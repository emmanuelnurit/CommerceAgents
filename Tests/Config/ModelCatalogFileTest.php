<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Config;

use CommerceAgents\Agent\Llm\LlmClientFactory;
use CommerceAgents\Service\ModelChoice;
use PHPUnit\Framework\TestCase;

class ModelCatalogFileTest extends TestCase
{
    /**
     * @return array{usd_to_eur: float, rated_at: string, providers: array<string, mixed>}
     */
    private static function catalog(): array
    {
        return require __DIR__.'/../../Config/models.php';
    }

    public function testConversionRateIsMaintained(): void
    {
        $catalog = self::catalog();

        $this->assertIsFloat($catalog['usd_to_eur']);
        $this->assertGreaterThan(0.0, $catalog['usd_to_eur']);
        $this->assertLessThan(2.0, $catalog['usd_to_eur'], 'a USD→EUR rate far from parity is a typo');
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $catalog['rated_at']);
    }

    public function testBundledCatalogIsConsistent(): void
    {
        $providers = self::catalog()['providers'];

        $this->assertSame(LlmClientFactory::PROVIDERS, array_keys($providers), 'one catalog section per provider');

        foreach ($providers as $provider => $section) {
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $section['priced_at'], $provider);
            $this->assertContains($section['currency'], ['USD', 'EUR'], $provider);
            $this->assertNotEmpty($section['models'], $provider);

            $ids = [];
            foreach ($section['models'] as $model) {
                $this->assertMatchesRegularExpression('/^[a-z0-9.\-]+$/', $model['id'], $provider);
                $this->assertNotContains($model['id'], $ids, 'duplicate '.$model['id']);
                $ids[] = $model['id'];
                $this->assertContains($model['tier'], ModelChoice::TIERS, $model['id']);
                $this->assertIsFloat($model['input'], $model['id']);
                $this->assertIsFloat($model['output'], $model['id']);
                $this->assertGreaterThanOrEqual(0.0, $model['input']);
                $this->assertGreaterThanOrEqual($model['input'], $model['output'], $model['id'].': output is never cheaper than input');
            }

            $this->assertContains(LlmClientFactory::DEFAULT_MODELS[$provider], $ids, 'default model of '.$provider.' must be in the catalog');
        }
    }

    public function testMistralSectionFollowsTheBoardDecision(): void
    {
        $section = self::catalog()['providers']['mistral'];

        $this->assertSame('EUR', $section['currency'], 'Mistral tariffs are the published EUR ones');

        $tiers = array_column($section['models'], 'tier', 'id');
        $this->assertSame('fast', $tiers['ministral-3b-latest']);
        $this->assertSame('fast', $tiers['ministral-8b-latest']);
        $this->assertSame('balanced', $tiers['mistral-small-latest']);
        $this->assertSame('balanced', $tiers['mistral-medium-latest']);
        $this->assertSame('balanced', $tiers['codestral-latest']);
        $this->assertSame('deep', $tiers['mistral-large-latest']);
        $this->assertSame('deep', $tiers['magistral-medium-latest'], 'the Mistral reasoning model belongs to the catalog');
    }

    public function testDefaultProviderAndModelAreMistral(): void
    {
        $this->assertSame('mistral', LlmClientFactory::DEFAULT_PROVIDER);
        $this->assertSame('ministral-3b-latest', LlmClientFactory::DEFAULT_MODELS[LlmClientFactory::DEFAULT_PROVIDER]);
    }
}

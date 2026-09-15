<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Service;

use CommerceAgents\Agent\Llm\LlmClientFactory;
use CommerceAgents\Agent\Llm\ModelDiscovery;
use CommerceAgents\Model\AgentModel;
use CommerceAgents\Service\ModelCatalog;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpFoundation\RequestStack;
use Thelia\Core\Translation\Translator;
use Thelia\Test\IntegrationTestCase;

/**
 * MYO-421: the wizard/edit picker was hard-limited to a single provider
 * because its only caller (AgentsController) never used the `null` (every
 * provider) entry point getSelectableModels() already supported. This
 * covers that entry point directly, plus pricesFor()'s USD/EUR conversion
 * for a non-Mistral provider (AC4).
 */
class ModelCatalogTest extends IntegrationTestCase
{
    private function catalog(): ModelCatalog
    {
        return new ModelCatalog(new ModelDiscovery(new MockHttpClient()), new Translator(new RequestStack()));
    }

    public function testGetSelectableModelsWithNoProviderReturnsEveryProvider(): void
    {
        $choices = $this->catalog()->getSelectableModels();

        $providers = array_unique(array_map(static fn ($c) => $c->provider, $choices));
        sort($providers);

        self::assertSame(LlmClientFactory::PROVIDERS, $providers, 'getSelectableModels(null) must span every provider, not just the configured one');
    }

    public function testGetSelectableModelsScopedToOneProviderOnlyReturnsThatProvider(): void
    {
        $choices = $this->catalog()->getSelectableModels('anthropic');

        self::assertNotEmpty($choices, 'test fixtures must seed at least one selectable anthropic model');
        foreach ($choices as $choice) {
            self::assertSame('anthropic', $choice->provider);
        }
    }

    /**
     * AC4: the internal USD accounting must stay correct for a USD-native
     * provider (no conversion) exactly like for Mistral's EUR one.
     */
    public function testPricesForAUsdNativeProviderPassesThroughUnconverted(): void
    {
        (new AgentModel())
            ->setProvider('anthropic')
            ->setModelId('myo-421-test-model')
            ->setName('MYO-421 test model')
            ->setPriceInput('2.000000')
            ->setPriceOutput('10.000000')
            ->setCurrency('USD')
            ->setEnabled(1)
            ->setSource(ModelCatalog::SOURCE_MANUAL)
            ->save($this->getPropelConnection());

        $prices = $this->catalog()->pricesFor('anthropic', 'myo-421-test-model');

        self::assertSame(2.0, $prices['input']);
        self::assertSame(10.0, $prices['output']);
    }

    public function testPricesForAEurNativeProviderConvertsToUsd(): void
    {
        (new AgentModel())
            ->setProvider('mistral')
            ->setModelId('myo-421-test-model')
            ->setName('MYO-421 test model')
            ->setPriceInput('0.090000')
            ->setPriceOutput('0.090000')
            ->setCurrency('EUR')
            ->setEnabled(1)
            ->setSource(ModelCatalog::SOURCE_MANUAL)
            ->save($this->getPropelConnection());

        $prices = $this->catalog()->pricesFor('mistral', 'myo-421-test-model');
        $expected = round(0.09 / ModelCatalog::usdToEurRate(), 6);

        self::assertSame($expected, $prices['input']);
        self::assertSame($expected, $prices['output']);
    }
}

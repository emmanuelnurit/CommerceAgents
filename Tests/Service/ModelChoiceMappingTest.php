<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Service;

use CommerceAgents\Agent\Llm\ModelDiscovery;
use CommerceAgents\Model\AgentModel;
use CommerceAgents\Service\ModelCatalog;
use CommerceAgents\Service\ModelChoice;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpFoundation\RequestStack;
use Thelia\Core\Translation\Translator;

/**
 * The row → ModelChoice mapping is the data contract of the back-office
 * agent model selector (plan MYO-226 §3.8).
 */
class ModelChoiceMappingTest extends TestCase
{
    private function catalog(): ModelCatalog
    {
        return new ModelCatalog(new ModelDiscovery(new MockHttpClient()), new Translator(new RequestStack()));
    }

    private static function row(): AgentModel
    {
        return (new AgentModel())
            ->setProvider('mistral')
            ->setModelId('ministral-3b-latest')
            ->setName('Ministral 3B')
            ->setPriceInput('0.090000')
            ->setPriceOutput('0.090000')
            ->setContextWindow(128000)
            ->setTier('fast')
            ->setCurrency('EUR');
    }

    public function testMapsACatalogRow(): void
    {
        $choice = $this->catalog()->choiceFromRow(self::row());

        $this->assertSame('ministral-3b-latest', $choice->modelId);
        $this->assertSame('Ministral 3B', $choice->name);
        $this->assertSame(ModelChoice::TIER_FAST, $choice->tier);
        $this->assertSame('Fast', $choice->tierLabel, 'untranslated source label outside a BO request');
        $this->assertSame('0.09', $choice->priceInput);
        $this->assertSame('0.09', $choice->priceOutput);
        $this->assertSame('EUR', $choice->currency);
        $this->assertSame(128000, $choice->contextWindow);
        $this->assertTrue($choice->isDefault, 'ministral-3b-latest is the module default');
        $this->assertSame('mistral', $choice->provider);
    }

    /**
     * MYO-421: the picker must be able to tell which provider a choice comes
     * from once several providers are listed side by side -- two providers
     * can each publish a different model under the very same id.
     */
    public function testMapsThePublishingProviderOfARow(): void
    {
        $choice = $this->catalog()->choiceFromRow(self::row()->setProvider('anthropic')->setModelId('claude-sonnet-5'));

        $this->assertSame('anthropic', $choice->provider);
    }

    public function testOnlyTheDefaultModelOfEachProviderIsFlagged(): void
    {
        $this->assertFalse($this->catalog()->choiceFromRow(self::row()->setModelId('mistral-large-latest'))->isDefault);
        $this->assertTrue($this->catalog()->choiceFromRow(
            self::row()->setProvider('anthropic')->setModelId('claude-sonnet-5')->setTier('balanced')->setCurrency('USD'),
        )->isDefault);
    }

    public function testRowsPredatingTheMigrationStaySelectable(): void
    {
        $choice = $this->catalog()->choiceFromRow(self::row()->setTier(null)->setCurrency(null));

        $this->assertSame(ModelChoice::TIER_BALANCED, $choice->tier, 'tier fallback for rows predating the column');
        $this->assertSame('USD', $choice->currency, 'rows priced before the currency column were USD');
    }

    public function testPriceFormattingTrimsStorageDecimals(): void
    {
        $this->assertSame('0.09', ModelCatalog::formatPrice('0.090000'));
        $this->assertSame('1.35', ModelCatalog::formatPrice('1.350000'));
        $this->assertSame('5.00', ModelCatalog::formatPrice('5.000000'));
        $this->assertSame('0.125', ModelCatalog::formatPrice('0.125000'));
    }

    public function testInternalAccountingConvertsEurPricesToUsd(): void
    {
        $rate = ModelCatalog::usdToEurRate();

        $this->assertSame(round(0.09 / $rate, 6), ModelCatalog::toUsd(0.09, 'EUR'));
        $this->assertSame(2.0, ModelCatalog::toUsd(2.0, 'USD'), 'USD prices pass through');
        $this->assertNull(ModelCatalog::toUsd(null, 'EUR'), 'unpriced stays unpriced');
    }
}

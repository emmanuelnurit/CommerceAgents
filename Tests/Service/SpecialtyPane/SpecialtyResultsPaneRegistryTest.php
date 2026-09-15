<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Service\SpecialtyPane;

use CommerceAgents\Agent\Tool\Capability;
use CommerceAgents\Service\AgentPresets;
use CommerceAgents\Service\SpecialtyPane\CartAbandonedResultsPane;
use CommerceAgents\Service\SpecialtyPane\CustomerReviewsReplyResultsPane;
use CommerceAgents\Service\SpecialtyPane\DailySalesSummaryResultsPane;
use CommerceAgents\Service\SpecialtyPane\GenericResultsPane;
use CommerceAgents\Service\SpecialtyPane\SpecialtyResultsPaneRegistry;
use CommerceAgents\Service\SpecialtyPane\StockWatchRestockResultsPane;
use CommerceAgents\Service\SpecialtyPane\WelcomeNewCustomerResultsPane;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The registry's resolution order (MYO-327/MYO-328 architecture decision):
 * preset_code exact match first, then an unambiguous capability signature,
 * then the generic pane as a guaranteed last resort -- never an exception.
 */
final class SpecialtyResultsPaneRegistryTest extends TestCase
{
    private function registry(): SpecialtyResultsPaneRegistry
    {
        return new SpecialtyResultsPaneRegistry(
            [
                new CartAbandonedResultsPane(),
                new WelcomeNewCustomerResultsPane(),
                new DailySalesSummaryResultsPane(),
                new StockWatchRestockResultsPane(),
                new CustomerReviewsReplyResultsPane(),
            ],
            new GenericResultsPane(),
        );
    }

    /**
     * @return iterable<string, array{string, class-string}>
     */
    public static function presetCodeProvider(): iterable
    {
        yield 'cart abandoned' => [AgentPresets::CART_ABANDONED, CartAbandonedResultsPane::class];
        yield 'welcome new customer' => [AgentPresets::WELCOME_NEW_CUSTOMER, WelcomeNewCustomerResultsPane::class];
        yield 'daily sales summary' => [AgentPresets::DAILY_SALES_SUMMARY, DailySalesSummaryResultsPane::class];
        yield 'stock watch restock' => [AgentPresets::STOCK_WATCH_RESTOCK, StockWatchRestockResultsPane::class];
        yield 'customer reviews reply' => [AgentPresets::CUSTOMER_REVIEWS_REPLY, CustomerReviewsReplyResultsPane::class];
    }

    #[DataProvider('presetCodeProvider')]
    public function testResolvesByExactPresetCode(string $presetCode, string $expectedClass): void
    {
        // Capabilities are irrelevant once preset_code matches exactly --
        // pass the ambiguous cart/welcome signature to prove it.
        $pane = $this->registry()->resolve($presetCode, [Capability::CATALOG_READ, Capability::CUSTOMER_READ]);

        $this->assertInstanceOf($expectedClass, $pane);
    }

    /**
     * @return iterable<string, array{list<string>, class-string}>
     */
    public static function unambiguousCapabilityProvider(): iterable
    {
        yield 'daily sales summary signature' => [[Capability::ANALYTICS_READ, Capability::ORDERS_READ], DailySalesSummaryResultsPane::class];
        yield 'stock watch signature' => [[Capability::INVENTORY_WRITE], StockWatchRestockResultsPane::class];
        yield 'customer reviews signature' => [[Capability::REVIEWS_READ, Capability::REVIEWS_WRITE], CustomerReviewsReplyResultsPane::class];
    }

    /**
     * @param list<string> $capabilities
     */
    #[DataProvider('unambiguousCapabilityProvider')]
    public function testFallsBackToUnambiguousCapabilitySignatureWhenPresetCodeIsUnset(array $capabilities, string $expectedClass): void
    {
        $pane = $this->registry()->resolve(null, $capabilities);

        $this->assertInstanceOf($expectedClass, $pane);
    }

    public function testFallsBackToGenericPaneForTheAmbiguousCartAbandonedAndWelcomeCapabilitySignature(): void
    {
        // cart_abandoned_relaunch and welcome_new_customer share the exact
        // same capability set: neither can be told apart without preset_code
        // (MYO-327 decision comment) -- guessing wrong would be worse than
        // the honest generic pane.
        $pane = $this->registry()->resolve(null, [Capability::CATALOG_READ, Capability::CUSTOMER_READ]);

        $this->assertInstanceOf(GenericResultsPane::class, $pane);
    }

    public function testFallsBackToGenericPaneForAnAgentWithoutCapabilities(): void
    {
        // from_scratch agents (or any agent with no capabilities granted).
        $pane = $this->registry()->resolve(null, []);

        $this->assertInstanceOf(GenericResultsPane::class, $pane);
    }

    public function testFallsBackToGenericPaneForAnUnrecognizedPresetCodeWithNoCapabilities(): void
    {
        $pane = $this->registry()->resolve(AgentPresets::FROM_SCRATCH, []);

        $this->assertInstanceOf(GenericResultsPane::class, $pane);
    }
}

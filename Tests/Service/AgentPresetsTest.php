<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Service;

use CommerceAgents\Agent\Tool\Capability;
use CommerceAgents\Service\AgentPresets;
use CommerceAgents\Service\TriggerCatalog;
use PHPUnit\Framework\TestCase;

class AgentPresetsTest extends TestCase
{
    public function testEveryPresetHasTheExpectedShapeAndValidReferences(): void
    {
        foreach (AgentPresets::all() as $code => $preset) {
            $this->assertSame($code, $preset['code']);
            $this->assertArrayHasKey('requiresModule', $preset);

            foreach ($preset['capabilities'] as $capability) {
                $this->assertContains($capability, Capability::ALL, \sprintf('Preset "%s" references unknown capability "%s"', $code, $capability));
            }

            foreach ($preset['triggers'] as $trigger) {
                $this->assertContains(
                    $trigger['type'],
                    [
                        TriggerCatalog::CART_ABANDONED,
                        TriggerCatalog::NEW_ORDER,
                        TriggerCatalog::ORDER_STATUS_CHANGE,
                        TriggerCatalog::NEW_CUSTOMER,
                        TriggerCatalog::LOW_STOCK,
                        TriggerCatalog::SCHEDULE,
                    ],
                    \sprintf('Preset "%s" references unknown trigger type "%s"', $code, $trigger['type']),
                );
            }
        }
    }

    public function testStockWatchRestockReusesExistingToolsOnly(): void
    {
        $preset = AgentPresets::find(AgentPresets::STOCK_WATCH_RESTOCK);

        $this->assertNotNull($preset);
        $this->assertNull($preset['requiresModule']);
        $this->assertSame(['catalog.read', 'inventory.write', 'analytics.read'], $preset['capabilities']);
        $this->assertSame(TriggerCatalog::LOW_STOCK, $preset['triggers'][0]['type']);
    }

    public function testCustomerReviewsReplyRequiresTheCommentModule(): void
    {
        $preset = AgentPresets::find(AgentPresets::CUSTOMER_REVIEWS_REPLY);

        $this->assertNotNull($preset);
        $this->assertSame('Comment', $preset['requiresModule']);
        $this->assertSame(['reviews.read', 'reviews.write'], $preset['capabilities']);
    }

    public function testUnknownPresetCodeReturnsNull(): void
    {
        $this->assertNull(AgentPresets::find('does-not-exist'));
    }
}

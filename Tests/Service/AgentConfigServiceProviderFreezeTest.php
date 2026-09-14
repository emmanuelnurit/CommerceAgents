<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Service;

use CommerceAgents\Service\AgentConfigService;
use PHPUnit\Framework\TestCase;

/**
 * Decision table of the 0.3.0 migration: when the module default switched
 * from Anthropic to Mistral, installations living on the implicit Anthropic
 * default must keep their behaviour.
 */
class AgentConfigServiceProviderFreezeTest extends TestCase
{
    public function testExplicitProviderIsNeverTouched(): void
    {
        $this->assertNull(AgentConfigService::providerToFreeze('anthropic', true));
        $this->assertNull(AgentConfigService::providerToFreeze('mistral', false));
        $this->assertNull(AgentConfigService::providerToFreeze('openai-compatible', true));
    }

    public function testImplicitAnthropicInstallationIsFrozenOnAnthropic(): void
    {
        $this->assertSame('anthropic', AgentConfigService::providerToFreeze('', true));
    }

    public function testVirginInstallationFallsThroughToTheMistralDefault(): void
    {
        $this->assertNull(AgentConfigService::providerToFreeze('', false));
    }
}

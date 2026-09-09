<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Tool\Shopping;

use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Tool\Shopping\Gateway\PolicyGatewayInterface;
use CommerceAgents\Tool\Shopping\GetPoliciesTool;
use PHPUnit\Framework\TestCase;

class FakePolicyGateway implements PolicyGatewayInterface
{
    public ?string $lastLocale = null;

    public function __construct(private readonly array $policies = [])
    {
    }

    public function getPolicies(string $locale): array
    {
        $this->lastLocale = $locale;

        return $this->policies;
    }
}

class GetPoliciesToolTest extends TestCase
{
    public function testDelegatesWithContextLocale(): void
    {
        $gateway = new FakePolicyGateway([['title' => 'CGV', 'text' => 'Conditions...']]);
        $tool = new GetPoliciesTool($gateway);

        $result = $tool->execute([], new ToolContext(locale: 'en_US'));

        $this->assertSame('en_US', $gateway->lastLocale);
        $this->assertSame('CGV', $result['policies'][0]['title']);
    }

    public function testEmptyConfigurationReturnsError(): void
    {
        $result = (new GetPoliciesTool(new FakePolicyGateway()))->execute([], new ToolContext());

        $this->assertSame('No policy content configured', $result['error']);
    }

    public function testAllowedForFrontOnly(): void
    {
        $tool = new GetPoliciesTool(new FakePolicyGateway());

        $this->assertTrue($tool->isAllowed(new ToolContext()));
        $this->assertFalse($tool->isAllowed(new ToolContext(isAdmin: true)));
    }
}

<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Service;

use CommerceAgents\Agent\Proactive\ProactiveMessage;
use CommerceAgents\Agent\Proactive\ProactiveScenarioResolverInterface;
use CommerceAgents\Agent\Proactive\ProactiveSignal;
use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Service\ProactiveScenarioRegistry;
use PHPUnit\Framework\TestCase;

class ProactiveScenarioRegistryTest extends TestCase
{
    public function testNoRegisteredResolverMeansNoMessage(): void
    {
        $registry = new ProactiveScenarioRegistry([]);

        $result = $registry->resolve(new ProactiveSignal('cart_idle'), new ToolContext());

        $this->assertNull($result);
    }

    public function testFirstResolverToReturnAMessageWins(): void
    {
        $silent = $this->stubResolver(null);
        $vocal = $this->stubResolver(new ProactiveMessage('Besoin d\'aide pour finaliser votre panier ?'));
        $neverReached = $this->stubResolver(new ProactiveMessage('never'));

        $registry = new ProactiveScenarioRegistry([$silent, $vocal, $neverReached]);

        $result = $registry->resolve(new ProactiveSignal('cart_idle'), new ToolContext());

        $this->assertSame('Besoin d\'aide pour finaliser votre panier ?', $result?->message);
    }

    private function stubResolver(?ProactiveMessage $message): ProactiveScenarioResolverInterface
    {
        return new class($message) implements ProactiveScenarioResolverInterface {
            public function __construct(private readonly ?ProactiveMessage $message)
            {
            }

            public function resolve(ProactiveSignal $signal, ToolContext $context): ?ProactiveMessage
            {
                return $this->message;
            }
        };
    }
}

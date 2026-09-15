<?php

declare(strict_types=1);

namespace CommerceAgents\Service;

use CommerceAgents\Agent\Proactive\ProactiveMessage;
use CommerceAgents\Agent\Proactive\ProactiveScenarioResolverInterface;
use CommerceAgents\Agent\Proactive\ProactiveSignal;
use CommerceAgents\Agent\Tool\ToolContext;
use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;

/**
 * Dispatches an eligible signal to the registered scenario resolvers.
 * MYO-246 ships this registry empty; MYO-236 lots 2-4 register resolvers
 * that are picked up automatically (see CommerceAgents::configureServices).
 */
final class ProactiveScenarioRegistry
{
    /** @var list<ProactiveScenarioResolverInterface> */
    private readonly array $resolvers;

    /**
     * @param iterable<ProactiveScenarioResolverInterface> $resolvers
     */
    public function __construct(
        #[TaggedIterator('commerce_agents.proactive_scenario_resolver')] iterable $resolvers = [],
    ) {
        $this->resolvers = $resolvers instanceof \Traversable ? iterator_to_array($resolvers, false) : $resolvers;
    }

    public function resolve(ProactiveSignal $signal, ToolContext $context): ?ProactiveMessage
    {
        foreach ($this->resolvers as $resolver) {
            $message = $resolver->resolve($signal, $context);
            if ($message !== null) {
                return $message;
            }
        }

        return null;
    }
}

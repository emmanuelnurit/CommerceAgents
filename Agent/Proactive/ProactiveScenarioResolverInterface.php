<?php

declare(strict_types=1);

namespace CommerceAgents\Agent\Proactive;

use CommerceAgents\Agent\Tool\ToolContext;

/**
 * Extension point for MYO-236 lots 2-4: each proactive scenario (abandoned
 * cart, low stock, ...) is a resolver fetching real data and deciding
 * whether the signal warrants a message. No implementation ships with this
 * lot (MYO-246) — ProactiveScenarioRegistry runs on an empty list until one
 * is registered.
 */
interface ProactiveScenarioResolverInterface
{
    /**
     * @return ProactiveMessage|null null when this resolver does not handle
     *                               the signal, or the data does not
     *                               warrant a solicitation
     */
    public function resolve(ProactiveSignal $signal, ToolContext $context): ?ProactiveMessage;
}

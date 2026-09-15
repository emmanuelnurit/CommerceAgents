<?php

declare(strict_types=1);

namespace CommerceAgents\Service\SpecialtyPane;

use CommerceAgents\Model\AgentDefinition;
use CommerceAgents\Model\AgentOutboundMessage;
use CommerceAgents\Model\AgentOutboundMessageQuery;
use Propel\Runtime\ActiveQuery\Criteria;

/**
 * Guaranteed last-resort pane (MYO-327/MYO-328): always supports(), so the
 * registry never fails to resolve. Shows the agent's own
 * agent_outbound_message trail (sent_at/channel/recipient/status) when it
 * has one -- true for the 3 channel-sending specialties even without a
 * recognized preset_code -- otherwise a plain empty state.
 */
final readonly class GenericResultsPane implements SpecialtyResultsPaneInterface
{
    private const MAX_MESSAGES = 50;

    public function supports(?string $presetCode, array $capabilities): bool
    {
        return true;
    }

    public function getTemplate(): string
    {
        return '@CommerceAgentsModule/backOffice/default-twig/agents/results/_generic.html.twig';
    }

    public function getViewData(AgentDefinition $definition): array
    {
        $messages = AgentOutboundMessageQuery::create()
            ->filterByAgentDefinitionId($definition->getId())
            ->orderBySentAt(Criteria::DESC)
            ->limit(self::MAX_MESSAGES)
            ->find();

        return [
            'messages' => array_map(static fn (AgentOutboundMessage $message): array => [
                'sentAt' => $message->getSentAt(),
                'channel' => $message->getChannel(),
                'recipient' => $message->getRecipient(),
                'status' => $message->getStatus(),
            ], $messages->getData()),
        ];
    }
}

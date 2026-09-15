<?php

declare(strict_types=1);

namespace CommerceAgents\Service\SpecialtyPane;

use CommerceAgents\Model\AgentDefinition;
use CommerceAgents\Model\AgentOutboundMessage;
use CommerceAgents\Model\AgentOutboundMessageQuery;
use CommerceAgents\Service\AgentPresets;
use Propel\Runtime\ActiveQuery\Criteria;

/**
 * Real "Results" tab for the cart-abandoned specialty (MYO-340, lot 3 of
 * MYO-326/MYO-337): the "Messages envoyés" feed built from
 * agent_outbound_message rows for this agent, newest first. Same base query
 * as GenericResultsPane (which it does not touch -- see that class doc), but
 * additionally exposes bodyExcerpt and error, needed here and not there.
 * Only recognized by an exact preset_code match: its capability set is
 * identical to welcome_new_customer's, see the MYO-327 decision comment.
 */
final readonly class CartAbandonedResultsPane implements SpecificSpecialtyResultsPaneInterface
{
    private const MAX_MESSAGES = 50;

    public function supports(?string $presetCode, array $capabilities): bool
    {
        return $presetCode === AgentPresets::CART_ABANDONED;
    }

    public function getTemplate(): string
    {
        return '@CommerceAgentsModule/backOffice/default-twig/agents/results/_customer_email_messages.html.twig';
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
                'bodyExcerpt' => $message->getBodyExcerpt(),
                'error' => $message->getError(),
            ], $messages->getData()),
        ];
    }
}

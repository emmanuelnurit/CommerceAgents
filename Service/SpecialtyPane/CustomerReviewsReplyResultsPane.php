<?php

declare(strict_types=1);

namespace CommerceAgents\Service\SpecialtyPane;

use CommerceAgents\Agent\Tool\Capability;
use CommerceAgents\Model\AgentDefinition;
use CommerceAgents\Service\AgentPresets;

/**
 * Stub for the customer-reviews-reply specialty (MYO-328 lot 0). Recognized
 * by preset_code, or by reviews.read + reviews.write when preset_code is
 * unset -- unique among the 5 presets, see the MYO-327 decision comment.
 */
final readonly class CustomerReviewsReplyResultsPane implements SpecificSpecialtyResultsPaneInterface
{
    public function supports(?string $presetCode, array $capabilities): bool
    {
        return $presetCode === AgentPresets::CUSTOMER_REVIEWS_REPLY
            || (\in_array(Capability::REVIEWS_READ, $capabilities, true) && \in_array(Capability::REVIEWS_WRITE, $capabilities, true));
    }

    public function getTemplate(): string
    {
        return '@CommerceAgentsModule/backOffice/default-twig/agents/results/_specialty_stub.html.twig';
    }

    public function getViewData(AgentDefinition $definition): array
    {
        return ['specialtyTitle' => AgentPresets::find(AgentPresets::CUSTOMER_REVIEWS_REPLY)['title']];
    }
}

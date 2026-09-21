<?php

declare(strict_types=1);

namespace CommerceAgents\Tool\Shopping;

use CommerceAgents\Agent\Tool\Capability;
use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Agent\Tool\ToolInterface;
use CommerceAgents\Service\NewsletterOptinService;

/**
 * MYO-471/MYO-475: subscribes the visitor's own e-mail to the newsletter.
 * Never returns a coupon code (NewsletterOptinService sends it by e-mail
 * only) — nothing this tool returns may ever be repeated by the LLM in the
 * conversation.
 */
final readonly class SubscribeToNewsletterTool implements ToolInterface
{
    public function __construct(
        private NewsletterOptinService $newsletterOptinService,
    ) {
    }

    public function getName(): string
    {
        return 'subscribe_to_newsletter';
    }

    public function getDescription(): string
    {
        return 'Subscribe the visitor to the store newsletter, using the e-mail address they gave you in this '
            .'conversation and their explicit yes/no consent. Never call this with a guessed e-mail or with '
            .'consent assumed true — always ask for both first, exactly as the visitor stated them.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'email' => ['type' => 'string', 'format' => 'email', 'description' => 'E-mail address the visitor gave you'],
                'consent' => ['type' => 'boolean', 'description' => 'True only if the visitor explicitly agreed to be subscribed'],
            ],
            'required' => ['email', 'consent'],
        ];
    }

    public function getRequiredCapability(): string
    {
        return Capability::MARKETING_WRITE;
    }

    public function isAllowed(ToolContext $ctx): bool
    {
        return !$ctx->isAdmin;
    }

    public function execute(array $args, ToolContext $ctx): array
    {
        // Revalidated here regardless of the LLM's arguments: the schema
        // shape is not a security boundary (MYO-475 AC1).
        $email = \is_string($args['email'] ?? null) ? $args['email'] : '';
        $consent = ($args['consent'] ?? null) === true;

        return $this->newsletterOptinService->subscribe($email, $consent, $ctx);
    }
}

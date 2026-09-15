<?php

declare(strict_types=1);

namespace CommerceAgents\Service;

use CommerceAgents\Agent\Tool\Capability;
use CommerceAgents\Model\AgentCapability;
use CommerceAgents\Model\AgentDefinition;
use CommerceAgents\Model\AgentDefinitionQuery;

/**
 * Seeds the two historical assistants as protected agent definitions (plan
 * MYO-226 §3.7). The chat controllers keep their historical wiring: these
 * rows expose the assistants in the coming agent list UI and reserve their
 * codes; they are created once and never overwrite merchant edits.
 */
final readonly class AgentDefinitionSeeder
{
    public const SHOPPING_CODE = 'shopping_assistant';
    public const MERCHANT_CODE = 'merchant_assistant';

    /** Codes the back-office must never allow to delete. */
    public const PROTECTED_CODES = [self::SHOPPING_CODE, self::MERCHANT_CODE];

    public function __construct(
        private AgentConfigService $configService,
    ) {
    }

    public function seed(): void
    {
        $this->seedDefinition(
            self::SHOPPING_CODE,
            $this->configService->getAssistantName(),
            'Assistant d\'achat historique du chat visiteurs (vitrine). Ses réglages restent pilotés par la configuration du module.',
            $this->configService->isFrontChatEnabled(),
            $this->shoppingCapabilities(),
        );

        $this->seedDefinition(
            self::MERCHANT_CODE,
            'Assistant marchand',
            'Assistant marchand historique du back-office. Ses réglages restent pilotés par la configuration du module.',
            true,
            [
                Capability::CATALOG_READ,
                Capability::ANALYTICS_READ,
                Capability::ORDERS_READ,
                Capability::CONTENT_READ,
                Capability::PRICING_WRITE,
                Capability::INVENTORY_WRITE,
            ],
        );
    }

    /**
     * The historical shopping feature flags become granted capabilities.
     *
     * @return list<string>
     */
    private function shoppingCapabilities(): array
    {
        $capabilities = [Capability::CATALOG_READ, Capability::CONTENT_READ, Capability::CUSTOMER_READ];

        if ($this->configService->isCartEnabled()) {
            $capabilities[] = Capability::CART_WRITE;
        }
        if ($this->configService->isCheckoutEnabled()) {
            $capabilities[] = Capability::CHECKOUT_WRITE;
        }
        if ($this->configService->areOrdersEnabled()) {
            $capabilities[] = Capability::ORDERS_READ;
        }

        return $capabilities;
    }

    /**
     * @param list<string> $capabilities
     */
    private function seedDefinition(string $code, string $title, string $description, bool $enabled, array $capabilities): void
    {
        if (AgentDefinitionQuery::create()->filterByCode($code)->exists()) {
            return;
        }

        // No setLocale() here: the "Agents IA" UI exposes no per-agent locale
        // field, so AgentRunner always derives the run's language from the
        // site's default (AssistantLocaleResolver::forAgentRun(), MYO-274) and
        // ignores this column's value, whatever it ends up being.
        $definition = (new AgentDefinition())
            ->setCode($code)
            ->setTitle($title)
            ->setDescription($description)
            ->setEnabled($enabled ? 1 : 0)
            ->setRolePrompt('');
        $definition->save();

        foreach ($capabilities as $capability) {
            (new AgentCapability())
                ->setAgentDefinitionId($definition->getId())
                ->setCapability($capability)
                ->save();
        }
    }
}

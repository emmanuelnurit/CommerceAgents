<?php

declare(strict_types=1);

namespace CommerceAgents\Tool\Shopping;

use CommerceAgents\Agent\Tool\Capability;
use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Agent\Tool\ToolInterface;
use CommerceAgents\Tool\Shopping\Gateway\CartGatewayInterface;
use CommerceAgents\Tool\Shopping\Gateway\CheckoutUrlProviderInterface;

final readonly class PrepareCheckoutTool implements ToolInterface
{
    public function __construct(
        private CartGatewayInterface $cartGateway,
        private CheckoutUrlProviderInterface $checkoutUrlProvider,
        private bool $checkoutEnabled = true,
    ) {
    }

    public function getName(): string
    {
        return 'prepare_checkout';
    }

    public function getDescription(): string
    {
        return 'Summarize the current cart and give the customer the link to the checkout funnel. '
            .'Never triggers any payment: the customer completes the order in the checkout pages.';
    }

    public function getInputSchema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass(), 'required' => []];
    }

    public function getRequiredCapability(): string
    {
        return Capability::CHECKOUT_WRITE;
    }

    public function isAllowed(ToolContext $ctx): bool
    {
        return !$ctx->isAdmin && $this->checkoutEnabled;
    }

    public function execute(array $args, ToolContext $ctx): array
    {
        $cart = $this->cartGateway->getCart($ctx);

        if (($cart['itemCount'] ?? 0) === 0) {
            return ['error' => 'Cart is empty'];
        }

        return [
            'summary' => $cart,
            'checkout_url' => $this->checkoutUrlProvider->getCheckoutUrl(),
            'message' => 'Direct the customer to the checkout URL to complete the order.',
        ];
    }
}

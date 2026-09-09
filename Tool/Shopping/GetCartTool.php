<?php

declare(strict_types=1);

namespace CommerceAgents\Tool\Shopping;

use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Agent\Tool\ToolInterface;
use CommerceAgents\Tool\Shopping\Gateway\CartGatewayInterface;

final readonly class GetCartTool implements ToolInterface
{
    public function __construct(
        private CartGatewayInterface $cartGateway,
    ) {
    }

    public function getName(): string
    {
        return 'get_cart';
    }

    public function getDescription(): string
    {
        return 'Get the current customer cart: items, quantities and taxed totals in the customer currency.';
    }

    public function getInputSchema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass(), 'required' => []];
    }

    public function isAllowed(ToolContext $ctx): bool
    {
        return !$ctx->isAdmin;
    }

    public function execute(array $args, ToolContext $ctx): array
    {
        return ['cart' => $this->cartGateway->getCart($ctx)];
    }
}

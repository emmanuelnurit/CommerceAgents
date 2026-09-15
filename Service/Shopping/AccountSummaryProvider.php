<?php

declare(strict_types=1);

namespace CommerceAgents\Service\Shopping;

use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Tool\Shopping\Gateway\OrderGatewayInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Account call-to-action payload for the front chat widget: anonymous
 * visitors only ever get sign-in/sign-up links (forAnonymous() never touches
 * the order gateway), logged-in customers get their account link plus a
 * single-query snapshot of their most recent orders.
 */
final readonly class AccountSummaryProvider
{
    private const RECENT_ORDERS_LIMIT = 3;

    public function __construct(
        private OrderGatewayInterface $orderGateway,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function forAnonymous(): array
    {
        return [
            'loggedIn' => false,
            'loginUrl' => $this->urlGenerator->generate('customer_login'),
            'registerUrl' => $this->urlGenerator->generate('customer_register'),
        ];
    }

    public function forCustomer(int $customerId, string $locale): array
    {
        $ctx = new ToolContext(customerId: $customerId, locale: $locale);

        return [
            'loggedIn' => true,
            'accountUrl' => $this->urlGenerator->generate('account_index'),
            'ordersUrl' => $this->urlGenerator->generate('account_orders'),
            'orders' => $this->orderGateway->getOrders($customerId, self::RECENT_ORDERS_LIMIT, $ctx),
        ];
    }
}

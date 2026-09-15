<?php

declare(strict_types=1);

namespace CommerceAgents\Service\Shopping;

use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Tool\Shopping\Gateway\OrderGatewayInterface;
use Propel\Runtime\ActiveQuery\Criteria;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Thelia\Model\OrderQuery;

final readonly class TheliaOrderGateway implements OrderGatewayInterface
{
    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function getOrders(int $customerId, int $limit, ToolContext $ctx): array
    {
        $orders = OrderQuery::create()
            ->filterByCustomerId($customerId)
            ->orderByCreatedAt(Criteria::DESC)
            ->limit($limit)
            ->find();

        $result = [];
        foreach ($orders as $order) {
            $tax = 0.0;
            $result[] = [
                'ref' => $order->getRef(),
                'date' => $order->getCreatedAt()?->format('Y-m-d'),
                'status' => $order->getOrderStatus()->setLocale($ctx->locale)->getTitle(),
                'statusCode' => $order->getOrderStatus()->getCode(),
                'totalAmount' => round($order->getTotalAmount($tax), 2),
                'currency' => $order->getCurrency()->getCode(),
                'url' => $this->urlGenerator->generate(
                    'account_order',
                    ['orderId' => $order->getId()],
                    UrlGeneratorInterface::ABSOLUTE_URL,
                ),
            ];
        }

        return $result;
    }
}

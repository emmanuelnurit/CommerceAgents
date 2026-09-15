<?php

declare(strict_types=1);

namespace CommerceAgents\Service\Run;

use Propel\Runtime\ActiveQuery\Criteria;
use Thelia\Model\Cart;
use Thelia\Model\CartQuery;
use Thelia\Model\OrderQuery;

/**
 * Carts old enough to count as abandoned (plan MYO-226 §3.3 point 4: "paniers
 * datés"), for the `abandoned_cart` cron trigger. A cart already converted to
 * an order is never abandoned, even if nobody touched it since.
 */
final class AbandonedCartFinder
{
    public const DEFAULT_DELAY_HOURS = 24;

    /**
     * @return Cart[]
     */
    public function find(int $delayHours, \DateTimeImmutable $now): array
    {
        $cutoff = \DateTime::createFromImmutable($now->modify(\sprintf('-%d hours', max(0, $delayHours))));

        $candidates = CartQuery::create()
            ->filterByUpdatedAt($cutoff, Criteria::LESS_EQUAL)
            ->useCartItemExistsQuery()->endUse()
            ->find();

        if ($candidates->isEmpty()) {
            return [];
        }

        $candidateIds = [];
        foreach ($candidates as $cart) {
            $candidateIds[] = $cart->getId();
        }

        $orderedCartIds = [];
        foreach (OrderQuery::create()->filterByCartId($candidateIds, Criteria::IN)->find() as $order) {
            $orderedCartIds[$order->getCartId()] = true;
        }

        return array_values(array_filter(
            iterator_to_array($candidates),
            static fn (Cart $cart): bool => !isset($orderedCartIds[$cart->getId()]),
        ));
    }
}

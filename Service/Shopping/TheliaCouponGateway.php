<?php

declare(strict_types=1);

namespace CommerceAgents\Service\Shopping;

use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Tool\Shopping\Gateway\CouponGatewayInterface;
use Propel\Runtime\ActiveQuery\Criteria;
use Thelia\Condition\Exception\UnmatchableConditionException;
use Thelia\Condition\Implementation\MatchForTotalAmount;
use Thelia\Domain\Promotion\Coupon\CouponFactory;
use Thelia\Domain\Promotion\Coupon\Type\CouponInterface;
use Thelia\Model\Coupon as CouponModel;
use Thelia\Model\CouponQuery;
use Thelia\Model\CurrencyQuery;
use Thelia\Model\Map\CouponTableMap;

final readonly class TheliaCouponGateway implements CouponGatewayInterface
{
    public function __construct(
        private CouponFactory $couponFactory,
    ) {
    }

    public function findApplicableCoupons(ToolContext $ctx): array
    {
        $coupons = [];

        foreach ($this->usableCoupons($ctx) as [$model, $coupon]) {
            if (!$this->isMatching($coupon)) {
                continue;
            }

            $coupons[] = $this->describe($model, $ctx);
        }

        return $coupons;
    }

    public function findAmountTierLadder(ToolContext $ctx): array
    {
        $tiers = [];

        foreach ($this->usableCoupons($ctx) as [$model, $coupon]) {
            $threshold = $this->amountThreshold($coupon);
            if ($threshold === null) {
                continue;
            }

            $tiers[] = [
                ...$this->describe($model, $ctx),
                'threshold' => $threshold,
                'matching' => $this->isMatching($coupon),
            ];
        }

        usort($tiers, static fn (array $a, array $b) => $a['threshold'] <=> $b['threshold']);

        return $tiers;
    }

    /**
     * Enabled, code-triggered, started/not-expired coupons the customer
     * still has usage left for — the same eligibility base whether the
     * caller wants the "matching now" list or the amount-tier ladder.
     *
     * @return list<array{0: CouponModel, 1: CouponInterface}>
     */
    private function usableCoupons(ToolContext $ctx): array
    {
        $now = new \DateTime();

        $models = CouponQuery::create()
            ->filterByIsEnabled(true)
            ->filterByTriggerMode(CouponModel::TRIGGER_MODE_CODE)
            ->filterByExpirationDate($now, Criteria::GREATER_EQUAL)
            ->condition('start_unset', CouponTableMap::COL_START_DATE.' IS NULL')
            ->condition('start_reached', CouponTableMap::COL_START_DATE.' <= ?', $now)
            ->where(['start_unset', 'start_reached'], Criteria::LOGICAL_OR)
            ->find();

        $result = [];

        /** @var CouponModel $model */
        foreach ($models as $model) {
            $code = $model->getCode();
            if ($code === null || $code === '') {
                continue;
            }

            if (!$model->isUsageUnlimited()) {
                if ($model->getPerCustomerUsageCount() && $ctx->customerId === null) {
                    // A per-customer cap cannot be checked for an anonymous visitor.
                    continue;
                }
                if ($model->getUsagesLeft($ctx->customerId) <= 0) {
                    continue;
                }
            }

            try {
                $coupon = $this->couponFactory->buildCouponFromModel($model);
            } catch (\Exception) {
                continue;
            }

            if ($coupon->getConditions()->count() === 0) {
                continue;
            }

            $result[] = [$model, $coupon];
        }

        return $result;
    }

    private function isMatching(CouponInterface $coupon): bool
    {
        try {
            return $coupon->isMatching();
        } catch (UnmatchableConditionException) {
            return false;
        }
    }

    private function amountThreshold(CouponInterface $coupon): ?float
    {
        foreach ($coupon->getConditions() as $condition) {
            $serializable = $condition->getSerializableCondition();
            if ($serializable->conditionServiceId === 'thelia.condition.match_for_total_amount') {
                return (float) ($serializable->values[MatchForTotalAmount::CART_TOTAL] ?? 0);
            }
        }

        return null;
    }

    private function describe(CouponModel $model, ToolContext $ctx): array
    {
        return [
            'code' => (string) $model->getCode(),
            'title' => (string) $model->getTitle(),
            'shortDescription' => (string) $model->getShortDescription(),
            'discountLabel' => $this->discountLabel($model, $ctx),
        ];
    }

    private function discountLabel(CouponModel $model, ToolContext $ctx): string
    {
        $effects = $model->getEffects();

        return match ($model->getType()) {
            'thelia.coupon.type.remove_x_percent' => \sprintf('-%s%%', self::trimAmount((float) ($effects['percentage'] ?? 0))),
            'thelia.coupon.type.remove_x_amount' => \sprintf('-%s%s', self::trimAmount((float) ($effects['amount'] ?? 0)), $this->currencySymbol($ctx)),
            default => (string) $model->getTitle(),
        };
    }

    private function currencySymbol(ToolContext $ctx): string
    {
        return CurrencyQuery::create()->findOneByCode($ctx->currencyCode)?->getSymbol() ?? $ctx->currencyCode;
    }

    private static function trimAmount(float $amount): string
    {
        return rtrim(rtrim(number_format($amount, 2, '.', ''), '0'), '.');
    }
}

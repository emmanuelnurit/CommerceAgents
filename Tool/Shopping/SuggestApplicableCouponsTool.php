<?php

declare(strict_types=1);

namespace CommerceAgents\Tool\Shopping;

use CommerceAgents\Agent\Tool\Capability;
use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Agent\Tool\ToolInterface;
use CommerceAgents\Tool\Shopping\Gateway\CouponGatewayInterface;

/**
 * The only source of promo codes the LLM is allowed to mention. Every entry
 * is a real, enabled Thelia coupon that already matches the current cart
 * (CouponAbstract::isMatching()) and still has usage left for this customer
 * (Coupon::getUsagesLeft()) — the model must never invent or guess a code
 * that is not in this list (MYO-236/MYO-247 guardrail).
 */
final readonly class SuggestApplicableCouponsTool implements ToolInterface
{
    public function __construct(
        private CouponGatewayInterface $couponGateway,
    ) {
    }

    public function getName(): string
    {
        return 'suggest_applicable_coupons';
    }

    public function getDescription(): string
    {
        return 'List the promo codes that are currently valid, active and usable by this customer for the '
            .'cart in its current state (minimum amount, item count, country... already checked). Only '
            .'mention a code that appears in this list, exactly as written; never invent or guess one.';
    }

    public function getInputSchema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass(), 'required' => []];
    }

    public function getRequiredCapability(): string
    {
        return Capability::CART_WRITE;
    }

    public function isAllowed(ToolContext $ctx): bool
    {
        return !$ctx->isAdmin;
    }

    public function execute(array $args, ToolContext $ctx): array
    {
        return ['coupons' => $this->couponGateway->findApplicableCoupons($ctx)];
    }
}

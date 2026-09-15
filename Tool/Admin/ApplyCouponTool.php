<?php

declare(strict_types=1);

namespace CommerceAgents\Tool\Admin;

use CommerceAgents\Agent\Tool\Capability;
use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Agent\Tool\ToolInterface;
use CommerceAgents\Tool\Admin\Gateway\StagingGatewayInterface;

/**
 * Applies an existing coupon code to an existing order (MYO-286 item 3): a
 * customer-service gesture, never a coupon creation. Coupon creation (code +
 * conditions + effects) is not possible through this module today — out of
 * scope, see docs/commerceagents/capacites-api.md §1.1.
 */
final readonly class ApplyCouponTool implements ToolInterface
{
    public function __construct(
        private StagingGatewayInterface $stagingGateway,
    ) {
    }

    public function getName(): string
    {
        return 'apply_coupon_to_order';
    }

    public function getDescription(): string
    {
        return 'Creates a PENDING proposal to apply an existing coupon code to an existing order. '
            .'Nothing is modified until a human administrator approves it in the approval console. '
            .'The coupon must already exist and be enabled; this tool never creates a new coupon.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'order_id' => ['type' => 'integer', 'description' => 'Order id, e.g. from get_customer_orders'],
                'coupon_code' => ['type' => 'string', 'description' => 'Code of an existing, enabled coupon'],
            ],
            'required' => ['order_id', 'coupon_code'],
        ];
    }

    public function getRequiredCapability(): string
    {
        return Capability::ORDERS_WRITE;
    }

    public function isAllowed(ToolContext $ctx): bool
    {
        return $ctx->isAdmin && $ctx->adminId !== null;
    }

    public function execute(array $args, ToolContext $ctx): array
    {
        $orderId = (int) $args['order_id'];
        if ($orderId <= 0) {
            return ['error' => 'order_id must be a positive integer'];
        }

        $couponCode = trim((string) $args['coupon_code']);
        if ($couponCode === '') {
            return ['error' => 'coupon_code must not be empty'];
        }

        $staged = $this->stagingGateway->stageCouponApplication($orderId, $couponCode, $ctx);

        if (isset($staged['error'])) {
            return $staged;
        }

        return [
            'staged_change' => $staged,
            'message' => 'Coupon application proposal recorded. It requires human approval in the approval console before anything is applied.',
        ];
    }
}

<?php

declare(strict_types=1);

namespace CommerceAgents\Tool\Admin;

use CommerceAgents\Agent\Tool\Capability;
use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Agent\Tool\ToolInterface;
use CommerceAgents\Tool\Admin\Gateway\CampaignGatewayInterface;
use CommerceAgents\Tool\Admin\Gateway\SalesVelocityGatewayInterface;
use CommerceAgents\Tool\Admin\Gateway\StagingGatewayInterface;

/**
 * Turns a low-stock signal into a costed restock proposal (MYO-473, plan
 * MYO-467 cases B1/B2): current stock and observed sales velocity
 * (get_sales_velocity) decide how many units to reorder and when stock runs
 * out, and an active-campaign check (get_campaigns) raises the urgency.
 * Nothing is written directly -- the new stock level is always a PENDING
 * StagedChange (same 'pse_stock' target as update_stock), approved by a
 * human in the approval console.
 */
final readonly class ProposeRestockTool implements ToolInterface
{
    private const DEFAULT_VELOCITY_WINDOW_WEEKS = 4;
    private const MAX_VELOCITY_WINDOW_WEEKS = 12;
    private const DEFAULT_COVERAGE_WEEKS = 6;

    public function __construct(
        private SalesVelocityGatewayInterface $salesVelocityGateway,
        private CampaignGatewayInterface $campaignGateway,
        private StagingGatewayInterface $stagingGateway,
    ) {
    }

    public function getName(): string
    {
        return 'propose_restock';
    }

    public function getDescription(): string
    {
        return 'Computes a costed restock proposal for one product variant: remaining stock, observed sales '
            .'velocity, estimated stockout date and proposed reorder quantity, flagging whether the product is '
            .'under an active promotional campaign. Stages the new stock quantity as a PENDING change; nothing '
            .'is modified until a human administrator approves it in the approval console. '
            .'Use pse_id from get_inventory; call get_sales_velocity first if you need the raw figures.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'pse_id' => ['type' => 'integer', 'description' => 'Variant id as returned by get_inventory'],
                'velocity_window_weeks' => ['type' => 'integer', 'description' => 'Sliding window used to measure velocity (default 4, max 12)'],
                'coverage_weeks' => ['type' => 'integer', 'description' => 'Weeks of demand the proposed reorder should cover (default 6)'],
            ],
            'required' => ['pse_id'],
        ];
    }

    public function getRequiredCapability(): string
    {
        return Capability::INVENTORY_WRITE;
    }

    public function isAllowed(ToolContext $ctx): bool
    {
        return $ctx->isAdmin && $ctx->adminId !== null;
    }

    public function execute(array $args, ToolContext $ctx): array
    {
        $velocityWeeks = min(max(1, (int) ($args['velocity_window_weeks'] ?? self::DEFAULT_VELOCITY_WINDOW_WEEKS)), self::MAX_VELOCITY_WINDOW_WEEKS);
        $coverageWeeks = max(1, (int) ($args['coverage_weeks'] ?? self::DEFAULT_COVERAGE_WEEKS));

        $velocity = $this->salesVelocityGateway->getSalesVelocity((int) $args['pse_id'], $velocityWeeks, $ctx);
        if (isset($velocity['error'])) {
            return $velocity;
        }

        $currentStock = $velocity['quantity'];
        $velocityPerWeek = $velocity['velocityPerWeek'];
        $onActiveCampaign = $this->campaignGateway->isProductOnActiveSale($velocity['productId'], $ctx);

        $estimatedStockoutDate = $velocityPerWeek > 0
            ? (new \DateTime())->modify(\sprintf('+%d days', (int) ceil(($currentStock / $velocityPerWeek) * 7)))->format('Y-m-d')
            : null;

        $targetCoverageStock = (int) ceil($velocityPerWeek * $coverageWeeks);
        $proposedQuantity = max(0, $targetCoverageStock - (int) ceil($currentStock));

        $proposal = [
            'pseId' => $velocity['pseId'],
            'pseRef' => $velocity['pseRef'],
            'productRef' => $velocity['productRef'],
            'currentStock' => $currentStock,
            'velocityPerWeek' => $velocityPerWeek,
            'estimatedStockoutDate' => $estimatedStockoutDate,
            'onActiveCampaign' => $onActiveCampaign,
            'proposedQuantity' => $proposedQuantity,
        ];

        if ($proposedQuantity <= 0) {
            return [
                'proposal' => $proposal,
                'message' => \sprintf(
                    'Current stock already covers %d week(s) of demand at the observed velocity; no restock proposed.',
                    $coverageWeeks,
                ),
            ];
        }

        $newQuantity = $currentStock + $proposedQuantity;
        $staged = $this->stagingGateway->stageRestockProposal($velocity['pseId'], $newQuantity, [
            'velocityPerWeek' => $velocityPerWeek,
            'estimatedStockoutDate' => $estimatedStockoutDate,
            'onActiveCampaign' => $onActiveCampaign,
            'proposedQuantity' => $proposedQuantity,
        ], $ctx);

        if (isset($staged['error'])) {
            return $staged;
        }

        $proposal['newStockAfterRestock'] = $newQuantity;

        return [
            'proposal' => $proposal,
            'staged_change' => $staged,
            'message' => 'Restock proposal recorded. It requires human approval in the approval console before anything is applied.',
        ];
    }
}

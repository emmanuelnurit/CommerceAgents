<?php

declare(strict_types=1);

namespace CommerceAgents\Service\Merchant;

use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Model\AgentStagedChange;
use CommerceAgents\StagedChange\StagedChangeData;
use CommerceAgents\Tool\Admin\Gateway\StagingGatewayInterface;
use Thelia\Model\CurrencyQuery;
use Thelia\Model\ProductPriceQuery;
use Thelia\Model\ProductSaleElementsQuery;

final readonly class TheliaStagingGateway implements StagingGatewayInterface
{
    public function stagePriceUpdate(int $pseId, float $newPrice, ?float $newPromoPrice, ToolContext $ctx): array
    {
        if ($ctx->conversationId === null || $ctx->adminId === null) {
            return ['error' => 'No conversation context'];
        }

        $pse = ProductSaleElementsQuery::create()->findPk($pseId);
        if ($pse === null) {
            return ['error' => 'Variant not found'];
        }

        $defaultCurrency = CurrencyQuery::create()->filterByByDefault(true)->findOne();
        $price = $defaultCurrency !== null
            ? ProductPriceQuery::create()
                ->filterByProductSaleElementsId($pseId)
                ->filterByCurrencyId($defaultCurrency->getId())
                ->findOne()
            : null;

        if ($price === null) {
            return ['error' => 'No price row found for the default currency'];
        }

        $before = [
            'price' => round((float) $price->getPrice(), 2),
            'promoPrice' => round((float) $price->getPromoPrice(), 2),
            'promo' => (bool) $pse->getPromo(),
            'pseRef' => $pse->getRef(),
        ];
        $after = [
            'price' => round($newPrice, 2),
            'promoPrice' => $newPromoPrice !== null ? round($newPromoPrice, 2) : $before['promoPrice'],
            'promo' => $before['promo'],
            'pseRef' => $pse->getRef(),
        ];

        return $this->createChange('pse_price', $pseId, $before, $after, $ctx);
    }

    public function stageStockUpdate(int $pseId, float $newQuantity, ToolContext $ctx): array
    {
        if ($ctx->conversationId === null || $ctx->adminId === null) {
            return ['error' => 'No conversation context'];
        }

        $pse = ProductSaleElementsQuery::create()->findPk($pseId);
        if ($pse === null) {
            return ['error' => 'Variant not found'];
        }

        $before = ['quantity' => (float) $pse->getQuantity(), 'pseRef' => $pse->getRef()];
        $after = ['quantity' => $newQuantity, 'pseRef' => $pse->getRef()];

        return $this->createChange('pse_stock', $pseId, $before, $after, $ctx);
    }

    private function createChange(string $targetType, int $targetId, array $before, array $after, ToolContext $ctx): array
    {
        $change = (new AgentStagedChange())
            ->setConversationId($ctx->conversationId)
            ->setAdminId($ctx->adminId)
            ->setTargetType($targetType)
            ->setTargetId($targetId)
            ->setPayloadBefore(json_encode($before, \JSON_THROW_ON_ERROR))
            ->setPayloadAfter(json_encode($after, \JSON_THROW_ON_ERROR))
            ->setStatus(StagedChangeData::STATUS_PENDING);
        $change->save();

        return [
            'changeId' => $change->getId(),
            'targetType' => $targetType,
            'targetId' => $targetId,
            'before' => $before,
            'after' => $after,
            'status' => StagedChangeData::STATUS_PENDING,
        ];
    }
}

<?php

declare(strict_types=1);

namespace CommerceAgents\Service\Merchant;

use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Tool\Admin\Gateway\CatalogAdminGatewayInterface;
use Propel\Runtime\ActiveQuery\Criteria;
use Thelia\Model\CategoryQuery;
use Thelia\Model\CurrencyQuery;
use Thelia\Model\ProductPriceQuery;
use Thelia\Model\ProductQuery;
use Thelia\Model\ProductSaleElementsQuery;

final readonly class TheliaCatalogAdminGateway implements CatalogAdminGatewayInterface
{
    public function getListings(?string $search, int $limit, int $offset, ToolContext $ctx): array
    {
        $query = ProductQuery::create()->orderByPosition()->limit($limit)->offset($offset);

        if ($search !== null) {
            $query->useI18nQuery($ctx->locale)
                ->filterByTitle('%'.$search.'%', Criteria::LIKE)
                ->endUse();
        }

        $listings = [];
        foreach ($query->find() as $product) {
            $product->setLocale($ctx->locale);

            $categoryTitles = [];
            $defaultCategoryId = $product->getDefaultCategoryId();
            if ($defaultCategoryId) {
                $category = CategoryQuery::create()->findPk($defaultCategoryId);
                if ($category !== null) {
                    $categoryTitles[] = $category->setLocale($ctx->locale)->getTitle();
                }
            }

            $listings[] = [
                'id' => $product->getId(),
                'ref' => $product->getRef(),
                'title' => $product->getTitle(),
                'visible' => (bool) $product->getVisible(),
                'position' => $product->getPosition(),
                'categoryTitles' => $categoryTitles,
                'publicUrl' => $product->getUrl($ctx->locale),
            ];
        }

        return $listings;
    }

    public function getInventory(?float $lowStockThreshold, int $limit, ToolContext $ctx): array
    {
        $query = ProductSaleElementsQuery::create()
            ->joinWithProduct()
            ->orderByQuantity(Criteria::ASC)
            ->limit($limit);

        if ($lowStockThreshold !== null) {
            $query->filterByQuantity($lowStockThreshold, Criteria::LESS_EQUAL);
        }

        $inventory = [];
        foreach ($query->find() as $pse) {
            $product = $pse->getProduct();
            $inventory[] = [
                'productId' => $product->getId(),
                'productRef' => $product->getRef(),
                'pseId' => $pse->getId(),
                'pseRef' => $pse->getRef(),
                'title' => $product->setLocale($ctx->locale)->getTitle(),
                'quantity' => (float) $pse->getQuantity(),
                'isDefault' => (bool) $pse->getIsDefault(),
            ];
        }

        return $inventory;
    }

    public function getPricing(int $productId, ToolContext $ctx): ?array
    {
        $product = ProductQuery::create()->findPk($productId);
        if ($product === null) {
            return null;
        }

        $defaultCurrency = CurrencyQuery::create()->filterByByDefault(true)->findOne();

        $pses = [];
        foreach (ProductSaleElementsQuery::create()->filterByProductId($productId)->find() as $pse) {
            $price = $defaultCurrency !== null
                ? ProductPriceQuery::create()
                    ->filterByProductSaleElementsId($pse->getId())
                    ->filterByCurrencyId($defaultCurrency->getId())
                    ->findOne()
                : null;

            $pses[] = [
                'pseId' => $pse->getId(),
                'ref' => $pse->getRef(),
                'price' => $price !== null ? round((float) $price->getPrice(), 2) : null,
                'promoPrice' => $price !== null ? round((float) $price->getPromoPrice(), 2) : null,
                'promo' => (bool) $pse->getPromo(),
                'currency' => $defaultCurrency?->getCode(),
            ];
        }

        return [
            'productId' => $product->getId(),
            'ref' => $product->getRef(),
            'title' => $product->setLocale($ctx->locale)->getTitle(),
            'pses' => $pses,
        ];
    }
}

<?php

declare(strict_types=1);

namespace CommerceAgents\Service\Catalog;

use Propel\Runtime\ActiveQuery\Criteria;
use Thelia\Model\ProductImageQuery;

/**
 * Public URL of the thumbnail of a product's first visible image.
 * Results are memoized per product for the lifetime of the request, so a
 * listing of several variants of one product resizes its picture only once.
 */
final class ProductThumbnailProvider
{
    private const CACHE_SUBDIRECTORY = 'product';

    /** @var array<int, string|null> */
    private array $urlByProductId = [];

    /** @var array<int, string|null> */
    private array $urlByVariantId = [];

    public function __construct(
        private readonly ThumbnailUrlBuilder $thumbnailUrlBuilder,
    ) {
    }

    public function urlFor(int $productId): ?string
    {
        if (\array_key_exists($productId, $this->urlByProductId)) {
            return $this->urlByProductId[$productId];
        }

        $image = ProductImageQuery::create()
            ->filterByProductId($productId)
            ->filterByVisible(1)
            ->orderByPosition(Criteria::ASC)
            ->findOne();

        return $this->urlByProductId[$productId] = $this->buildUrl($image);
    }

    /**
     * Picture of one variant, when the merchant attached images to it. Most
     * catalogues never do, so the product picture is the fallback rather than
     * an empty slot.
     */
    public function urlForVariant(int $productSaleElementsId, int $productId): ?string
    {
        if (\array_key_exists($productSaleElementsId, $this->urlByVariantId)) {
            return $this->urlByVariantId[$productSaleElementsId];
        }

        $image = ProductImageQuery::create()
            ->useProductSaleElementsProductImageQuery()
                ->filterByProductSaleElementsId($productSaleElementsId)
            ->endUse()
            ->filterByVisible(1)
            ->orderByPosition(Criteria::ASC)
            ->findOne();

        return $this->urlByVariantId[$productSaleElementsId] = $image === null
            ? $this->urlFor($productId)
            : $this->buildUrl($image);
    }

    private function buildUrl(?\Thelia\Model\ProductImage $image): ?string
    {
        return $image === null
            ? null
            : $this->thumbnailUrlBuilder->build($image->getUploadDir().DS.$image->getFile(), self::CACHE_SUBDIRECTORY);
    }
}

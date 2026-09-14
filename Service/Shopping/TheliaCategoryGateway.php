<?php

declare(strict_types=1);

namespace CommerceAgents\Service\Shopping;

use CommerceAgents\Tool\Shopping\Gateway\CategoryGatewayInterface;
use Thelia\Model\CategoryQuery;
use Thelia\Model\ProductCategoryQuery;

/**
 * Product categories are what a visitor actually names ("chairs", "sofas"):
 * store catalogues title products after a model name, never after their type,
 * so the category tree is the only vocabulary a keyword search can land on.
 *
 * Not readonly: the list is memoized for the lifetime of the request, since the
 * shopping prompt and every fallback search ask for it again.
 */
final class TheliaCategoryGateway implements CategoryGatewayInterface
{
    /** @var array<string, list<array{id: int, title: string, url: string|null, productCount: int}>> */
    private array $cacheByLocale = [];

    public function getCategories(string $locale, int $limit): array
    {
        return \array_slice($this->all($locale), 0, max(1, $limit));
    }

    public function findByName(string $name, string $locale): ?array
    {
        return CategoryMatcher::best($name, $this->all($locale));
    }

    /**
     * @return list<array{id: int, title: string, url: string|null, productCount: int}>
     */
    private function all(string $locale): array
    {
        if (isset($this->cacheByLocale[$locale])) {
            return $this->cacheByLocale[$locale];
        }

        $counts = self::visibleProductCounts();

        $categories = [];
        foreach (CategoryQuery::create()->filterByVisible(true)->orderByPosition()->find() as $category) {
            $count = $counts[(int) $category->getId()] ?? 0;
            if ($count === 0) {
                continue;
            }

            $category->setLocale($locale);
            $categories[] = [
                'id' => (int) $category->getId(),
                'title' => $category->getTitle() ?? '',
                'url' => $category->getUrl($locale),
                'productCount' => $count,
            ];
        }

        usort($categories, static fn (array $a, array $b): int => $b['productCount'] <=> $a['productCount']);

        return $this->cacheByLocale[$locale] = $categories;
    }

    /**
     * One grouped query instead of one count per category.
     *
     * @return array<int, int>
     */
    private static function visibleProductCounts(): array
    {
        $rows = ProductCategoryQuery::create()
            ->useProductQuery()
                ->filterByVisible(true)
            ->endUse()
            ->groupByCategoryId()
            ->select(['CategoryId'])
            ->withColumn('COUNT(*)', 'ProductCount')
            ->find();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['CategoryId']] = (int) $row['ProductCount'];
        }

        return $counts;
    }
}

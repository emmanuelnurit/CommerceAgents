<?php

declare(strict_types=1);

namespace CommerceAgents\Service\Shopping;

use CommerceAgents\Tool\Shopping\Gateway\FeatureGatewayInterface;
use Thelia\Model\FeatureAvQuery;
use Thelia\Model\FeatureProductQuery;

/**
 * A material, a style, a finish: properties of the product itself. Without
 * them "les produits en tissu" degrades into a title search, finds nothing,
 * and the assistant falls back on whatever category looks close.
 *
 * Not readonly: the list is memoized for the request.
 */
final class TheliaFeatureGateway implements FeatureGatewayInterface
{
    /** @var array<string, list<array{id: int, title: string, feature: string, productCount: int}>> */
    private array $cacheByLocale = [];

    public function getValues(string $locale, int $limit): array
    {
        return \array_slice($this->all($locale), 0, max(1, $limit));
    }

    public function findValueByName(string $term, string $locale): ?array
    {
        return Terms::best($term, $this->all($locale), 'title', 'productCount');
    }

    /**
     * @return list<array{id: int, title: string, feature: string, productCount: int}>
     */
    private function all(string $locale): array
    {
        if (isset($this->cacheByLocale[$locale])) {
            return $this->cacheByLocale[$locale];
        }

        $counts = self::productCounts();

        $values = [];
        foreach (FeatureAvQuery::create()->orderByPosition()->find() as $featureAv) {
            $count = $counts[(int) $featureAv->getId()] ?? 0;
            if ($count === 0) {
                continue;
            }

            $featureAv->setLocale($locale);
            $feature = $featureAv->getFeature();
            $feature->setLocale($locale);

            $values[] = [
                'id' => (int) $featureAv->getId(),
                'title' => $featureAv->getTitle() ?? '',
                'feature' => $feature->getTitle() ?? '',
                'productCount' => $count,
            ];
        }

        usort($values, static fn (array $a, array $b): int => $b['productCount'] <=> $a['productCount']);

        return $this->cacheByLocale[$locale] = $values;
    }

    /**
     * @return array<int, int>
     */
    private static function productCounts(): array
    {
        $rows = FeatureProductQuery::create()
            ->useProductQuery()
                ->filterByVisible(true)
            ->endUse()
            ->groupByFeatureAvId()
            ->select(['FeatureAvId'])
            ->withColumn('COUNT(DISTINCT product_id)', 'ProductCount')
            ->find();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['FeatureAvId']] = (int) $row['ProductCount'];
        }

        return $counts;
    }
}

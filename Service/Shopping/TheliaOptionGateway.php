<?php

declare(strict_types=1);

namespace CommerceAgents\Service\Shopping;

use CommerceAgents\Tool\Shopping\Gateway\OptionGatewayInterface;
use Thelia\Model\AttributeAvQuery;
use Thelia\Model\AttributeCombinationQuery;

/**
 * Option values are what a visitor names when asking for "les produits orange":
 * a colour is never a product title, it lives on the variants.
 *
 * Not readonly: the list is memoized for the request, since the prompt and each
 * option search ask for it again.
 */
final class TheliaOptionGateway implements OptionGatewayInterface
{
    /** @var array<string, list<array{id: int, title: string, attribute: string, variantCount: int}>> */
    private array $cacheByLocale = [];

    public function getValues(string $locale, int $limit): array
    {
        return \array_slice($this->all($locale), 0, max(1, $limit));
    }

    public function findValueByName(string $term, string $locale): ?array
    {
        return Terms::best($term, $this->all($locale), 'title', 'variantCount');
    }

    /**
     * @return list<array{id: int, title: string, attribute: string, variantCount: int}>
     */
    private function all(string $locale): array
    {
        if (isset($this->cacheByLocale[$locale])) {
            return $this->cacheByLocale[$locale];
        }

        $counts = self::variantCounts();

        $values = [];
        foreach (AttributeAvQuery::create()->orderByPosition()->find() as $attributeAv) {
            $count = $counts[(int) $attributeAv->getId()] ?? 0;
            if ($count === 0) {
                continue;
            }

            $attributeAv->setLocale($locale);
            $attribute = $attributeAv->getAttribute();
            $attribute->setLocale($locale);

            $values[] = [
                'id' => (int) $attributeAv->getId(),
                'title' => $attributeAv->getTitle() ?? '',
                'attribute' => $attribute->getTitle() ?? '',
                'variantCount' => $count,
            ];
        }

        usort($values, static fn (array $a, array $b): int => $b['variantCount'] <=> $a['variantCount']);

        return $this->cacheByLocale[$locale] = $values;
    }

    /**
     * One grouped query instead of one count per value.
     *
     * @return array<int, int>
     */
    private static function variantCounts(): array
    {
        $rows = AttributeCombinationQuery::create()
            ->useProductSaleElementsQuery()
                ->useProductQuery()
                    ->filterByVisible(true)
                ->endUse()
            ->endUse()
            ->groupByAttributeAvId()
            ->select(['AttributeAvId'])
            ->withColumn('COUNT(*)', 'VariantCount')
            ->find();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['AttributeAvId']] = (int) $row['VariantCount'];
        }

        return $counts;
    }
}

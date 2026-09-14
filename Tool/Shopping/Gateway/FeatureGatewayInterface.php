<?php

declare(strict_types=1);

namespace CommerceAgents\Tool\Shopping\Gateway;

interface FeatureGatewayInterface
{
    /**
     * Every feature value the catalog uses ("Matière: Tissu"), most used first.
     * Features sit on the product, unlike options which sit on its variants.
     *
     * @return list<array{id: int, title: string, feature: string, productCount: int}>
     */
    public function getValues(string $locale, int $limit): array;

    /**
     * @return array{id: int, title: string, feature: string, productCount: int}|null
     */
    public function findValueByName(string $term, string $locale): ?array;
}

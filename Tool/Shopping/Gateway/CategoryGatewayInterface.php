<?php

declare(strict_types=1);

namespace CommerceAgents\Tool\Shopping\Gateway;

interface CategoryGatewayInterface
{
    /**
     * Visible categories that actually hold products, most populated first.
     *
     * @return list<array{id: int, title: string, url: string|null, productCount: int}>
     */
    public function getCategories(string $locale, int $limit): array;

    /**
     * Closest category to a free-text term, or null when nothing is close enough.
     *
     * @return array{id: int, title: string, url: string|null, productCount: int}|null
     */
    public function findByName(string $name, string $locale): ?array;
}

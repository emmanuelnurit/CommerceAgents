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

    /**
     * @return array{id: int, title: string, url: string|null, productCount: int}|null
     */
    public function findById(int $id, string $locale): ?array;

    /**
     * Other visible categories under the same parent as $categoryId, excluding
     * it — the "see also" neighbours for a quick reply (MYO-282 §1.A). Only
     * categories that actually hold products are returned; an empty list
     * means no real sibling exists, never a guessed one.
     *
     * @return list<array{id: int, title: string, url: string|null, productCount: int}>
     */
    public function getSiblings(int $categoryId, string $locale, int $limit): array;
}

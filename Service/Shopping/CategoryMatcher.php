<?php

declare(strict_types=1);

namespace CommerceAgents\Service\Shopping;

/**
 * Maps a free-text term ("chairs", "un fauteuil") onto a category of the store.
 * Pure on purpose: the ranking is the risky part and must be testable without
 * a catalog behind it.
 */
final readonly class CategoryMatcher
{
    /**
     * @param list<array{id: int, title: string, url: string|null, productCount: int}> $categories
     *
     * @return array{id: int, title: string, url: string|null, productCount: int}|null
     */
    public static function best(string $name, array $categories): ?array
    {
        // A tie goes to the category holding more products: "chair" should land
        // on the 14-product Chairs, not on a 1-product outlet shelf.
        return Terms::best($name, $categories, 'title', 'productCount');
    }
}

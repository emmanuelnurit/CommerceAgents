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
    private const MIN_TOKEN_LENGTH = 3;

    /**
     * @param list<array{id: int, title: string, url: string|null, productCount: int}> $categories
     *
     * @return array{id: int, title: string, url: string|null, productCount: int}|null
     */
    public static function best(string $name, array $categories): ?array
    {
        $wanted = self::tokens($name);
        if ($wanted === []) {
            return null;
        }

        $best = null;
        $bestScore = 0;

        foreach ($categories as $category) {
            $score = \count(array_intersect($wanted, self::tokens($category['title'])));
            if ($score === 0) {
                continue;
            }
            // A tie goes to the category holding more products: "chair" should
            // land on the 14-product Chairs, not on a 1-product outlet shelf.
            if ($score > $bestScore || ($score === $bestScore && $category['productCount'] > ($best['productCount'] ?? 0))) {
                $best = $category;
                $bestScore = $score;
            }
        }

        return $best;
    }

    /**
     * @return list<string>
     */
    private static function tokens(string $value): array
    {
        $ascii = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower', $value);
        $ascii = \is_string($ascii) ? $ascii : mb_strtolower($value);
        $ascii = preg_replace('/[^a-z0-9]+/', ' ', $ascii) ?? '';

        $tokens = [];
        foreach (explode(' ', trim($ascii)) as $word) {
            if (mb_strlen($word) < self::MIN_TOKEN_LENGTH) {
                continue;
            }
            // Crude singularisation: "chairs" and "chair" must be the same token.
            $tokens[] = str_ends_with($word, 's') && mb_strlen($word) > self::MIN_TOKEN_LENGTH
                ? mb_substr($word, 0, -1)
                : $word;
        }

        return array_values(array_unique($tokens));
    }
}

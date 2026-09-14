<?php

declare(strict_types=1);

namespace CommerceAgents\Service\Shopping;

/**
 * Guards the price bounds a model sends. Small models invert the two and emit
 * degenerate ranges; a search that silently returns nothing then reads to the
 * assistant as "the store has none of that".
 */
final readonly class PriceRange
{
    /**
     * @return array{0: float|null, 1: float|null}
     */
    public static function sane(?float $minPrice, ?float $maxPrice): array
    {
        if ($minPrice === null || $maxPrice === null) {
            return [$minPrice, $maxPrice];
        }

        // A range is a range, whichever order the bounds arrive in.
        if ($minPrice > $maxPrice) {
            return [$maxPrice, $minPrice];
        }

        // An exact price is never what a shopper means: keep the ceiling.
        if ($minPrice === $maxPrice) {
            return [null, $maxPrice];
        }

        return [$minPrice, $maxPrice];
    }
}

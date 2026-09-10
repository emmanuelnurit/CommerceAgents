<?php

declare(strict_types=1);

namespace CommerceAgents\Service;

/**
 * Converts a token count into a provider bill, from prices expressed in USD
 * per million tokens. Returns null when a price is unknown so the caller can
 * tell "free" from "not priced".
 */
final readonly class CostCalculator
{
    private const TOKENS_PER_PRICE_UNIT = 1_000_000;

    public static function cost(?float $priceInput, ?float $priceOutput, int $tokensIn, int $tokensOut): ?float
    {
        if ($priceInput === null || $priceOutput === null) {
            return null;
        }

        return round(
            $tokensIn / self::TOKENS_PER_PRICE_UNIT * $priceInput
            + $tokensOut / self::TOKENS_PER_PRICE_UNIT * $priceOutput,
            8,
        );
    }
}

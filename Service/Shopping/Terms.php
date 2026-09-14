<?php

declare(strict_types=1);

namespace CommerceAgents\Service\Shopping;

/**
 * Turns a free-text term into comparable tokens: accents folded, case dropped,
 * plurals collapsed. Shared by the category and option matchers so "chaises"
 * and "oranges" behave the same way.
 */
final readonly class Terms
{
    private const MIN_LENGTH = 3;

    /**
     * @return list<string>
     */
    public static function tokens(string $value): array
    {
        $ascii = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower', $value);
        $ascii = \is_string($ascii) ? $ascii : mb_strtolower($value);
        $ascii = preg_replace('/[^a-z0-9]+/', ' ', $ascii) ?? '';

        $tokens = [];
        foreach (explode(' ', trim($ascii)) as $word) {
            if (mb_strlen($word) < self::MIN_LENGTH) {
                continue;
            }
            $tokens[] = str_ends_with($word, 's') && mb_strlen($word) > self::MIN_LENGTH
                ? mb_substr($word, 0, -1)
                : $word;
        }

        return array_values(array_unique($tokens));
    }

    /**
     * Best entry of $candidates for $term: most tokens in common, ties broken by
     * the weight column. Returns null when nothing overlaps.
     *
     * @param list<array<string, mixed>> $candidates
     */
    public static function best(string $term, array $candidates, string $titleKey, string $weightKey): ?array
    {
        $wanted = self::tokens($term);
        if ($wanted === []) {
            return null;
        }

        $best = null;
        $bestScore = 0;

        foreach ($candidates as $candidate) {
            $score = \count(array_intersect($wanted, self::tokens((string) $candidate[$titleKey])));
            if ($score === 0) {
                continue;
            }
            if ($score > $bestScore || ($score === $bestScore && $candidate[$weightKey] > ($best[$weightKey] ?? 0))) {
                $best = $candidate;
                $bestScore = $score;
            }
        }

        return $best;
    }
}

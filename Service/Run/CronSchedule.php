<?php

declare(strict_types=1);

namespace CommerceAgents\Service\Run;

/**
 * Minimal 5-field cron expression evaluator (minute, hour, day of month,
 * month, day of week) supporting "*", steps ("*\/n"), ranges ("a-b"), lists
 * ("a,b,c") and their combinations. Enough for the agent cron triggers
 * without pulling a dependency into the module.
 */
final class CronSchedule
{
    private const FIELD_BOUNDS = [
        [0, 59],  // minute
        [0, 23],  // hour
        [1, 31],  // day of month
        [1, 12],  // month
        [0, 6],   // day of week, 0 = Sunday (7 accepted as alias of 0)
    ];

    /**
     * First execution time strictly after $from, or null when the expression
     * matches nothing in the coming year.
     *
     * @throws \InvalidArgumentException on a malformed expression
     */
    public static function nextRunDate(string $expression, \DateTimeImmutable $from): ?\DateTimeImmutable
    {
        [$minutes, $hours, $daysOfMonth, $months, $daysOfWeek] = self::parse($expression);

        // Standard cron rule: when both day fields are restricted, a date
        // matches if either of them does.
        $parts = preg_split('/\s+/', trim($expression));
        $domRestricted = $parts[2] !== '*';
        $dowRestricted = $parts[4] !== '*';

        $cursor = $from->setTime((int) $from->format('H'), (int) $from->format('i'))->modify('+1 minute');
        $limit = $from->modify('+366 days');

        while ($cursor <= $limit) {
            if (!isset($months[(int) $cursor->format('n')])) {
                $cursor = $cursor->modify('first day of next month')->setTime(0, 0);
                continue;
            }

            $domMatches = isset($daysOfMonth[(int) $cursor->format('j')]);
            $dowMatches = isset($daysOfWeek[(int) $cursor->format('w')]);
            $dayMatches = ($domRestricted && $dowRestricted) ? ($domMatches || $dowMatches) : ($domMatches && $dowMatches);
            if (!$dayMatches) {
                $cursor = $cursor->modify('+1 day')->setTime(0, 0);
                continue;
            }

            if (!isset($hours[(int) $cursor->format('G')])) {
                $cursor = $cursor->setTime((int) $cursor->format('G'), 0)->modify('+1 hour');
                continue;
            }

            if (isset($minutes[(int) $cursor->format('i')])) {
                return $cursor;
            }

            $cursor = $cursor->modify('+1 minute');
        }

        return null;
    }

    /**
     * @return array{0: array<int, true>, 1: array<int, true>, 2: array<int, true>, 3: array<int, true>, 4: array<int, true>}
     */
    private static function parse(string $expression): array
    {
        $parts = preg_split('/\s+/', trim($expression));
        if (!\is_array($parts) || \count($parts) !== 5) {
            throw new \InvalidArgumentException(\sprintf('Cron expression "%s" must have exactly 5 fields', $expression));
        }

        $fields = [];
        foreach ($parts as $index => $part) {
            [$min, $max] = self::FIELD_BOUNDS[$index];
            $fields[] = self::parseField($part, $min, $max, $expression);
        }

        return $fields;
    }

    /**
     * @return array<int, true>
     */
    private static function parseField(string $field, int $min, int $max, string $expression): array
    {
        $values = [];

        foreach (explode(',', $field) as $item) {
            $step = 1;
            if (str_contains($item, '/')) {
                [$item, $stepSpec] = explode('/', $item, 2);
                if (!ctype_digit($stepSpec) || (int) $stepSpec < 1) {
                    throw new \InvalidArgumentException(\sprintf('Invalid step in cron expression "%s"', $expression));
                }
                $step = (int) $stepSpec;
            }

            if ($item === '*') {
                [$from, $to] = [$min, $max];
            } elseif (str_contains($item, '-')) {
                [$fromSpec, $toSpec] = explode('-', $item, 2);
                if (!ctype_digit($fromSpec) || !ctype_digit($toSpec)) {
                    throw new \InvalidArgumentException(\sprintf('Invalid range in cron expression "%s"', $expression));
                }
                [$from, $to] = [(int) $fromSpec, (int) $toSpec];
            } elseif (ctype_digit($item)) {
                $from = $to = (int) $item;
            } else {
                throw new \InvalidArgumentException(\sprintf('Invalid field "%s" in cron expression "%s"', $field, $expression));
            }

            // Day-of-week alias: 7 means Sunday.
            if ($min === 0 && $max === 6) {
                $from = $from === 7 ? 0 : $from;
                $to = $to === 7 ? 0 : $to;
            }

            if ($from > $to || $from < $min || $to > $max) {
                throw new \InvalidArgumentException(\sprintf('Value out of bounds in cron expression "%s"', $expression));
            }

            for ($value = $from; $value <= $to; $value += $step) {
                $values[$value] = true;
            }
        }

        return $values;
    }
}

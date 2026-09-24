<?php

declare(strict_types=1);

namespace CommerceAgents\Service\Run;

/**
 * Reads and evaluates `agent_trigger.conditions` (plan MYO-226 §3.3 point 3:
 * "montant min, statut cible…"). The column is free-form JSON edited by the
 * trigger wizard (MYO-227), so a malformed or absent value is never fatal:
 * it is treated as "no condition", never as a reason to drop a run.
 */
final class TriggerConditions
{
    /**
     * @param array<string, mixed> $context known keys: 'amount' (float), 'status_id' (int)
     */
    public static function matches(?string $json, array $context): bool
    {
        $conditions = self::decode($json);

        if (isset($conditions['min_amount'], $context['amount']) && is_numeric($conditions['min_amount'])) {
            if ((float) $context['amount'] < (float) $conditions['min_amount']) {
                return false;
            }
        }

        if (isset($conditions['target_statuses'], $context['status_id']) && \is_array($conditions['target_statuses'])) {
            $targets = array_map('intval', $conditions['target_statuses']);
            if ($targets !== [] && !\in_array((int) $context['status_id'], $targets, true)) {
                return false;
            }
        }

        return true;
    }

    public static function intOption(?string $json, string $key, int $default): int
    {
        $value = self::decode($json)[$key] ?? null;

        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * Raw value for a condition key, or null when absent/malformed -- unlike
     * {@see self::intOption()}, this never substitutes a default, for guided
     * settings fields (MYO-508 AC2) where "unset" (illimité/désactivé) is
     * itself a meaningful, distinct value from any number.
     */
    public static function option(?string $json, string $key): mixed
    {
        return self::decode($json)[$key] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }

        try {
            $decoded = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return \is_array($decoded) ? $decoded : [];
    }
}

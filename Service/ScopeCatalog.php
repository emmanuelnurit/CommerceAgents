<?php

declare(strict_types=1);

namespace CommerceAgents\Service;

/**
 * Guided-edition "scope" setting (MYO-508 AC3), same family as TriggerCatalog
 * and CapabilityCatalog: a fixed catalog, zero schema of its own. Composes a
 * fixed sentence appended to `role_prompt` for the chosen category/customer
 * scope — always a full-text append, never a fragment inserted mid-text.
 *
 * Write-only by design: the composed sentence is never re-parsed to pre-fill
 * the setting back. Drift detection (AC4) only compares role_prompt against
 * the tone/detail `rolePromptVariants` of {@see AgentPresets}, never against
 * scope sentences.
 */
final class ScopeCatalog
{
    public const CUSTOMER_SCOPE_ALL = 'all';
    public const CUSTOMER_SCOPE_RETURNING = 'returning_customers';

    /** Named after `customer.reseller` (MYO-518 decision 3) — Thelia has no "grand compte" field to honor that wording. */
    public const CUSTOMER_SCOPE_EXCLUDE_RESELLERS = 'exclude_resellers';

    /**
     * customer scope code => fixed sentence appended to role_prompt.
     * CUSTOMER_SCOPE_ALL (the default, "all customers") has no sentence: no
     * restriction to state.
     *
     * @var array<string, string>
     */
    private const CUSTOMER_SCOPE_SENTENCES = [
        self::CUSTOMER_SCOPE_RETURNING => "Ne t'adresse qu'aux clients ayant déjà passé au moins une commande.",
        self::CUSTOMER_SCOPE_EXCLUDE_RESELLERS => 'N\'inclus jamais les comptes revendeurs dans ton périmètre.',
    ];

    /** customer scope code => untranslated business label key (guided settings select, MYO-508 AC2). */
    private const CUSTOMER_SCOPE_LABELS = [
        self::CUSTOMER_SCOPE_ALL => 'All customers',
        self::CUSTOMER_SCOPE_RETURNING => 'Returning customers only',
        self::CUSTOMER_SCOPE_EXCLUDE_RESELLERS => 'Exclude reseller accounts',
    ];

    /**
     * @return list<string> known customer scope codes, in display order
     */
    public static function customerScopes(): array
    {
        return [self::CUSTOMER_SCOPE_ALL, self::CUSTOMER_SCOPE_RETURNING, self::CUSTOMER_SCOPE_EXCLUDE_RESELLERS];
    }

    public static function customerScopeLabelKey(string $code): string
    {
        return self::CUSTOMER_SCOPE_LABELS[$code] ?? $code;
    }

    /**
     * @param list<string> $categoryTitles shop category titles selected as scope, already resolved to display text; an empty list means "all categories" and leaves the prompt untouched
     */
    public static function appendCategoryScope(string $rolePrompt, array $categoryTitles): string
    {
        if ($categoryTitles === []) {
            return $rolePrompt;
        }

        return self::append($rolePrompt, \sprintf('Concentre-toi uniquement sur les produits des catégories : %s.', implode(', ', $categoryTitles)));
    }

    public static function appendCustomerScope(string $rolePrompt, string $customerScope): string
    {
        $sentence = self::CUSTOMER_SCOPE_SENTENCES[$customerScope] ?? null;

        return $sentence === null ? $rolePrompt : self::append($rolePrompt, $sentence);
    }

    private static function append(string $rolePrompt, string $sentence): string
    {
        $rolePrompt = rtrim($rolePrompt);

        return $rolePrompt === '' ? $sentence : $rolePrompt.' '.$sentence;
    }
}

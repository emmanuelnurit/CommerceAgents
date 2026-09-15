<?php

declare(strict_types=1);

namespace CommerceAgents\Service;

/**
 * Proactive scenario messages are composed from small locale-keyed string
 * templates (never through the LLM, never through the full Translator/module
 * catalogue machinery — these resolvers must stay unit-testable without a
 * database). Only the 4 locales this module already ships in I18n/ are
 * covered; anything else falls back to French.
 */
final class ProactiveLocale
{
    private function __construct()
    {
    }

    public static function group(string $locale): string
    {
        $prefix = strtolower(substr($locale, 0, 2));

        return \in_array($prefix, ['fr', 'en', 'es', 'it'], true) ? $prefix : 'fr';
    }
}

<?php

declare(strict_types=1);

namespace CommerceAgents\Service;

/**
 * Proactive scenario messages are composed from small locale-keyed string
 * templates (never through the LLM, never through the full Translator/module
 * catalogue machinery — these resolvers must stay unit-testable without a
 * database). Only 4 locales have templates today (MYO-274): if neither the
 * visitor's locale nor the site's default language is covered, English is
 * used — not French — since it is the language most likely to be understood
 * on a store that is configured in neither.
 */
final class ProactiveLocale
{
    private const SUPPORTED = ['fr', 'en', 'es', 'it'];
    private const ULTIMATE_FALLBACK = 'en';

    private function __construct()
    {
    }

    public static function group(string $locale, string $siteDefaultLocale): string
    {
        $prefix = self::prefixOf($locale);
        if (\in_array($prefix, self::SUPPORTED, true)) {
            return $prefix;
        }

        $sitePrefix = self::prefixOf($siteDefaultLocale);

        return \in_array($sitePrefix, self::SUPPORTED, true) ? $sitePrefix : self::ULTIMATE_FALLBACK;
    }

    private static function prefixOf(string $locale): string
    {
        return strtolower(substr($locale, 0, 2));
    }
}

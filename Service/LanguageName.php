<?php

declare(strict_types=1);

namespace CommerceAgents\Service;

/**
 * A locale as a model understands it: the English name plus the native one,
 * which is a far stronger signal than the raw code.
 */
final readonly class LanguageName
{
    public static function of(string $locale): string
    {
        $name = \Locale::getDisplayLanguage($locale, 'en');

        if ($name === '' || $name === \Locale::getPrimaryLanguage($locale)) {
            return $locale;
        }

        $native = \Locale::getDisplayLanguage($locale, $locale);

        return $native === '' || $native === $name ? $name : sprintf('%s (%s)', $name, $native);
    }
}

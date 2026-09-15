<?php

declare(strict_types=1);

namespace CommerceAgents\Service\Locale;

use Thelia\Model\Lang;

final class TheliaSiteDefaultLocaleProvider implements SiteDefaultLocaleProviderInterface
{
    public function defaultLocale(): string
    {
        return Lang::getDefaultLanguage()->getLocale();
    }
}

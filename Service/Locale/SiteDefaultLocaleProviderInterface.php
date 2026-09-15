<?php

declare(strict_types=1);

namespace CommerceAgents\Service\Locale;

/**
 * The site's configured default language (Configuration > Languages), which
 * MYO-274 makes the sole fallback for every assistant locale cascade.
 * Isolated behind an interface so AssistantLocaleResolver and its callers
 * stay unit-testable without a Propel connection.
 */
interface SiteDefaultLocaleProviderInterface
{
    public function defaultLocale(): string;
}

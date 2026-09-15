<?php

declare(strict_types=1);

namespace CommerceAgents\Service\Locale;

/**
 * MYO-274: single source of truth for the locale every assistant answers in.
 * The site's default language (Configuration > Languages) is the sole
 * fallback across the module — no locale is ever hard-coded here, so
 * switching the site's default language changes every assistant's language
 * on the next exchange. Each cascade is deliberately explicit:
 *
 * - front chat: the language the visitor is browsing the store in, else the
 *   site default;
 * - back-office chat / MCP: the administrator's profile locale, else the
 *   site default;
 * - scheduled agent runs: the "Agents IA" screen exposes no per-agent locale
 *   field today, so a run always follows the site default (see
 *   forAgentRun()).
 */
final readonly class AssistantLocaleResolver
{
    public function __construct(
        private SiteDefaultLocaleProviderInterface $siteDefaultLocaleProvider,
    ) {
    }

    public function forVisitor(?string $navigationLocale): string
    {
        return $this->firstNonEmpty($navigationLocale) ?? $this->siteDefault();
    }

    public function forAdmin(?string $adminLocale): string
    {
        return $this->firstNonEmpty($adminLocale) ?? $this->siteDefault();
    }

    /**
     * There is no per-agent locale picker in the "Agents IA" UI: until one
     * ships, a run's language simply follows the site's default, whatever
     * value happens to be stored on the agent_definition row.
     */
    public function forAgentRun(): string
    {
        return $this->siteDefault();
    }

    public function siteDefault(): string
    {
        return $this->siteDefaultLocaleProvider->defaultLocale();
    }

    private function firstNonEmpty(?string $locale): ?string
    {
        return $locale !== null && trim($locale) !== '' ? $locale : null;
    }
}

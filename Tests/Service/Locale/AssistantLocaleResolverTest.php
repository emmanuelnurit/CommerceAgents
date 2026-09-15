<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Service\Locale;

use CommerceAgents\Service\Locale\AssistantLocaleResolver;
use CommerceAgents\Service\Locale\SiteDefaultLocaleProviderInterface;
use PHPUnit\Framework\TestCase;

final class FakeSiteDefaultLocaleProvider implements SiteDefaultLocaleProviderInterface
{
    public function __construct(private readonly string $locale = 'fr_FR')
    {
    }

    public function defaultLocale(): string
    {
        return $this->locale;
    }
}

/**
 * MYO-274: the site's default language is the sole fallback of every
 * assistant locale cascade — never a locale hard-coded in the module.
 */
final class AssistantLocaleResolverTest extends TestCase
{
    public function testVisitorNavigationLocaleWinsOverSiteDefault(): void
    {
        $resolver = new AssistantLocaleResolver(new FakeSiteDefaultLocaleProvider('fr_FR'));

        $this->assertSame('en_US', $resolver->forVisitor('en_US'));
    }

    public function testVisitorFallsBackToSiteDefaultWhenNavigationLocaleIsMissing(): void
    {
        $resolver = new AssistantLocaleResolver(new FakeSiteDefaultLocaleProvider('es_ES'));

        $this->assertSame('es_ES', $resolver->forVisitor(null));
        $this->assertSame('es_ES', $resolver->forVisitor(''));
        $this->assertSame('es_ES', $resolver->forVisitor('  '));
    }

    public function testAdminLocaleWinsOverSiteDefault(): void
    {
        $resolver = new AssistantLocaleResolver(new FakeSiteDefaultLocaleProvider('fr_FR'));

        $this->assertSame('it_IT', $resolver->forAdmin('it_IT'));
    }

    public function testAdminFallsBackToSiteDefaultWhenProfileLocaleIsMissing(): void
    {
        $resolver = new AssistantLocaleResolver(new FakeSiteDefaultLocaleProvider('de_DE'));

        $this->assertSame('de_DE', $resolver->forAdmin(null));
        $this->assertSame('de_DE', $resolver->forAdmin(''));
    }

    public function testAgentRunAlwaysFollowsTheSiteDefault(): void
    {
        $resolver = new AssistantLocaleResolver(new FakeSiteDefaultLocaleProvider('pt_PT'));

        $this->assertSame('pt_PT', $resolver->forAgentRun());
    }

    public function testSiteDefaultDelegatesToTheProvider(): void
    {
        $resolver = new AssistantLocaleResolver(new FakeSiteDefaultLocaleProvider('nl_NL'));

        $this->assertSame('nl_NL', $resolver->siteDefault());
    }
}

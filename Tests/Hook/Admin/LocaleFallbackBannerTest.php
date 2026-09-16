<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Hook\Admin;

use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;

/**
 * MYO-435: CommerceAgents ships translations for 4 of the BO's 21 locales
 * (en_US, es_ES, fr_FR, it_IT — cf. I18n/*.php). Outside those, screens
 * silently fell back to English with no signal (flagged during the MYO-430
 * UX review). AdminHookManager::onMainBeforeContent() now injects an
 * explicit banner on CommerceAgents screens when the admin's locale isn't
 * covered — this proves it through the real DI container and Twig render,
 * not a unit-level assertion on the hook class alone.
 */
final class LocaleFallbackBannerTest extends WebIntegrationTestCase
{
    private const TESTID = 'data-testid="commerceagents-locale-fallback-banner"';

    private AdminSessionInjector $injector;

    protected function setUp(): void
    {
        parent::setUp();

        $this->injector = new AdminSessionInjector();
        $this->getService(EventDispatcherInterface::class)->addSubscriber($this->injector);
    }

    protected function tearDown(): void
    {
        $this->injector->clear();
        parent::tearDown();
    }

    private function loginAs(string $locale): void
    {
        $factory = new FixtureFactory($this->getPropelConnection());
        $admin = $factory->admin(['locale' => $locale]);
        $admin->eraseCredentials();
        $this->injector->setAdmin($admin);
    }

    public function testBannerShowsOnTheAgentsListForAnUncoveredLocale(): void
    {
        $this->loginAs('de_DE');

        $this->assertPageRenders('/admin/module/CommerceAgents/agents');

        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString(self::TESTID, $html);
        self::assertStringContainsString('de_DE', $html);
    }

    public function testBannerShowsOnTheModuleConfigurationPageForAnUncoveredLocale(): void
    {
        $this->loginAs('de_DE');

        $this->assertPageRenders('/admin/module/CommerceAgents');

        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString(self::TESTID, $html);
    }

    public function testBannerIsAbsentForACoveredLocale(): void
    {
        $this->loginAs('fr_FR');

        $this->assertPageRenders('/admin/module/CommerceAgents/agents');

        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString(self::TESTID, $html);
    }

    public function testBannerIsAbsentOnAnUnrelatedAdminScreenEvenForAnUncoveredLocale(): void
    {
        $this->loginAs('de_DE');

        $this->assertPageRenders('/admin/home');

        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString(self::TESTID, $html);
    }
}

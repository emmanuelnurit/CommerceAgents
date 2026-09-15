<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Controller\Admin;

use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;

final class AgentsWizardSmokeTest extends WebIntegrationTestCase
{
    private AdminSessionInjector $injector;

    protected function setUp(): void
    {
        parent::setUp();

        $this->injector = new AdminSessionInjector();
        $this->getService(EventDispatcherInterface::class)->addSubscriber($this->injector);

        $factory = new FixtureFactory($this->getPropelConnection());
        $admin = $factory->admin();
        $admin->eraseCredentials();
        $this->injector->setAdmin($admin);
    }

    protected function tearDown(): void
    {
        $this->injector->clear();
        parent::tearDown();
    }

    public function testNewAgentWizardStillRenders(): void
    {
        $this->assertPageRenders('/admin/module/CommerceAgents/agents/new');

        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('data-testid="agent-preset-code"', $html);
    }

    public function testNewAgentWizardWithPresetPrefillsPresetCode(): void
    {
        $this->assertPageRenders('/admin/module/CommerceAgents/agents/new?preset=cart_abandoned_relaunch');

        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('id="agent-preset-code" name="preset_code" value="cart_abandoned_relaunch"', $html);
    }
}

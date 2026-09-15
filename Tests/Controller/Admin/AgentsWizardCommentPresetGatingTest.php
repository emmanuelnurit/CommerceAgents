<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Controller\Admin;

use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Thelia\Model\Module;
use Thelia\Model\ModuleQuery;
use Thelia\Module\BaseModule;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;

/**
 * MYO-301 acceptance criterion: the "Customer reviews replies" preset is
 * absent/disabled when the "Comment" module is inactive, present and
 * functional when it is active — both cases verified.
 */
final class AgentsWizardCommentPresetGatingTest extends WebIntegrationTestCase
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

    public function testPresetIsHiddenAsBrokenWhenCommentModuleIsNotActive(): void
    {
        // Explicit precondition within this test's own rolled-back transaction:
        // don't assume the shared test database has no "Comment" row already.
        $this->setCommentModuleActivate(BaseModule::IS_NOT_ACTIVATED);

        $this->assertPageRenders('/admin/module/CommerceAgents/agents/new');

        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('data-testid="preset-customer_reviews_reply"', $html);
        self::assertStringContainsString('data-testid="preset-customer_reviews_reply-disabled"', $html);
        self::assertStringContainsString('Requires the &quot;Comment&quot; module to be active', $html);
    }

    public function testPresetIsSelectableWhenCommentModuleIsActive(): void
    {
        $this->setCommentModuleActivate(BaseModule::IS_ACTIVATED);

        $this->assertPageRenders('/admin/module/CommerceAgents/agents/new');

        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('data-testid="preset-customer_reviews_reply"', $html);
        self::assertStringNotContainsString('data-testid="preset-customer_reviews_reply-disabled"', $html);

        $this->assertPageRenders('/admin/module/CommerceAgents/agents/new?preset=customer_reviews_reply');
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('id="agent-preset-code" name="preset_code" value="customer_reviews_reply"', $html);
    }

    private function setCommentModuleActivate(int $activate): void
    {
        $module = ModuleQuery::create()->findOneByCode('Comment');
        if ($module === null) {
            $module = (new Module())
                ->setCode('Comment')
                ->setVersion('1.0.0')
                ->setType(BaseModule::CLASSIC_MODULE_TYPE);
        }

        $module->setActivate($activate)->save();
    }
}

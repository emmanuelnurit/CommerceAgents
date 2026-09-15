<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Controller\Admin;

use CommerceAgents\Model\AgentDefinition;
use CommerceAgents\Model\AgentDefinitionQuery;
use CommerceAgents\Service\AgentDefinitionSeeder;
use CommerceAgents\Service\AgentMemoryManager;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;

/**
 * End-to-end coverage of the "Agents IA" edit page for MYO-280: the prompt
 * preview, the "Memory" tab and its CRUD routes, rendered through the real
 * Twig templates and DI container (not just unit-level string assertions).
 */
final class AgentsControllerTest extends WebIntegrationTestCase
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

    public function testEditPageOfTheShoppingAssistantRendersThePreviewAndTheMemoryTab(): void
    {
        $assistant = $this->requireAssistant(AgentDefinitionSeeder::SHOPPING_CODE);

        $this->assertPageRenders(\sprintf('/admin/module/CommerceAgents/agents/%d/edit', $assistant->getId()));

        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('data-testid="agent-effective-prompt"', $html);
        self::assertStringContainsString('data-testid="agent-role-prompt-reset"', $html);
        self::assertStringContainsString('id="agent-memory"', $html);
        self::assertStringContainsString('data-testid="agent-memory-empty"', $html);
    }

    public function testEditPageOfAConfigurableAgentRendersThePreview(): void
    {
        $agent = $this->createAgentDefinition();

        $this->assertPageRenders(\sprintf('/admin/module/CommerceAgents/agents/%d/edit', $agent->getId()));

        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('data-testid="agent-effective-prompt"', $html);
        self::assertStringContainsString('data-testid="agent-effective-prompt-text"', $html);
    }

    public function testAddingAMemoryEntryThroughTheFormMakesItAppearInTheList(): void
    {
        $agent = $this->createAgentDefinition();
        $token = $this->csrfToken($agent->getId());

        $this->client->request('POST', \sprintf('/admin/module/CommerceAgents/agents/%d/memory/add', $agent->getId()), [
            '_token' => $token,
            'content' => 'The customer prefers email over SMS.',
        ]);

        self::assertSame(302, $this->client->getResponse()->getStatusCode());

        $this->client->followRedirect();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('The customer prefers email over SMS.', $html);
        self::assertStringContainsString('data-testid="agent-memory-table"', $html);
    }

    public function testTogglingAMemoryEntryDisablesIt(): void
    {
        $agent = $this->createAgentDefinition();
        $entry = $this->getService(AgentMemoryManager::class)->create($agent->getId(), 'Toggle me');
        $token = $this->csrfToken($agent->getId());

        $this->client->request('POST', \sprintf('/admin/module/CommerceAgents/agents/%d/memory/%d/toggle', $agent->getId(), $entry->getId()), [
            '_token' => $token,
        ]);

        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        self::assertFalse((bool) $this->getService(AgentMemoryManager::class)->listForAgent($agent->getId())[0]->getEnabled());
    }

    /**
     * MYO-284 B4: the "apply automatically, without review" checkbox was
     * persisted but never read by StagedChangeManager/AgentRunner -- a
     * security-looking control that did nothing. Removed rather than wired
     * up, since auto-applying agent proposals would contradict the
     * maker-checker model MYO-276 (H2/H3) just put in place. Posting the old
     * field name must have no effect any more.
     */
    public function testSavingWithAutoApplyPostedHasNoEffect(): void
    {
        $agent = $this->createAgentDefinition();
        $token = $this->csrfToken($agent->getId());

        $this->client->request('POST', '/admin/module/CommerceAgents/agents/save', [
            '_token' => $token,
            'agent_id' => (string) $agent->getId(),
            'title' => 'Test agent',
            'role_prompt' => 'Watch stock levels.',
            'auto_apply' => '1',
        ]);

        self::assertSame(302, $this->client->getResponse()->getStatusCode());

        $reloaded = AgentDefinitionQuery::create()->findPk($agent->getId());
        self::assertFalse((bool) $reloaded->getAutoApply(), 'auto_apply must never be settable from the form any more');
    }

    public function testEditFormNoLongerRendersTheAutoApplyCheckbox(): void
    {
        $agent = $this->createAgentDefinition();

        $this->assertPageRenders(\sprintf('/admin/module/CommerceAgents/agents/%d/edit', $agent->getId()));

        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('auto_apply', $html);
        self::assertStringNotContainsString('agent-auto-apply', $html);
    }

    public function testDeletingAMemoryEntryRemovesIt(): void
    {
        $agent = $this->createAgentDefinition();
        $entry = $this->getService(AgentMemoryManager::class)->create($agent->getId(), 'Delete me');
        $token = $this->csrfToken($agent->getId());

        $this->client->request('POST', \sprintf('/admin/module/CommerceAgents/agents/%d/memory/%d/delete', $agent->getId(), $entry->getId()), [
            '_token' => $token,
        ]);

        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        self::assertSame([], $this->getService(AgentMemoryManager::class)->listForAgent($agent->getId()));
    }

    private function requireAssistant(string $code): AgentDefinition
    {
        $definition = AgentDefinitionQuery::create()->filterByCode($code)->findOne();
        self::assertNotNull($definition, \sprintf('AgentDefinitionSeeder must have seeded "%s"', $code));

        return $definition;
    }

    private function createAgentDefinition(): AgentDefinition
    {
        $definition = new AgentDefinition();
        $definition
            ->setCode('agent-'.uniqid())
            ->setTitle('Test agent')
            ->setRolePrompt('Watch stock levels.')
            ->setEnabled(1)
            ->save($this->getPropelConnection());

        return $definition;
    }

    private function csrfToken(int $agentId): string
    {
        $this->client->request('GET', \sprintf('/admin/module/CommerceAgents/agents/%d/edit', $agentId));
        $crawler = $this->client->getCrawler();

        return (string) $crawler->filter('#agent-form input[name="_token"]')->attr('value');
    }
}

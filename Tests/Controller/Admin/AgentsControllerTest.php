<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Controller\Admin;

use CommerceAgents\CommerceAgents;
use CommerceAgents\Model\AgentDefinition;
use CommerceAgents\Model\AgentDefinitionQuery;
use CommerceAgents\Model\AgentRun;
use CommerceAgents\Service\AgentDefinitionSeeder;
use CommerceAgents\Service\AgentMemoryManager;
use CommerceAgents\Service\AgentPresets;
use CommerceAgents\Service\ModelCatalog;
use CommerceAgents\Service\Run\AgentRunQueue;
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

    /**
     * MYO-299: `CommerceAgentsModelPicker.init()` was only wired up on the
     * module config page and never called for the agent form, so the model
     * list rendered by `components/model-picker.html.twig` was populated in
     * the DOM but never turned into selectable options. This is the
     * server-rendered half of that contract: the `data-choices` attribute
     * must actually carry the selectable models.
     */
    public function testCreateWizardModelPickerDataChoicesArePopulated(): void
    {
        $this->assertPageRenders('/admin/module/CommerceAgents/agents/new');

        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('data-testid="agent-model"', $html);
        self::assertMatchesRegularExpression('/data-choices="(?!\[\]&quot;|&quot;\[\]&quot;)[^"]+"/', $html);
        self::assertDoesNotMatchRegularExpression('/data-choices="\[\]"/', $html);
    }

    /**
     * MYO-299: the controller used to hard-code `getSelectableModels('mistral')`
     * regardless of the module's configured provider (AgentConfigService::getProvider()).
     * The picker must follow the active provider instead.
     */
    public function testModelPickerFollowsTheConfiguredProviderInsteadOfHardcodedMistral(): void
    {
        $originalProvider = CommerceAgents::getConfigValue('provider', 'mistral');
        CommerceAgents::setConfigValue('provider', 'anthropic');

        try {
            $anthropicModelId = $this->getService(ModelCatalog::class)->getSelectableModels('anthropic')[0]->modelId ?? null;
            self::assertNotNull($anthropicModelId, 'test fixtures must seed at least one selectable anthropic model');

            $this->assertPageRenders('/admin/module/CommerceAgents/agents/new');
            $html = (string) $this->client->getResponse()->getContent();

            self::assertStringContainsString($anthropicModelId, $html);
        } finally {
            CommerceAgents::setConfigValue('provider', $originalProvider);
        }
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

    /**
     * MYO-325: the per-agent page renders a Conversation tab wired to the
     * agent-scoped chat endpoint (not the global merchant chat one) and a
     * Run history tab.
     */
    public function testShowPageRendersConversationAndRunHistoryTabsForAConfigurableAgent(): void
    {
        $agent = $this->createAgentDefinition();

        $this->assertPageRenders(\sprintf('/admin/module/CommerceAgents/agents/%d', $agent->getId()));

        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('data-testid="commerceagents-agent-show-page"', $html);
        self::assertStringContainsString('id="merchant-chat"', $html);
        self::assertStringContainsString(\sprintf('/admin/module/CommerceAgents/agents/%d/chat', $agent->getId()), $html);
        self::assertStringContainsString('data-testid="agent-tab-runs"', $html);
    }

    /**
     * MYO-328: the registry must resolve to the generic pane -- never throw
     * -- for an agent with no preset_code and no granted capabilities
     * (createAgentDefinition() below is exactly that: from_scratch style).
     */
    public function testShowPageOfAnAgentWithNoPresetCodeRendersTheResultsTabWithoutError(): void
    {
        $agent = $this->createAgentDefinition();
        self::assertNull($agent->getPresetCode());

        $this->assertPageRenders(\sprintf('/admin/module/CommerceAgents/agents/%d', $agent->getId()));

        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('data-testid="agent-tab-results"', $html);
        self::assertStringContainsString('data-testid="agent-pane-results"', $html);
        self::assertStringContainsString('data-testid="agent-results-empty"', $html);
    }

    /**
     * MYO-328: a recognized preset_code resolves to its own specialty pane
     * (a stub for this lot) instead of falling through to the generic one.
     */
    public function testShowPageOfAStockWatchAgentRendersItsSpecialtyResultsStub(): void
    {
        $agent = $this->createAgentDefinition();
        $agent->setPresetCode(AgentPresets::STOCK_WATCH_RESTOCK)->save($this->getPropelConnection());

        $this->assertPageRenders(\sprintf('/admin/module/CommerceAgents/agents/%d', $agent->getId()));

        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('data-testid="agent-results-stub"', $html);
    }

    /**
     * MYO-325 §4: a run history that is not a raw log — a typed status per
     * row, and a callout pointing to MYO-324's proposal review screen for the
     * two presets that stage changes (review drafts, restock proposals).
     */
    public function testShowPageOfACustomerReviewsReplyAgentListsItsRunsAndLinksToProposals(): void
    {
        $agent = $this->createAgentDefinition();
        $agent->setPresetCode(AgentPresets::CUSTOMER_REVIEWS_REPLY)->save($this->getPropelConnection());

        $run = new AgentRun();
        $run
            ->setAgentDefinitionId($agent->getId())
            ->setStatus(AgentRunQueue::STATUS_DONE)
            ->setStartedAt(new \DateTimeImmutable('-10 minutes'))
            ->setFinishedAt(new \DateTimeImmutable('-9 minutes'))
            ->setSummary('Replied to 3 reviews.')
            ->save($this->getPropelConnection());

        $this->assertPageRenders(\sprintf('/admin/module/CommerceAgents/agents/%d', $agent->getId()));

        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('data-testid="agent-recent-runs"', $html);
        self::assertStringContainsString('data-testid="agent-proposals-callout"', $html);
        self::assertStringContainsString(\sprintf('/admin/merchant-agent/changes?agentId=%d', $agent->getId()), $html);
    }

    public function testShowPageOfAnAgentWithNoStagedChangesPresetHasNoProposalsCallout(): void
    {
        $agent = $this->createAgentDefinition();

        $this->assertPageRenders(\sprintf('/admin/module/CommerceAgents/agents/%d', $agent->getId()));

        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('data-testid="agent-proposals-callout"', $html);
    }

    public function testShowPageOfAnUnknownAgentReturns404(): void
    {
        $this->client->request('GET', '/admin/module/CommerceAgents/agents/999999');

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testAgentCardOnTheListPageLinksToItsShowPage(): void
    {
        $agent = $this->createAgentDefinition();

        $this->assertPageRenders('/admin/module/CommerceAgents/agents');

        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString(\sprintf('/admin/module/CommerceAgents/agents/%d"', $agent->getId()), $html);
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

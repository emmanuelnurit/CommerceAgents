<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Controller\Admin;

use CommerceAgents\Model\AgentActionLog;
use CommerceAgents\Model\AgentConversation;
use CommerceAgents\Model\AgentDefinition;
use CommerceAgents\Model\AgentRun;
use CommerceAgents\Model\AgentStagedChange;
use CommerceAgents\Service\Run\AgentRunQueue;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;

/**
 * End-to-end coverage of the "Run history" screen (MYO-319/MYO-321): the
 * `agent_run` list with its agent/status filters, and the detail page that
 * surfaces the full summary/error plus anything linked via conversation_id.
 */
final class AgentRunsControllerTest extends WebIntegrationTestCase
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

    public function testListPageShowsRunsMostRecentFirst(): void
    {
        $agent = $this->createAgentDefinition('Antichronological order agent');
        $older = $this->createRun($agent, AgentRunQueue::STATUS_DONE, new \DateTimeImmutable('-2 hours'));
        $newer = $this->createRun($agent, AgentRunQueue::STATUS_DONE, new \DateTimeImmutable('-10 minutes'));

        $this->assertPageRenders('/admin/module/CommerceAgents/agents/runs');

        $html = (string) $this->client->getResponse()->getContent();
        $posNewer = strpos($html, 'data-testid="commerceagents-run-'.$newer->getId().'"');
        $posOlder = strpos($html, 'data-testid="commerceagents-run-'.$older->getId().'"');
        self::assertNotFalse($posNewer);
        self::assertNotFalse($posOlder);
        self::assertLessThan($posOlder, $posNewer, 'the most recently started run must be listed first');
    }

    public function testListPageFiltersByAgent(): void
    {
        $agentA = $this->createAgentDefinition('Agent A');
        $agentB = $this->createAgentDefinition('Agent B');
        $runA = $this->createRun($agentA, AgentRunQueue::STATUS_DONE, new \DateTimeImmutable('-1 hour'));
        $runB = $this->createRun($agentB, AgentRunQueue::STATUS_DONE, new \DateTimeImmutable('-1 hour'));

        $this->assertPageRenders(\sprintf('/admin/module/CommerceAgents/agents/runs?agentId=%d', $agentA->getId()));

        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('data-testid="commerceagents-run-'.$runA->getId().'"', $html);
        self::assertStringNotContainsString('data-testid="commerceagents-run-'.$runB->getId().'"', $html);
    }

    public function testListPageFiltersByStatus(): void
    {
        $agent = $this->createAgentDefinition('Status filter agent');
        $failed = $this->createRun($agent, AgentRunQueue::STATUS_FAILED, new \DateTimeImmutable('-1 hour'));
        $done = $this->createRun($agent, AgentRunQueue::STATUS_DONE, new \DateTimeImmutable('-1 hour'));

        $this->assertPageRenders(\sprintf('/admin/module/CommerceAgents/agents/runs?status=%s', AgentRunQueue::STATUS_FAILED));

        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('data-testid="commerceagents-run-'.$failed->getId().'"', $html);
        self::assertStringNotContainsString('data-testid="commerceagents-run-'.$done->getId().'"', $html);
    }

    public function testDetailPageShowsTheFullErrorOfAFailedRun(): void
    {
        $agent = $this->createAgentDefinition('Failing agent');
        // Longer than the ~150 char excerpt shown on the list page: the
        // detail page must not truncate it (MYO-321 §2 acceptance criterion).
        $longError = trim(str_repeat('boom ', 60));
        $run = $this->createRun($agent, AgentRunQueue::STATUS_FAILED, new \DateTimeImmutable('-5 minutes'), error: $longError);

        $this->assertPageRenders(\sprintf('/admin/module/CommerceAgents/agents/runs/%d', $run->getId()));

        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString($longError, $html);
    }

    public function testDetailPageShowsTheFullSummaryAndLinkedActionsAndChanges(): void
    {
        $agent = $this->createAgentDefinition('Summary agent');
        $conversation = (new AgentConversation())->setType('agent')->setSessionRef('test:'.uniqid('', true));
        $conversation->save($this->getPropelConnection());

        $longSummary = trim(str_repeat('did stuff ', 30));
        $run = $this->createRun($agent, AgentRunQueue::STATUS_DONE, new \DateTimeImmutable('-5 minutes'), summary: $longSummary, conversationId: $conversation->getId());

        (new AgentActionLog())
            ->setToolName('get_customer_orders')
            ->setStatus('success')
            ->setChannel('agent')
            ->setConversationId($conversation->getId())
            ->setAgentDefinitionId($agent->getId())
            ->setArguments(json_encode(['customerId' => 42], \JSON_THROW_ON_ERROR))
            ->save($this->getPropelConnection());

        (new AgentStagedChange())
            ->setConversationId($conversation->getId())
            ->setAgentDefinitionId($agent->getId())
            ->setAdminId(1)
            ->setTargetType('pse_stock')
            ->setTargetId(7)
            ->setPayloadBefore(json_encode(['quantity' => 3], \JSON_THROW_ON_ERROR))
            ->setPayloadAfter(json_encode(['quantity' => 10], \JSON_THROW_ON_ERROR))
            ->save($this->getPropelConnection());

        $this->assertPageRenders(\sprintf('/admin/module/CommerceAgents/agents/runs/%d', $run->getId()));

        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString($longSummary, $html);
        self::assertStringContainsString('data-testid="commerceagents-run-action-logs"', $html);
        self::assertStringContainsString('get_customer_orders', $html);
        self::assertStringContainsString('data-testid="commerceagents-run-staged-changes"', $html);
        self::assertStringContainsString('pse_stock', $html);
    }

    private function createAgentDefinition(string $title): AgentDefinition
    {
        $definition = new AgentDefinition();
        $definition
            ->setCode('agent-'.uniqid('', true))
            ->setTitle($title)
            ->setRolePrompt('Test agent.')
            ->setEnabled(1)
            ->save($this->getPropelConnection());

        return $definition;
    }

    private function createRun(
        AgentDefinition $agent,
        string $status,
        \DateTimeImmutable $startedAt,
        ?string $summary = null,
        ?string $error = null,
        ?int $conversationId = null,
    ): AgentRun {
        $run = new AgentRun();
        $run
            ->setAgentDefinitionId($agent->getId())
            ->setStatus($status)
            ->setStartedAt(\DateTime::createFromImmutable($startedAt))
            ->setFinishedAt(\DateTime::createFromImmutable($startedAt->modify('+30 seconds')))
            ->setSummary($summary)
            ->setError($error)
            ->setConversationId($conversationId);
        $run->save($this->getPropelConnection());

        return $run;
    }
}

<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Service;

use CommerceAgents\Agent\Llm\ModelDiscovery;
use CommerceAgents\Model\AgentTriggerQuery;
use CommerceAgents\Service\AgentDefinitionManager;
use CommerceAgents\Service\Locale\AssistantLocaleResolver;
use CommerceAgents\Service\ModelCatalog;
use CommerceAgents\Service\Run\AbandonedCartFinder;
use CommerceAgents\Service\Run\AgentRunQueue;
use CommerceAgents\Service\Run\LowStockFinder;
use CommerceAgents\Service\TriggerCatalog;
use CommerceAgents\Tests\Service\Locale\FakeSiteDefaultLocaleProvider;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpFoundation\RequestStack;
use Thelia\Core\Translation\Translator;
use Thelia\Test\IntegrationTestCase;

/**
 * MYO-304: replaceTriggers() must seed agent_trigger.next_run_at at creation
 * (and on any cron_expression change, since it always recreates the row) or
 * a fresh cron trigger is never picked up by
 * AgentRunQueue::enqueueDueCronRuns() -- dueTriggers() only matches rows
 * where next_run_at <= now, which a NULL column never satisfies.
 */
class AgentDefinitionManagerTest extends IntegrationTestCase
{
    private AgentDefinitionManager $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = new AgentDefinitionManager(
            new ModelCatalog(new ModelDiscovery(new MockHttpClient()), new Translator(new RequestStack())),
            new TriggerCatalog(new Translator(new RequestStack())),
            new AgentRunQueue(
                new NullLogger(),
                new AbandonedCartFinder(),
                new LowStockFinder(),
                new AssistantLocaleResolver(new FakeSiteDefaultLocaleProvider('en_US')),
            ),
            new NullLogger(),
        );
    }

    public function testSaveSeedsNextRunAtForAFreshCronTrigger(): void
    {
        $before = new \DateTimeImmutable();

        $definition = $this->manager->save(null, $this->data([
            ['type' => 'cron', 'cronExpression' => '0 9 * * *'],
        ]));

        $trigger = AgentTriggerQuery::create()->filterByAgentDefinitionId($definition->getId())->findOne();

        self::assertNotNull($trigger->getNextRunAt(), 'a freshly created cron trigger must have next_run_at seeded, not NULL');
        self::assertGreaterThan($before, $trigger->getNextRunAt());
    }

    public function testSaveRecomputesNextRunAtWhenTheCronExpressionChanges(): void
    {
        $definition = $this->manager->save(null, $this->data([
            ['type' => 'cron', 'cronExpression' => '0 9 * * *'],
        ]));
        $firstNextRunAt = AgentTriggerQuery::create()->filterByAgentDefinitionId($definition->getId())->findOne()->getNextRunAt();

        $this->manager->save($definition->getId(), $this->data([
            ['type' => 'cron', 'cronExpression' => '30 14 * * *'],
        ]));
        $secondNextRunAt = AgentTriggerQuery::create()->filterByAgentDefinitionId($definition->getId())->findOne()->getNextRunAt();

        self::assertNotEquals($firstNextRunAt->format('H:i'), $secondNextRunAt->format('H:i'));
    }

    public function testSaveLeavesNextRunAtNullForANonCronTrigger(): void
    {
        $definition = $this->manager->save(null, $this->data([
            ['type' => 'event', 'eventName' => 'action.order.pay'],
        ]));

        $trigger = AgentTriggerQuery::create()->filterByAgentDefinitionId($definition->getId())->findOne();

        self::assertNull($trigger->getNextRunAt());
    }

    /**
     * @param list<array{type: string, cronExpression?: ?string, eventName?: ?string, conditions?: ?array<string, mixed>}> $triggers
     *
     * @return array<string, mixed>
     */
    private function data(array $triggers): array
    {
        return [
            'title' => 'Test agent '.uniqid(),
            'description' => '',
            'rolePrompt' => '',
            'model' => '',
            'monthlyBudgetUsd' => null,
            'enabled' => true,
            'capabilities' => [],
            'triggers' => $triggers,
            'channels' => [],
        ];
    }
}

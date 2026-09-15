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
     * MYO-421: save() used to hard-code the provider to 'mistral' as soon as
     * a model was chosen, whatever provider that model actually belonged to
     * -- an agent set to run on Claude would silently execute on the shop's
     * Mistral credentials instead (or fail outright without a Mistral key).
     */
    public function testSavePersistsTheProviderOfANonMistralModel(): void
    {
        $definition = $this->manager->save(null, $this->data([], model: 'claude-sonnet-5', provider: 'anthropic'));

        self::assertSame('anthropic', $definition->getProvider());
        self::assertSame('claude-sonnet-5', $definition->getModel());
    }

    /**
     * A forged/unknown provider value must never be persisted verbatim: it
     * falls back to the module default, same as before this fix.
     */
    public function testSaveFallsBackToTheDefaultProviderWhenTheSubmittedOneIsUnknown(): void
    {
        $definition = $this->manager->save(null, $this->data([], model: 'ministral-3b-latest', provider: 'not-a-real-provider'));

        self::assertSame('mistral', $definition->getProvider());
    }

    /**
     * @param list<array{type: string, cronExpression?: ?string, eventName?: ?string, conditions?: ?array<string, mixed>}> $triggers
     *
     * @return array<string, mixed>
     */
    private function data(array $triggers, string $model = '', ?string $provider = null): array
    {
        return [
            'title' => 'Test agent '.uniqid(),
            'description' => '',
            'rolePrompt' => '',
            'model' => $model,
            'provider' => $provider,
            'monthlyBudgetUsd' => null,
            'enabled' => true,
            'capabilities' => [],
            'triggers' => $triggers,
            'channels' => [],
        ];
    }
}

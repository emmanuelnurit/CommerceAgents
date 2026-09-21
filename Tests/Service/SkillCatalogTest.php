<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Service;

use CommerceAgents\Agent\Llm\ModelDiscovery;
use CommerceAgents\Model\AgentConversation;
use CommerceAgents\Model\AgentDefinitionQuery;
use CommerceAgents\Model\AgentMessage;
use CommerceAgents\Model\AgentRun;
use CommerceAgents\Model\AgentStagedChange;
use CommerceAgents\Service\AgentDefinitionManager;
use CommerceAgents\Service\AgentPresets;
use CommerceAgents\Service\AgentSpendRepository;
use CommerceAgents\Service\Locale\AssistantLocaleResolver;
use CommerceAgents\Service\ModelCatalog;
use CommerceAgents\Service\Run\AbandonedCartFinder;
use CommerceAgents\Service\Run\AgentRunQueue;
use CommerceAgents\Service\Run\LowStockFinder;
use CommerceAgents\Service\SkillCatalog;
use CommerceAgents\Service\TriggerCatalog;
use CommerceAgents\StagedChange\StagedChangeData;
use CommerceAgents\Tests\Service\Locale\FakeSiteDefaultLocaleProvider;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Thelia\Core\Translation\Translator;
use Thelia\Test\IntegrationTestCase;

/**
 * MYO-506: `state`, `acceptanceRate` and `costPerProposalEur` must all be
 * derived from real rows (`agent_definition`, `agent_staged_change`,
 * `agent_message`) -- never a hardcoded number (AC2/AC3/AC4). A `soon` skill
 * must never become activable just because its code happens to be passed in.
 */
class SkillCatalogTest extends IntegrationTestCase
{
    private SkillCatalog $catalog;

    protected function setUp(): void
    {
        parent::setUp();

        $definitionManager = new AgentDefinitionManager(
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

        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturnCallback(
            static fn (string $name, array $params = []): string => '/'.$name.'/'.($params['id'] ?? ''),
        );

        $this->catalog = new SkillCatalog($definitionManager, new AgentSpendRepository(), $urlGenerator);
    }

    public function testSoonSkillIsNeverActivable(): void
    {
        $soon = self::findRow($this->catalog->all(), 'campaign_proposal');

        self::assertSame('soon', $soon['state']);
        self::assertNull($soon['agentDefinitionId']);
        self::assertNull($soon['editUrl']);

        $this->expectException(\RuntimeException::class);
        $this->catalog->activate('campaign_proposal');
    }

    public function testActivateCreatesAgentDefinitionFromPresetAndIsIdempotent(): void
    {
        $code = AgentPresets::STUCK_ORDERS_WATCH;

        $this->catalog->activate($code);
        $first = self::findRow($this->catalog->all(), $code);
        self::assertSame('active', $first['state']);
        self::assertNotNull($first['agentDefinitionId']);
        self::assertSame('/commerceagents_agents_edit/'.$first['agentDefinitionId'], $first['editUrl']);

        // Idempotent: calling it again must not create a second agent_definition.
        $this->catalog->activate($code);
        self::assertSame(1, AgentDefinitionQuery::create()->filterByPresetCode($code)->count());
    }

    public function testDeactivateDisablesWithoutDeletingHistory(): void
    {
        $code = AgentPresets::CUSTOMER_REVIEWS_REPLY;
        $this->catalog->activate($code);
        $definitionId = self::findRow($this->catalog->all(), $code)['agentDefinitionId'];

        $this->catalog->deactivate($code);
        $inactiveRow = self::findRow($this->catalog->all(), $code);

        self::assertSame('inactive', $inactiveRow['state']);
        self::assertSame($definitionId, $inactiveRow['agentDefinitionId'], 'the agent_definition row must still exist, just disabled');

        // Idempotent too.
        $this->catalog->deactivate($code);
        self::assertSame('inactive', self::findRow($this->catalog->all(), $code)['state']);
    }

    public function testAcceptanceRateAggregatesRealAppliedAndRejectedStagedChanges(): void
    {
        $code = AgentPresets::CART_ABANDONED;
        $this->catalog->activate($code);
        $definitionId = self::findRow($this->catalog->all(), $code)['agentDefinitionId'];

        $conversation = (new AgentConversation())->setType('run');
        $conversation->save();

        self::stageChange($conversation->getId(), $definitionId, StagedChangeData::STATUS_APPLIED);
        self::stageChange($conversation->getId(), $definitionId, StagedChangeData::STATUS_APPLIED);
        self::stageChange($conversation->getId(), $definitionId, StagedChangeData::STATUS_APPLIED);
        self::stageChange($conversation->getId(), $definitionId, StagedChangeData::STATUS_REJECTED);
        self::stageChange($conversation->getId(), $definitionId, StagedChangeData::STATUS_PENDING); // must not count

        $row = self::findRow($this->catalog->all(), $code);
        self::assertSame(0.75, $row['acceptanceRate']);
    }

    public function testAggregatesAreNullWithoutAnyStagedChangeHistory(): void
    {
        $code = AgentPresets::PRODUCT_SHEET_AUDIT;
        $this->catalog->activate($code);

        $row = self::findRow($this->catalog->all(), $code);
        self::assertNull($row['acceptanceRate']);
        self::assertNull($row['costPerProposalEur']);
    }

    public function testCostPerProposalEurAggregatesRealSpendAcrossProposals(): void
    {
        $code = AgentPresets::STOCK_WATCH_RESTOCK;
        $this->catalog->activate($code);
        $definitionId = self::findRow($this->catalog->all(), $code)['agentDefinitionId'];

        $conversation = (new AgentConversation())->setType('run');
        $conversation->save();

        $run = (new AgentRun())
            ->setAgentDefinitionId($definitionId)
            ->setConversationId($conversation->getId())
            ->setStatus(AgentRunQueue::STATUS_DONE);
        $run->save();

        (new AgentMessage())->setConversationId($conversation->getId())->setRole('assistant')->setCost('2.000000')->save();
        (new AgentMessage())->setConversationId($conversation->getId())->setRole('assistant')->setCost('2.000000')->save();

        self::stageChange($conversation->getId(), $definitionId, StagedChangeData::STATUS_APPLIED);
        self::stageChange($conversation->getId(), $definitionId, StagedChangeData::STATUS_REJECTED);

        $row = self::findRow($this->catalog->all(), $code);
        // (2 + 2) USD lifetime spend / 2 proposals = 2 USD/proposal, converted with the fixed catalog rate (MYO-489/495).
        self::assertSame(round(2.0 * ModelCatalog::usdToEurRate(), 4), $row['costPerProposalEur']);
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return array<string, mixed>
     */
    private static function findRow(array $rows, string $code): array
    {
        foreach ($rows as $row) {
            if ($row['code'] === $code) {
                return $row;
            }
        }

        self::fail(\sprintf('No skill row for code "%s"', $code));
    }

    private static function stageChange(int $conversationId, int $agentDefinitionId, string $status): void
    {
        $change = (new AgentStagedChange())
            ->setConversationId($conversationId)
            ->setAgentDefinitionId($agentDefinitionId)
            ->setTargetType('customer_email')
            ->setTargetId(1)
            ->setStatus($status);
        $change->save();
    }
}

<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Service\SpecialtyPane;

use CommerceAgents\Model\AgentConversation;
use CommerceAgents\Model\AgentDefinition;
use CommerceAgents\Model\AgentStagedChange;
use CommerceAgents\Service\SpecialtyPane\StockWatchRestockResultsPane;
use CommerceAgents\StagedChange\StagedChangeData;
use Thelia\Test\IntegrationTestCase;

/**
 * MYO-336 lot 2 / MYO-338: the real stock-watch-restock Results pane, built
 * from agent_staged_change rows (target_type = pse_stock) -- never the
 * "_specialty_stub.html.twig" placeholder.
 */
final class StockWatchRestockResultsPaneTest extends IntegrationTestCase
{
    private function definition(): AgentDefinition
    {
        $definition = (new AgentDefinition())
            ->setCode('stock-watch-test-'.uniqid('', true))
            ->setTitle('Stock watch test agent');
        $definition->save();

        return $definition;
    }

    private function stagedChange(
        ?AgentDefinition $definition,
        string $targetType,
        array $payloadBefore,
        array $payloadAfter,
        string $status = StagedChangeData::STATUS_PENDING,
    ): AgentStagedChange {
        $conversation = (new AgentConversation())->setType('merchant');
        $conversation->save();

        $change = (new AgentStagedChange())
            ->setConversationId($conversation->getId())
            ->setAgentDefinitionId($definition?->getId())
            ->setAdminId(1)
            ->setTargetType($targetType)
            ->setTargetId(random_int(1, 1_000_000))
            ->setPayloadBefore(json_encode($payloadBefore, \JSON_THROW_ON_ERROR))
            ->setPayloadAfter(json_encode($payloadAfter, \JSON_THROW_ON_ERROR))
            ->setStatus($status);
        $change->save();

        return $change;
    }

    public function testProposalIncludesTheStockChangeAndStatus(): void
    {
        $definition = $this->definition();
        $change = $this->stagedChange(
            $definition,
            'pse_stock',
            ['pseRef' => 'SKU-42', 'quantity' => 3],
            ['quantity' => 25],
            StagedChangeData::STATUS_APPLIED,
        );

        $data = (new StockWatchRestockResultsPane())->getViewData($definition);

        $this->assertCount(1, $data['proposals']);
        $proposal = $data['proposals'][0];
        $this->assertSame($change->getId(), $proposal['id']);
        $this->assertSame('SKU-42', $proposal['pseRef']);
        $this->assertSame(3, $proposal['quantityBefore']);
        $this->assertSame(25, $proposal['quantityAfter']);
        $this->assertSame('applied', $proposal['status']);
    }

    public function testEmptyStateWhenTheAgentHasNoProposal(): void
    {
        $definition = $this->definition();

        $data = (new StockWatchRestockResultsPane())->getViewData($definition);

        $this->assertSame([], $data['proposals']);
        $this->assertSame(0, $data['totalCount']);
    }

    public function testMissingPayloadKeysFallBackToNullRatherThanCrashing(): void
    {
        $definition = $this->definition();
        $this->stagedChange($definition, 'pse_stock', [], []);

        $data = (new StockWatchRestockResultsPane())->getViewData($definition);

        $this->assertCount(1, $data['proposals']);
        $this->assertNull($data['proposals'][0]['pseRef']);
        $this->assertNull($data['proposals'][0]['quantityBefore']);
        $this->assertNull($data['proposals'][0]['quantityAfter']);
    }

    public function testIgnoresChangesFromAnotherAgent(): void
    {
        $definition = $this->definition();
        $otherDefinition = $this->definition();
        $this->stagedChange($otherDefinition, 'pse_stock', ['pseRef' => 'SKU-1', 'quantity' => 0], ['quantity' => 10]);

        $data = (new StockWatchRestockResultsPane())->getViewData($definition);

        $this->assertSame([], $data['proposals']);
    }

    public function testIgnoresChangesOfAnotherTargetType(): void
    {
        $definition = $this->definition();
        $this->stagedChange($definition, 'pse_price', ['price' => 10], ['price' => 12]);

        $data = (new StockWatchRestockResultsPane())->getViewData($definition);

        $this->assertSame([], $data['proposals']);
    }

    public function testCapsAtTwentyMostRecentProposalsAndReportsTheTotal(): void
    {
        $definition = $this->definition();
        for ($i = 0; $i < 21; ++$i) {
            $this->stagedChange($definition, 'pse_stock', ['pseRef' => 'SKU-'.$i, 'quantity' => $i], ['quantity' => $i + 1]);
        }

        $data = (new StockWatchRestockResultsPane())->getViewData($definition);

        $this->assertCount(20, $data['proposals']);
        $this->assertSame(21, $data['totalCount']);
        $this->assertSame(20, $data['maxProposals']);
    }
}

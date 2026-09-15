<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Service;

use CommerceAgents\Model\AgentDefinition;
use CommerceAgents\Service\AgentMemoryManager;
use Thelia\Test\IntegrationTestCase;

/**
 * CRUD + cap preview of the "Memory" tab (plan MYO-280 §2). Real fixtures,
 * real Propel queries so the acceptance criterion "ces entrées se retrouvent
 * dans le prompt envoyé au modèle (vérifié par test)" is exercised end to
 * end together with SystemPromptFactoryTest.
 */
class AgentMemoryManagerTest extends IntegrationTestCase
{
    private AgentMemoryManager $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = new AgentMemoryManager();
    }

    public function testCreateThenListForAgentReturnsTheEntryNewestFirst(): void
    {
        $agent = $this->createAgentDefinition();

        $this->manager->create($agent->getId(), 'First note');
        $this->manager->create($agent->getId(), 'Second note');

        $entries = $this->manager->listForAgent($agent->getId());

        self::assertCount(2, $entries);
        self::assertSame('Second note', $entries[0]->getContent());
        self::assertSame(AgentMemoryManager::SOURCE_MANUAL, $entries[0]->getSource());
        self::assertTrue((bool) $entries[0]->getEnabled());
    }

    public function testCreateTruncatesContentToTheCharacterCap(): void
    {
        $agent = $this->createAgentDefinition();
        $tooLong = str_repeat('a', AgentMemoryManager::MAX_ENTRY_CHARS + 500);

        $entry = $this->manager->create($agent->getId(), $tooLong);

        self::assertSame(AgentMemoryManager::MAX_ENTRY_CHARS, mb_strlen((string) $entry->getContent()));
    }

    public function testUpdateChangesContentAndIsScopedToTheAgent(): void
    {
        $agent = $this->createAgentDefinition();
        $otherAgent = $this->createAgentDefinition();
        $entry = $this->manager->create($agent->getId(), 'Original');

        $this->manager->update($agent->getId(), $entry->getId(), 'Updated');

        $reloaded = $this->manager->listForAgent($agent->getId())[0];
        self::assertSame('Updated', $reloaded->getContent());

        $this->expectException(\RuntimeException::class);
        $this->manager->update($otherAgent->getId(), $entry->getId(), 'Hijacked');
    }

    public function testToggleFlipsEnabled(): void
    {
        $agent = $this->createAgentDefinition();
        $entry = $this->manager->create($agent->getId(), 'Toggle me');
        self::assertTrue((bool) $entry->getEnabled());

        $this->manager->toggle($agent->getId(), $entry->getId());
        self::assertFalse((bool) $this->manager->listForAgent($agent->getId())[0]->getEnabled());

        $this->manager->toggle($agent->getId(), $entry->getId());
        self::assertTrue((bool) $this->manager->listForAgent($agent->getId())[0]->getEnabled());
    }

    public function testDeleteRemovesTheEntry(): void
    {
        $agent = $this->createAgentDefinition();
        $entry = $this->manager->create($agent->getId(), 'Gone soon');

        $this->manager->delete($agent->getId(), $entry->getId());

        self::assertSame([], $this->manager->listForAgent($agent->getId()));
    }

    public function testActiveContentsExcludesDisabledEntries(): void
    {
        $agent = $this->createAgentDefinition();
        $this->manager->create($agent->getId(), 'Active note');
        $disabled = $this->manager->create($agent->getId(), 'Disabled note');
        $this->manager->toggle($agent->getId(), $disabled->getId());

        $contents = $this->manager->activeContents($agent->getId());

        self::assertSame(['Active note'], $contents);
    }

    public function testPromptCoverageMirrorsTheSystemPromptFactoryCap(): void
    {
        $agent = $this->createAgentDefinition();
        $this->manager->create($agent->getId(), 'Only entry');

        $coverage = $this->manager->promptCoverage($agent->getId());

        self::assertSame(1, $coverage['includedCount']);
        self::assertSame(1, $coverage['totalCount']);
        self::assertSame(0, $coverage['droppedCount']);
    }

    private function createAgentDefinition(): AgentDefinition
    {
        $definition = new AgentDefinition();
        $definition
            ->setCode('agent-'.uniqid())
            ->setTitle('Test agent')
            ->setEnabled(1)
            ->save($this->getPropelConnection());

        return $definition;
    }
}

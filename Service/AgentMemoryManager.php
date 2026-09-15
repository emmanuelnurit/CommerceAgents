<?php

declare(strict_types=1);

namespace CommerceAgents\Service;

use CommerceAgents\Model\AgentMemory;
use CommerceAgents\Model\AgentMemoryQuery;
use Propel\Runtime\ActiveQuery\Criteria;

/**
 * CRUD for agent_memory (plan MYO-280 §2): the "Memory" tab of the agent
 * configuration panel reads and writes through this class, and
 * SystemPromptFactory::capMemory() is the single source of truth for how
 * many entries make it into a run's prompt.
 */
final readonly class AgentMemoryManager
{
    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_AGENT = 'agent';

    /** Stored content longer than this is truncated before saving (form field carries the same cap). */
    public const MAX_ENTRY_CHARS = 2000;

    /**
     * All entries for the agent, including disabled ones, newest first —
     * what the "Memory" tab lists (plan §2: "afficher son origine et sa date").
     *
     * @return list<AgentMemory>
     */
    public function listForAgent(int $agentDefinitionId): array
    {
        return AgentMemoryQuery::create()
            ->filterByAgentDefinitionId($agentDefinitionId)
            ->orderByCreatedAt(Criteria::DESC)
            ->orderById(Criteria::DESC)
            ->find()
            ->getData();
    }

    /**
     * Content of the enabled entries, newest first, uncapped: the caller
     * (SystemPromptFactory::capMemory) decides how many survive the prompt's
     * size budget.
     *
     * @return list<string>
     */
    public function activeContents(int $agentDefinitionId): array
    {
        $entries = AgentMemoryQuery::create()
            ->filterByAgentDefinitionId($agentDefinitionId)
            ->filterByEnabled(1)
            ->orderByCreatedAt(Criteria::DESC)
            ->orderById(Criteria::DESC)
            ->find()
            ->getData();

        return array_map(static fn (AgentMemory $entry): string => (string) $entry->getContent(), $entries);
    }

    /**
     * Cap preview for the back office: how many of the currently active
     * entries would actually reach the model right now, mirroring
     * SystemPromptFactory::capMemory() exactly.
     *
     * @return array{includedCount: int, totalCount: int, droppedCount: int}
     */
    public function promptCoverage(int $agentDefinitionId): array
    {
        $cap = SystemPromptFactory::capMemory($this->activeContents($agentDefinitionId));

        return [
            'includedCount' => $cap['includedCount'],
            'totalCount' => $cap['totalCount'],
            'droppedCount' => $cap['droppedCount'],
        ];
    }

    public function create(int $agentDefinitionId, string $content, string $source = self::SOURCE_MANUAL): AgentMemory
    {
        $entry = (new AgentMemory())
            ->setAgentDefinitionId($agentDefinitionId)
            ->setContent(self::capContent($content))
            ->setSource(\in_array($source, [self::SOURCE_MANUAL, self::SOURCE_AGENT], true) ? $source : self::SOURCE_MANUAL)
            ->setEnabled(1);
        $entry->save();

        return $entry;
    }

    public function update(int $agentDefinitionId, int $id, string $content): AgentMemory
    {
        $entry = $this->requireEntry($agentDefinitionId, $id);
        $entry->setContent(self::capContent($content));
        $entry->save();

        return $entry;
    }

    public function toggle(int $agentDefinitionId, int $id): AgentMemory
    {
        $entry = $this->requireEntry($agentDefinitionId, $id);
        $entry->setEnabled($entry->getEnabled() ? 0 : 1);
        $entry->save();

        return $entry;
    }

    public function delete(int $agentDefinitionId, int $id): void
    {
        $this->requireEntry($agentDefinitionId, $id)->delete();
    }

    private function requireEntry(int $agentDefinitionId, int $id): AgentMemory
    {
        $entry = AgentMemoryQuery::create()
            ->filterByAgentDefinitionId($agentDefinitionId)
            ->findPk($id);

        if ($entry === null) {
            throw new \RuntimeException(\sprintf('Memory entry %d not found for agent %d', $id, $agentDefinitionId));
        }

        return $entry;
    }

    private static function capContent(string $content): string
    {
        return mb_substr(trim($content), 0, self::MAX_ENTRY_CHARS);
    }
}

<?php

declare(strict_types=1);

namespace CommerceAgents\Service\Merchant;

use CommerceAgents\Model\AgentStagedChange;
use CommerceAgents\Model\AgentStagedChangeQuery;
use CommerceAgents\StagedChange\StagedChangeData;
use CommerceAgents\StagedChange\StagedChangeRepositoryInterface;
use Propel\Runtime\ActiveQuery\Criteria;

final readonly class TheliaStagedChangeRepository implements StagedChangeRepositoryInterface
{
    public function find(int $id): ?StagedChangeData
    {
        $model = AgentStagedChangeQuery::create()->findPk($id);

        return $model !== null ? $this->toData($model) : null;
    }

    /**
     * @return array[] raw rows for the approval console, newest first, optionally
     *                 scoped to one agent (MYO-324 §4) so an agent-specific page can reuse it
     */
    public function findRecent(int $limit = 50, ?int $agentDefinitionId = null): array
    {
        $query = AgentStagedChangeQuery::create()->orderById(Criteria::DESC);
        if ($agentDefinitionId !== null) {
            $query->filterByAgentDefinitionId($agentDefinitionId);
        }

        $rows = [];
        foreach ($query->limit($limit)->find() as $model) {
            $rows[] = [
                'id' => $model->getId(),
                'targetType' => $model->getTargetType(),
                'targetId' => $model->getTargetId(),
                'agentDefinitionId' => $model->getAgentDefinitionId(),
                'agentTitle' => $model->getAgentDefinition()?->getTitle(),
                'payloadBefore' => json_decode((string) $model->getPayloadBefore(), true) ?? [],
                'payloadAfter' => json_decode((string) $model->getPayloadAfter(), true) ?? [],
                'status' => $model->getStatus(),
                'adminId' => $model->getAdminId(),
                'approvedBy' => $model->getApprovedBy(),
                'error' => $model->getError(),
                'createdAt' => $model->getCreatedAt()?->format('Y-m-d H:i'),
            ];
        }

        return $rows;
    }

    /**
     * Overwrites the "after" payload of a still-pending change (MYO-324 §2): lets
     * the merchant amend an agent's draft (e.g. a review reply text) before
     * approving it, without changing StagedChangeManager's approve contract.
     */
    public function updatePayloadAfter(int $id, array $payloadAfter): void
    {
        $model = AgentStagedChangeQuery::create()->findPk($id);
        if ($model === null) {
            return;
        }

        $model->setPayloadAfter(json_encode($payloadAfter, \JSON_THROW_ON_ERROR))->save();
    }

    /**
     * Pending suggestions for one agent's dashboard popup (MYO-237 §5): scoped
     * to the v1 contract (pending pse_price/pse_stock only), newest first.
     *
     * @return array[] raw rows for the suggestions popup
     */
    public function findPendingForAgent(int $agentDefinitionId, int $limit = 20): array
    {
        $rows = [];
        foreach (
            AgentStagedChangeQuery::create()
                ->filterByAgentDefinitionId($agentDefinitionId)
                ->filterByStatus(StagedChangeData::STATUS_PENDING)
                ->orderByCreatedAt(Criteria::DESC)
                ->limit($limit)
                ->find() as $model
        ) {
            $rows[] = [
                'id' => $model->getId(),
                'targetType' => $model->getTargetType(),
                'targetId' => $model->getTargetId(),
                'payloadBefore' => json_decode((string) $model->getPayloadBefore(), true) ?? [],
                'payloadAfter' => json_decode((string) $model->getPayloadAfter(), true) ?? [],
                'createdAt' => $model->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            ];
        }

        return $rows;
    }

    public function countPendingForAgent(int $agentDefinitionId): int
    {
        return AgentStagedChangeQuery::create()
            ->filterByAgentDefinitionId($agentDefinitionId)
            ->filterByStatus(StagedChangeData::STATUS_PENDING)
            ->count();
    }

    public function markApplied(int $id, int $approvedBy): void
    {
        $this->mark($id, StagedChangeData::STATUS_APPLIED, $approvedBy, applied: true);
    }

    public function markRejected(int $id, int $approvedBy): void
    {
        $this->mark($id, StagedChangeData::STATUS_REJECTED, $approvedBy);
    }

    public function markFailed(int $id, int $approvedBy, string $error): void
    {
        $this->mark($id, StagedChangeData::STATUS_FAILED, $approvedBy, error: $error);
    }

    private function mark(int $id, string $status, int $approvedBy, bool $applied = false, ?string $error = null): void
    {
        $model = AgentStagedChangeQuery::create()->findPk($id);
        if ($model === null) {
            return;
        }

        $model->setStatus($status)
            ->setApprovedBy($approvedBy)
            ->setApprovedAt(new \DateTime());

        if ($applied) {
            $model->setAppliedAt(new \DateTime());
        }
        if ($error !== null) {
            $model->setError($error);
        }

        $model->save();
    }

    private function toData(AgentStagedChange $model): StagedChangeData
    {
        $createdAt = $model->getCreatedAt();

        return new StagedChangeData(
            id: $model->getId(),
            targetType: $model->getTargetType(),
            targetId: $model->getTargetId(),
            payloadBefore: json_decode((string) $model->getPayloadBefore(), true) ?? [],
            payloadAfter: json_decode((string) $model->getPayloadAfter(), true) ?? [],
            status: $model->getStatus(),
            proposedBy: $model->getAdminId(),
            createdAt: $createdAt instanceof \DateTimeInterface ? \DateTimeImmutable::createFromInterface($createdAt) : null,
        );
    }
}

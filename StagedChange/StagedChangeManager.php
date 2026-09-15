<?php

declare(strict_types=1);

namespace CommerceAgents\StagedChange;

use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;

final readonly class StagedChangeManager
{
    /** @var array<string, ChangeApplierInterface> */
    private array $appliers;

    /**
     * @param iterable<ChangeApplierInterface> $appliers
     */
    public function __construct(
        private StagedChangeRepositoryInterface $repository,
        #[TaggedIterator('commerce_agents.change_applier')] iterable $appliers = [],
    ) {
        $indexed = [];
        foreach ($appliers as $applier) {
            $indexed[$applier->getTargetType()] = $applier;
        }
        $this->appliers = $indexed;
    }

    /**
     * @return array{status?: string, error?: string}
     */
    public function approve(int $changeId, int $adminId): array
    {
        $change = $this->repository->find($changeId);
        if ($change === null) {
            return ['error' => \sprintf('Staged change %d not found', $changeId)];
        }
        if ($change->status !== StagedChangeData::STATUS_PENDING) {
            return ['error' => \sprintf('Staged change %d is not pending (status: %s)', $changeId, $change->status)];
        }
        if ($change->proposedBy !== null && $change->proposedBy === $adminId) {
            return ['error' => \sprintf('Staged change %d was proposed by this admin and cannot be self-approved', $changeId)];
        }

        $applier = $this->appliers[$change->targetType] ?? null;
        if ($applier === null) {
            return ['error' => \sprintf('No applier registered for target type "%s"', $change->targetType)];
        }

        try {
            $applier->apply($change);
        } catch (\RuntimeException $exception) {
            $this->repository->markFailed($changeId, $adminId, $exception->getMessage());

            return ['status' => StagedChangeData::STATUS_FAILED, 'error' => $exception->getMessage()];
        }

        $this->repository->markApplied($changeId, $adminId);

        return ['status' => StagedChangeData::STATUS_APPLIED];
    }

    /**
     * @return array{status?: string, error?: string}
     */
    public function reject(int $changeId, int $adminId): array
    {
        $change = $this->repository->find($changeId);
        if ($change === null) {
            return ['error' => \sprintf('Staged change %d not found', $changeId)];
        }
        if ($change->status !== StagedChangeData::STATUS_PENDING) {
            return ['error' => \sprintf('Staged change %d is not pending (status: %s)', $changeId, $change->status)];
        }

        $this->repository->markRejected($changeId, $adminId);

        return ['status' => StagedChangeData::STATUS_REJECTED];
    }
}

<?php

declare(strict_types=1);

namespace CommerceAgents\StagedChange;

use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;

final readonly class StagedChangeManager
{
    /**
     * A pending proposal older than this can no longer be approved: the
     * catalog state it was computed from is presumed stale (MYO-284 M5).
     */
    private const PENDING_MAX_AGE_HOURS = 24;

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
        if ($this->isExpired($change)) {
            $error = \sprintf(
                'Staged change %d has been pending for more than %d hours and can no longer be approved; the underlying catalog data may have changed since it was proposed',
                $changeId,
                self::PENDING_MAX_AGE_HOURS,
            );
            $this->repository->markFailed($changeId, $adminId, $error);

            return ['status' => StagedChangeData::STATUS_FAILED, 'error' => $error];
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

    private function isExpired(StagedChangeData $change): bool
    {
        if ($change->createdAt === null) {
            return false;
        }

        return $change->createdAt < new \DateTimeImmutable(\sprintf('-%d hours', self::PENDING_MAX_AGE_HOURS));
    }
}

<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\StagedChange;

use CommerceAgents\StagedChange\ChangeApplierInterface;
use CommerceAgents\StagedChange\StagedChangeData;
use CommerceAgents\StagedChange\StagedChangeManager;
use CommerceAgents\StagedChange\StagedChangeRepositoryInterface;
use PHPUnit\Framework\TestCase;

class FakeStagedChangeRepository implements StagedChangeRepositoryInterface
{
    public array $calls = [];

    /** @param array<int, StagedChangeData> $changes */
    public function __construct(private readonly array $changes = [])
    {
    }

    public function find(int $id): ?StagedChangeData
    {
        return $this->changes[$id] ?? null;
    }

    public function markApplied(int $id, int $approvedBy): void
    {
        $this->calls[] = ['markApplied', $id, $approvedBy];
    }

    public function markRejected(int $id, int $approvedBy): void
    {
        $this->calls[] = ['markRejected', $id, $approvedBy];
    }

    public function markFailed(int $id, int $approvedBy, string $error): void
    {
        $this->calls[] = ['markFailed', $id, $approvedBy, $error];
    }
}

class FakePriceApplier implements ChangeApplierInterface
{
    public array $applied = [];

    public function __construct(private readonly ?string $failWith = null)
    {
    }

    public function getTargetType(): string
    {
        return 'pse_price';
    }

    public function apply(StagedChangeData $change): void
    {
        if ($this->failWith !== null) {
            throw new \RuntimeException($this->failWith);
        }
        $this->applied[] = $change->id;
    }
}

class StagedChangeManagerTest extends TestCase
{
    private function pendingChange(int $id = 5): StagedChangeData
    {
        return new StagedChangeData(
            id: $id,
            targetType: 'pse_price',
            targetId: 12,
            payloadBefore: ['price' => 30.0],
            payloadAfter: ['price' => 25.0],
            status: StagedChangeData::STATUS_PENDING,
        );
    }

    public function testApprovePendingAppliesAndMarksApplied(): void
    {
        $repository = new FakeStagedChangeRepository([5 => $this->pendingChange()]);
        $applier = new FakePriceApplier();
        $manager = new StagedChangeManager($repository, [$applier]);

        $result = $manager->approve(5, adminId: 3);

        $this->assertSame('applied', $result['status']);
        $this->assertSame([5], $applier->applied);
        $this->assertSame([['markApplied', 5, 3]], $repository->calls);
    }

    public function testApproveNonPendingIsRefused(): void
    {
        $applied = new StagedChangeData(1, 'pse_price', 12, [], [], StagedChangeData::STATUS_APPLIED);
        $repository = new FakeStagedChangeRepository([1 => $applied]);
        $applier = new FakePriceApplier();
        $manager = new StagedChangeManager($repository, [$applier]);

        $result = $manager->approve(1, adminId: 3);

        $this->assertArrayHasKey('error', $result);
        $this->assertSame([], $applier->applied);
        $this->assertSame([], $repository->calls);
    }

    public function testApplierFailureMarksFailedWithoutThrowing(): void
    {
        $repository = new FakeStagedChangeRepository([5 => $this->pendingChange()]);
        $manager = new StagedChangeManager($repository, [new FakePriceApplier(failWith: 'PSE not found')]);

        $result = $manager->approve(5, adminId: 3);

        $this->assertSame('failed', $result['status']);
        $this->assertSame('PSE not found', $result['error']);
        $this->assertSame([['markFailed', 5, 3, 'PSE not found']], $repository->calls);
    }

    public function testMissingApplierIsRefused(): void
    {
        $stock = new StagedChangeData(7, 'pse_stock', 12, [], [], StagedChangeData::STATUS_PENDING);
        $repository = new FakeStagedChangeRepository([7 => $stock]);
        $manager = new StagedChangeManager($repository, [new FakePriceApplier()]);

        $result = $manager->approve(7, adminId: 3);

        $this->assertArrayHasKey('error', $result);
        $this->assertSame([], $repository->calls);
    }

    public function testRejectPendingNeverApplies(): void
    {
        $repository = new FakeStagedChangeRepository([5 => $this->pendingChange()]);
        $applier = new FakePriceApplier();
        $manager = new StagedChangeManager($repository, [$applier]);

        $result = $manager->reject(5, adminId: 3);

        $this->assertSame('rejected', $result['status']);
        $this->assertSame([], $applier->applied);
        $this->assertSame([['markRejected', 5, 3]], $repository->calls);
    }

    public function testUnknownChangeIsRefused(): void
    {
        $manager = new StagedChangeManager(new FakeStagedChangeRepository(), [new FakePriceApplier()]);

        $this->assertArrayHasKey('error', $manager->approve(999, adminId: 3));
        $this->assertArrayHasKey('error', $manager->reject(999, adminId: 3));
    }

    public function testSelfApprovalIsRefused(): void
    {
        $change = new StagedChangeData(
            id: 5,
            targetType: 'pse_price',
            targetId: 12,
            payloadBefore: ['price' => 30.0],
            payloadAfter: ['price' => 25.0],
            status: StagedChangeData::STATUS_PENDING,
            proposedBy: 3,
        );
        $repository = new FakeStagedChangeRepository([5 => $change]);
        $applier = new FakePriceApplier();
        $manager = new StagedChangeManager($repository, [$applier]);

        $result = $manager->approve(5, adminId: 3);

        $this->assertArrayHasKey('error', $result);
        $this->assertSame([], $applier->applied);
        $this->assertSame([], $repository->calls);
    }

    public function testApprovalByDifferentAdminThanProposerSucceeds(): void
    {
        $change = new StagedChangeData(
            id: 5,
            targetType: 'pse_price',
            targetId: 12,
            payloadBefore: ['price' => 30.0],
            payloadAfter: ['price' => 25.0],
            status: StagedChangeData::STATUS_PENDING,
            proposedBy: 3,
        );
        $repository = new FakeStagedChangeRepository([5 => $change]);
        $applier = new FakePriceApplier();
        $manager = new StagedChangeManager($repository, [$applier]);

        $result = $manager->approve(5, adminId: 4);

        $this->assertSame('applied', $result['status']);
        $this->assertSame([5], $applier->applied);
    }

    public function testApproveOldPendingProposalIsRefusedAsExpired(): void
    {
        $change = new StagedChangeData(
            id: 5,
            targetType: 'pse_price',
            targetId: 12,
            payloadBefore: ['price' => 30.0],
            payloadAfter: ['price' => 25.0],
            status: StagedChangeData::STATUS_PENDING,
            createdAt: new \DateTimeImmutable('-25 hours'),
        );
        $repository = new FakeStagedChangeRepository([5 => $change]);
        $applier = new FakePriceApplier();
        $manager = new StagedChangeManager($repository, [$applier]);

        $result = $manager->approve(5, adminId: 3);

        $this->assertSame('failed', $result['status']);
        $this->assertArrayHasKey('error', $result);
        $this->assertSame([], $applier->applied);
        $this->assertSame([['markFailed', 5, 3, $result['error']]], $repository->calls);
    }

    public function testApproveRecentPendingProposalIsNotTreatedAsExpired(): void
    {
        $change = new StagedChangeData(
            id: 5,
            targetType: 'pse_price',
            targetId: 12,
            payloadBefore: ['price' => 30.0],
            payloadAfter: ['price' => 25.0],
            status: StagedChangeData::STATUS_PENDING,
            createdAt: new \DateTimeImmutable('-1 hour'),
        );
        $repository = new FakeStagedChangeRepository([5 => $change]);
        $applier = new FakePriceApplier();
        $manager = new StagedChangeManager($repository, [$applier]);

        $result = $manager->approve(5, adminId: 3);

        $this->assertSame('applied', $result['status']);
    }
}

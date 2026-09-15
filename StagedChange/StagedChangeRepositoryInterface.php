<?php

declare(strict_types=1);

namespace CommerceAgents\StagedChange;

interface StagedChangeRepositoryInterface
{
    public function find(int $id): ?StagedChangeData;

    public function updatePayloadAfter(int $id, array $payloadAfter): void;

    public function markApplied(int $id, int $approvedBy): void;

    public function markRejected(int $id, int $approvedBy): void;

    public function markFailed(int $id, int $approvedBy, string $error): void;
}

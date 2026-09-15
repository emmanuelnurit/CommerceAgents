<?php

declare(strict_types=1);

namespace CommerceAgents\StagedChange;

final readonly class StagedChangeData
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPLIED = 'applied';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_FAILED = 'failed';

    public function __construct(
        public int $id,
        public string $targetType,
        public int $targetId,
        public array $payloadBefore,
        public array $payloadAfter,
        public string $status,
        public ?int $proposedBy = null,
    ) {
    }
}

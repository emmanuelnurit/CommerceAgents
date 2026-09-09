<?php

declare(strict_types=1);

namespace CommerceAgents\StagedChange;

interface ChangeApplierInterface
{
    public function getTargetType(): string;

    /**
     * @throws \RuntimeException when the change cannot be applied
     */
    public function apply(StagedChangeData $change): void;
}

<?php

declare(strict_types=1);

namespace CommerceAgents\Service;

/**
 * Whether another Thelia module (e.g. "Comment") is installed and active.
 * Used to gate presets/tools that depend on a module CommerceAgents does not
 * ship itself (MYO-301): the dependency must be masked or clearly disabled,
 * never left silently broken.
 */
interface ModuleAvailabilityInterface
{
    public function isActive(string $moduleCode): bool;
}

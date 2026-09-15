<?php

declare(strict_types=1);

namespace CommerceAgents\Service;

use Thelia\Model\ModuleQuery;
use Thelia\Module\BaseModule;

final readonly class ModuleAvailability implements ModuleAvailabilityInterface
{
    public function isActive(string $moduleCode): bool
    {
        return ModuleQuery::create()
            ->filterByCode($moduleCode)
            ->filterByActivate(BaseModule::IS_ACTIVATED)
            ->exists();
    }
}

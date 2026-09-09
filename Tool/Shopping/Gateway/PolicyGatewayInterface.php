<?php

declare(strict_types=1);

namespace CommerceAgents\Tool\Shopping\Gateway;

interface PolicyGatewayInterface
{
    /**
     * @return array[] each policy: {title, text}
     */
    public function getPolicies(string $locale): array;
}

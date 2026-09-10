<?php

declare(strict_types=1);

namespace CommerceAgents\Tool\Admin\Gateway;

interface AdminPagesGatewayInterface
{
    /**
     * @return array[] each page: {title, url, key}
     */
    public function getPages(?string $query, string $locale): array;
}

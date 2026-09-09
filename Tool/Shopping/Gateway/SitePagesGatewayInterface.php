<?php

declare(strict_types=1);

namespace CommerceAgents\Tool\Shopping\Gateway;

interface SitePagesGatewayInterface
{
    /**
     * @return array[] each page: {title, url, type} with type in category|content|static
     */
    public function getPages(?string $query, string $locale): array;
}

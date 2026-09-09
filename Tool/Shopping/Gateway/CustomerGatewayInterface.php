<?php

declare(strict_types=1);

namespace CommerceAgents\Tool\Shopping\Gateway;

interface CustomerGatewayInterface
{
    /**
     * @return array|null {firstName, lastName, email, defaultAddress: {address, zipcode, city, country}|null, accountUrl}
     */
    public function getProfile(int $customerId, string $locale): ?array;
}

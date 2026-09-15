<?php

declare(strict_types=1);

namespace CommerceAgents\Tool\Admin\Gateway;

interface CustomerAdminGatewayInterface
{
    /**
     * @return array|null {customerId, reference, firstName, lastName, email, createdAt,
     *                     defaultAddress: {address, zipcode, city, country}|null, adminUrl}
     *                     null when no customer exists for that id
     */
    public function getCustomerProfile(int $customerId, string $locale): ?array;
}

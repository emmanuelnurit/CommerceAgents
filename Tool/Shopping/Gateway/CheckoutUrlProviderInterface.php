<?php

declare(strict_types=1);

namespace CommerceAgents\Tool\Shopping\Gateway;

interface CheckoutUrlProviderInterface
{
    public function getCheckoutUrl(): string;
}

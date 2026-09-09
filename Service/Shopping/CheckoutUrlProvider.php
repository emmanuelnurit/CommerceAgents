<?php

declare(strict_types=1);

namespace CommerceAgents\Service\Shopping;

use CommerceAgents\Tool\Shopping\Gateway\CheckoutUrlProviderInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final readonly class CheckoutUrlProvider implements CheckoutUrlProviderInterface
{
    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function getCheckoutUrl(): string
    {
        return $this->urlGenerator->generate('checkout_cart', [], UrlGeneratorInterface::ABSOLUTE_URL);
    }
}

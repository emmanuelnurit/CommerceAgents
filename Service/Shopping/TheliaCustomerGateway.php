<?php

declare(strict_types=1);

namespace CommerceAgents\Service\Shopping;

use CommerceAgents\Tool\Shopping\Gateway\CustomerGatewayInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Thelia\Model\CustomerQuery;

final readonly class TheliaCustomerGateway implements CustomerGatewayInterface
{
    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function getProfile(int $customerId, string $locale): ?array
    {
        $customer = CustomerQuery::create()->findPk($customerId);
        if ($customer === null) {
            return null;
        }

        $defaultAddress = null;
        $address = $customer->getDefaultAddress();
        if ($address !== null) {
            $defaultAddress = [
                'address' => trim($address->getAddress1().' '.($address->getAddress2() ?? '')),
                'zipcode' => $address->getZipcode(),
                'city' => $address->getCity(),
                'country' => $address->getCountry()?->setLocale($locale)->getTitle(),
            ];
        }

        return [
            'firstName' => $customer->getFirstname(),
            'lastName' => $customer->getLastname(),
            'email' => $customer->getEmail(),
            'defaultAddress' => $defaultAddress,
            'accountUrl' => $this->urlGenerator->generate('account_index', [], UrlGeneratorInterface::ABSOLUTE_URL),
        ];
    }
}

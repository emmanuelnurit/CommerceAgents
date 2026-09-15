<?php

declare(strict_types=1);

namespace CommerceAgents\Service\Merchant;

use CommerceAgents\Tool\Admin\Gateway\CustomerAdminGatewayInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Thelia\Model\CustomerQuery;

final readonly class TheliaCustomerAdminGateway implements CustomerAdminGatewayInterface
{
    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function getCustomerProfile(int $customerId, string $locale): ?array
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
            'customerId' => $customer->getId(),
            'reference' => $customer->getRef(),
            'firstName' => $customer->getFirstname(),
            'lastName' => $customer->getLastname(),
            'email' => $customer->getEmail(),
            'createdAt' => $customer->getCreatedAt()?->format('Y-m-d'),
            'defaultAddress' => $defaultAddress,
            'adminUrl' => $this->urlGenerator->generate(
                'admin.customer.update.view',
                ['customer_id' => $customerId],
                UrlGeneratorInterface::ABSOLUTE_URL,
            ),
        ];
    }
}

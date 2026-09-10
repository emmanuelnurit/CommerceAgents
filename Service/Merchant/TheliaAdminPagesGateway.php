<?php

declare(strict_types=1);

namespace CommerceAgents\Service\Merchant;

use CommerceAgents\Tool\Admin\Gateway\AdminPagesGatewayInterface;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Thelia\Core\Translation\Translator;

final readonly class TheliaAdminPagesGateway implements AdminPagesGatewayInterface
{
    /** @var array<string, array{string, string, array}> key => [title id, route name, route params] */
    private const PAGES = [
        'dashboard' => ['Dashboard', 'admin.home', []],
        'orders' => ['Orders', 'admin.order.list', []],
        'customers' => ['Customers', 'admin.customers', []],
        'products' => ['Products', 'admin.products.default', []],
        'categories' => ['Categories', 'admin.categories.default', []],
        'coupons' => ['Coupons', 'admin.coupon.default', []],
        'configuration' => ['Configuration', 'admin.configuration.index', []],
        'modules' => ['Modules', 'admin.module', []],
        'merchant_agent' => ['Merchant Agent', 'commerceagents_merchant_page', []],
        'proposed_changes' => ['Proposed changes', 'commerceagents_changes', []],
        'agent_configuration' => ['Commerce Agents configuration', 'admin.module.configure', ['module_code' => 'CommerceAgents']],
    ];

    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
        private Translator $translator,
    ) {
    }

    public function getPages(?string $query, string $locale): array
    {
        $pages = [];

        foreach (self::PAGES as $key => [$titleId, $routeName, $parameters]) {
            try {
                $url = $this->urlGenerator->generate($routeName, $parameters, UrlGeneratorInterface::ABSOLUTE_URL);
            } catch (RouteNotFoundException) {
                continue;
            }

            $title = $this->translator->trans($titleId, [], 'commerceagents', $locale);
            if ($query !== null && mb_stripos($title, $query) === false && mb_stripos($titleId, $query) === false) {
                continue;
            }

            $pages[] = ['title' => $title, 'url' => $url, 'key' => $key];
        }

        return $pages;
    }
}

<?php

declare(strict_types=1);

namespace CommerceAgents\Service\Shopping;

use CommerceAgents\Tool\Shopping\Gateway\SitePagesGatewayInterface;
use Propel\Runtime\ActiveQuery\Criteria;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Thelia\Model\CategoryQuery;
use Thelia\Model\ContentQuery;

final readonly class TheliaSitePagesGateway implements SitePagesGatewayInterface
{
    private const MAX_PAGES = 20;

    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function getPages(?string $query, string $locale): array
    {
        $pages = [];

        foreach ($this->staticPages($locale) as $page) {
            if ($query === null || mb_stripos($page['title'], $query) !== false) {
                $pages[] = $page;
            }
        }

        $categoryQuery = CategoryQuery::create()->filterByVisible(true)->orderByPosition();
        if ($query !== null) {
            $categoryQuery->useI18nQuery($locale)->filterByTitle('%'.$query.'%', Criteria::LIKE)->endUse();
        }
        foreach ($categoryQuery->limit(self::MAX_PAGES)->find() as $category) {
            $category->setLocale($locale);
            $pages[] = ['title' => $category->getTitle(), 'url' => $category->getUrl($locale), 'type' => 'category'];
        }

        $contentQuery = ContentQuery::create()->filterByVisible(true);
        if ($query !== null) {
            $contentQuery->useI18nQuery($locale)->filterByTitle('%'.$query.'%', Criteria::LIKE)->endUse();
        }
        foreach ($contentQuery->limit(self::MAX_PAGES)->find() as $content) {
            $content->setLocale($locale);
            $pages[] = ['title' => $content->getTitle(), 'url' => $content->getUrl($locale), 'type' => 'content'];
        }

        return \array_slice($pages, 0, self::MAX_PAGES);
    }

    private function staticPages(string $locale): array
    {
        $isFrench = str_starts_with($locale, 'fr');

        return [
            [
                'title' => $isFrench ? 'Accueil' : 'Home',
                'url' => $this->urlGenerator->generate('index', [], UrlGeneratorInterface::ABSOLUTE_URL),
                'type' => 'static',
            ],
            [
                'title' => $isFrench ? 'Panier' : 'Cart',
                'url' => $this->urlGenerator->generate('checkout_cart', [], UrlGeneratorInterface::ABSOLUTE_URL),
                'type' => 'static',
            ],
            [
                'title' => $isFrench ? 'Mon compte' : 'My account',
                'url' => $this->urlGenerator->generate('account_index', [], UrlGeneratorInterface::ABSOLUTE_URL),
                'type' => 'static',
            ],
            [
                'title' => $isFrench ? 'Mes commandes' : 'My orders',
                'url' => $this->urlGenerator->generate('account_orders', [], UrlGeneratorInterface::ABSOLUTE_URL),
                'type' => 'static',
            ],
        ];
    }
}

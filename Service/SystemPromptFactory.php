<?php

declare(strict_types=1);

namespace CommerceAgents\Service;

use CommerceAgents\Tool\Admin\Gateway\AdminPagesGatewayInterface;
use CommerceAgents\Tool\Shopping\Gateway\CategoryGatewayInterface;
use CommerceAgents\Tool\Shopping\Gateway\SitePagesGatewayInterface;

final readonly class SystemPromptFactory
{
    private const MAX_PROMPT_CATEGORIES = 25;

    public function __construct(
        private AdminPagesGatewayInterface $adminPagesGateway,
        private SitePagesGatewayInterface $sitePagesGateway,
        private CategoryGatewayInterface $categoryGateway,
    ) {
    }

    public function shopping(string $assistantName, string $locale): string
    {
        return sprintf(
            'You are %s, the shopping assistant of this online store. '
            .'Only discuss topics related to this store and its products. '
            .'Never invent prices or discounts, never ask for payment card details. '
            .'To find or recommend products, always use search_products (get_site_pages only lists '
            .'store pages, not the catalog). '
            .'Product titles in this catalog are model names, not product types: a word like "chair" '
            .'or "sofa" names a category, never a title. Before telling a visitor the store sells no '
            .'such thing, call get_categories and search again with the closest category_id. '
            .'Call search_products with promo=true and no query to list the current deals. A product '
            .'is on sale only when its promo_price is filled: when promo_price is absent, never '
            .'describe it as discounted or reduced. '
            .'Do not repeat a tool call that already returned a result. If a search still returns no '
            .'products after you tried the categories, tell the visitor nothing matched and ask '
            .'them for a product name or category. '
            .'The products a search returns are displayed to the visitor right under your message, as '
            .'cards with the picture, the price and an add-to-cart button. Introduce them in one or '
            .'two sentences — why they fit, what tells them apart — and never re-list the products, '
            .'their prices or their links in your text. '
            .'Whenever you mention a store page, include its link as a Markdown link '
            .'using the URLs returned by your tools. '
            .'When the visitor asks to see or go to a specific page or product, do not just give the link: '
            .'call open_page with its URL to take them there directly, and tell them where they are going. '
            .'Never build a URL by guessing a path from a page or product name: every link you write must '
            .'be copied from a tool result or from the list below, character for character. If the visitor '
            .'asks for a page that is not listed and no tool returns it, say the store has no such page.%s%s'
            .'Always answer in %s — the language the visitor selected on the store — '
            .'even if the customer writes in another language.',
            $assistantName,
            $this->sitePagesBlock($locale),
            $this->categoriesBlock($locale),
            $this->languageName($locale),
        );
    }

    public function merchant(string $locale): string
    {
        return sprintf(
            'You are the merchant assistant of this online store back-office, working for the store staff. '
            .'You can analyse sales, listings, inventory, pricing and campaigns through your tools, '
            .'and you can propose price and stock changes with update_price and update_stock. '
            .'Proposals are NEVER applied directly: a human administrator must approve each one in the '
            .'approval console before anything changes. After staging a proposal, tell the administrator '
            .'it is pending approval. '
            .'Never invent figures: every number you give must come from a tool result. '
            .'Whenever you mention a product, an order or a page and a tool result provides its URL, '
            .'include its link as a Markdown link. '
            .'When the administrator asks to open or go to a back-office screen, do not just give the link: '
            .'call open_admin_page with its URL to take them there, then tell them where they are going. '
            .'The list below is the complete set of back-office screens that exist. Never write a '
            .'back-office link that is not in it, and never build a URL by guessing a path from a screen '
            .'name: copy the URL exactly as written here, or get it from get_admin_pages. If the '
            .'administrator asks for a screen that is not listed, say it is not reachable from here and '
            .'point them to the closest listed screen.%s'
            .'Always answer in %s — the language selected in the administrator profile — '
            .'even if the administrator writes in another language.',
            $this->adminPagesBlock($locale),
            $this->languageName($locale),
        );
    }

    /**
     * The real, route-generated back-office URLs, so the assistant never has to guess one.
     */
    private function adminPagesBlock(string $locale): string
    {
        return self::pagesBlock('Back-office screens', $this->adminPagesGateway->getPages(null, $locale));
    }

    /**
     * The store pages the visitor can be sent to. Products are not listed here: they come from
     * search_products, which returns their real URL.
     */
    private function sitePagesBlock(string $locale): string
    {
        return self::pagesBlock('Store pages', $this->sitePagesGateway->getPages(null, $locale));
    }

    /**
     * The catalog vocabulary the visitor actually uses. Product titles are model
     * names, so without this the assistant cannot tell what the store sells.
     */
    private function categoriesBlock(string $locale): string
    {
        $lines = [];
        foreach ($this->categoryGateway->getCategories($locale, self::MAX_PROMPT_CATEGORIES) as $category) {
            $lines[] = sprintf('- %s (category_id %d, %d products)', $category['title'], $category['id'], $category['productCount']);
        }

        if ($lines === []) {
            return '';
        }

        return sprintf("Product categories:\n%s\n\n", implode("\n", $lines));
    }

    /**
     * @param array[] $pages
     */
    private static function pagesBlock(string $heading, array $pages): string
    {
        $lines = [];
        foreach ($pages as $page) {
            $lines[] = sprintf('- %s: %s', $page['title'], $page['url']);
        }

        if ($lines === []) {
            return ' ';
        }

        return sprintf("\n\n%s:\n%s\n\n", $heading, implode("\n", $lines));
    }

    private function languageName(string $locale): string
    {
        $name = \Locale::getDisplayLanguage($locale, 'en');

        if ($name === '' || $name === \Locale::getPrimaryLanguage($locale)) {
            return $locale;
        }

        return $name;
    }
}

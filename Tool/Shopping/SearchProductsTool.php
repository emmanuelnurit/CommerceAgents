<?php

declare(strict_types=1);

namespace CommerceAgents\Tool\Shopping;

use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Agent\Tool\ToolInterface;
use CommerceAgents\Tool\Shopping\Gateway\CatalogGatewayInterface;

final readonly class SearchProductsTool implements ToolInterface
{
    private const DEFAULT_LIMIT = 5;
    private const MAX_LIMIT = 10;

    public function __construct(
        private CatalogGatewayInterface $catalogGateway,
    ) {
    }

    public function getName(): string
    {
        return 'search_products';
    }

    public function getDescription(): string
    {
        return 'Search the store catalog. Returns matching products with their taxed price in the '
            .'customer currency, categories, public URL and image; promo_price is only present on '
            .'products that are actually discounted. Product titles are usually model names, not '
            .'product types, so a type word ("chair", "sofa") is matched against category names: '
            .'when that happens the answer carries matched_category. Combine filters freely — call '
            .'it with promo=true and no query to list current deals, or with category_id alone to '
            .'browse a category. min_price is a floor and max_price is a ceiling: "under 200" is '
            .'max_price=200 with no min_price. Price filters apply to pre-tax prices.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string', 'description' => 'Keywords matched against product titles, then against category names'],
                'category_id' => ['type' => 'integer', 'description' => 'Restrict to one category, as returned by get_categories'],
                'promo' => ['type' => 'boolean', 'description' => 'Only products currently on sale'],
                'min_price' => ['type' => 'number', 'description' => 'Cheapest acceptable pre-tax price. Use it for "at least", "more than", "from X". Leave it out for "under X".'],
                'max_price' => ['type' => 'number', 'description' => 'Dearest acceptable pre-tax price. Use it for "under", "less than", "up to", "cheap", "budget". Leave it out for "over X".'],
                'limit' => ['type' => 'integer', 'description' => 'Max results (default 5, max 10)'],
            ],
            'required' => [],
        ];
    }

    public function isAllowed(ToolContext $ctx): bool
    {
        return !$ctx->isAdmin;
    }

    public function execute(array $args, ToolContext $ctx): array
    {
        $limit = min(max(1, (int) ($args['limit'] ?? self::DEFAULT_LIMIT)), self::MAX_LIMIT);

        $search = $this->catalogGateway->searchProducts(
            query: isset($args['query']) ? (string) $args['query'] : null,
            categoryId: isset($args['category_id']) ? (int) $args['category_id'] : null,
            minPrice: isset($args['min_price']) ? (float) $args['min_price'] : null,
            maxPrice: isset($args['max_price']) ? (float) $args['max_price'] : null,
            promoOnly: (bool) ($args['promo'] ?? false),
            limit: $limit,
            ctx: $ctx,
        );

        return [
            'count' => \count($search['products']),
            'products' => $search['products'],
            'matched_category' => $search['matchedCategory'],
        ];
    }
}

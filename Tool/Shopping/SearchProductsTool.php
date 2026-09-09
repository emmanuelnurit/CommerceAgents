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
        return 'Search the store catalog by keywords. Returns matching products with their taxed price '
            .'in the customer currency, public URL and image. Price filters (min_price, max_price) apply '
            .'to pre-tax prices. Keyword matching works on word beginnings.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string', 'description' => 'Search keywords matched against product titles'],
                'category_id' => ['type' => 'integer', 'description' => 'Restrict to one category'],
                'min_price' => ['type' => 'number', 'description' => 'Minimum pre-tax price'],
                'max_price' => ['type' => 'number', 'description' => 'Maximum pre-tax price'],
                'limit' => ['type' => 'integer', 'description' => 'Max results (default 5, max 10)'],
            ],
            'required' => ['query'],
        ];
    }

    public function isAllowed(ToolContext $ctx): bool
    {
        return !$ctx->isAdmin;
    }

    public function execute(array $args, ToolContext $ctx): array
    {
        $limit = min(max(1, (int) ($args['limit'] ?? self::DEFAULT_LIMIT)), self::MAX_LIMIT);

        $products = $this->catalogGateway->searchProducts(
            query: (string) $args['query'],
            categoryId: isset($args['category_id']) ? (int) $args['category_id'] : null,
            minPrice: isset($args['min_price']) ? (float) $args['min_price'] : null,
            maxPrice: isset($args['max_price']) ? (float) $args['max_price'] : null,
            limit: $limit,
            ctx: $ctx,
        );

        return ['count' => \count($products), 'products' => $products];
    }
}

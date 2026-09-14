<?php

declare(strict_types=1);

namespace CommerceAgents\Tool\Shopping;

use CommerceAgents\Agent\Tool\Capability;
use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Agent\Tool\ToolInterface;
use CommerceAgents\Tool\Shopping\Gateway\CatalogGatewayInterface;

final readonly class GetProductDetailsTool implements ToolInterface
{
    public function __construct(
        private CatalogGatewayInterface $catalogGateway,
    ) {
    }

    public function getName(): string
    {
        return 'get_product_details';
    }

    public function getDescription(): string
    {
        return 'Get the full detail of one product: description, and every variant under "pses" '
            .'with its own label, picture, stock and taxed price in the customer currency. '
            .'Call it whenever the visitor asks about an option of a product — colours, sizes, '
            .'materials, what is left in stock — rather than answering from a search result.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'product_id' => ['type' => 'integer', 'description' => 'Product id as returned by search_products'],
            ],
            'required' => ['product_id'],
        ];
    }

    public function getRequiredCapability(): string
    {
        return Capability::CATALOG_READ;
    }

    public function isAllowed(ToolContext $ctx): bool
    {
        return !$ctx->isAdmin;
    }

    public function execute(array $args, ToolContext $ctx): array
    {
        $product = $this->catalogGateway->getProductDetails((int) $args['product_id'], $ctx);

        if ($product === null) {
            return ['error' => 'Product not found'];
        }

        return ['product' => $product];
    }
}

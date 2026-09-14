<?php

declare(strict_types=1);

namespace CommerceAgents\Tool\Shopping;

use CommerceAgents\Agent\Tool\Capability;
use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Agent\Tool\ToolInterface;
use CommerceAgents\Tool\Shopping\Gateway\CategoryGatewayInterface;

final readonly class GetCategoriesTool implements ToolInterface
{
    private const MAX_CATEGORIES = 40;

    public function __construct(
        private CategoryGatewayInterface $categoryGateway,
    ) {
    }

    public function getName(): string
    {
        return 'get_categories';
    }

    public function getDescription(): string
    {
        return 'List the product categories of the store with their product count and public URL. '
            .'Use it to know what the store actually sells before answering that something does not '
            .'exist, then pass a category_id to search_products to list its products.';
    }

    public function getInputSchema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass(), 'required' => []];
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
        $categories = $this->categoryGateway->getCategories($ctx->locale, self::MAX_CATEGORIES);

        return ['count' => \count($categories), 'categories' => $categories];
    }
}

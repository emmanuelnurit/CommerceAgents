<?php

declare(strict_types=1);

namespace CommerceAgents\Tool\Admin;

use CommerceAgents\Agent\Tool\Capability;
use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Agent\Tool\ToolInterface;
use CommerceAgents\Tool\Admin\Gateway\CatalogAdminGatewayInterface;

final readonly class GetListingsTool implements ToolInterface
{
    private const DEFAULT_LIMIT = 10;
    private const MAX_LIMIT = 50;

    public function __construct(
        private CatalogAdminGatewayInterface $catalogAdminGateway,
    ) {
    }

    public function getName(): string
    {
        return 'get_listings';
    }

    public function getDescription(): string
    {
        return 'List catalog products with their visibility, position and categories. '
            .'Optional search on titles, paginated with limit/offset.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'search' => ['type' => 'string', 'description' => 'Search keywords on product titles'],
                'limit' => ['type' => 'integer', 'description' => 'Max results (default 10, max 50)'],
                'offset' => ['type' => 'integer', 'description' => 'Pagination offset (default 0)'],
            ],
            'required' => [],
        ];
    }

    public function getRequiredCapability(): string
    {
        return Capability::CATALOG_READ;
    }

    public function isAllowed(ToolContext $ctx): bool
    {
        return $ctx->isAdmin && $ctx->adminId !== null;
    }

    public function execute(array $args, ToolContext $ctx): array
    {
        $listings = $this->catalogAdminGateway->getListings(
            search: isset($args['search']) && trim((string) $args['search']) !== '' ? trim((string) $args['search']) : null,
            limit: min(max(1, (int) ($args['limit'] ?? self::DEFAULT_LIMIT)), self::MAX_LIMIT),
            offset: max(0, (int) ($args['offset'] ?? 0)),
            ctx: $ctx,
        );

        return ['count' => \count($listings), 'listings' => $listings];
    }
}

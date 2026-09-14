<?php

declare(strict_types=1);

namespace CommerceAgents\Tool\Admin;

use CommerceAgents\Agent\Tool\Capability;
use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Agent\Tool\ToolInterface;
use CommerceAgents\Tool\Admin\Gateway\AdminPagesGatewayInterface;

final readonly class GetAdminPagesTool implements ToolInterface
{
    public function __construct(
        private AdminPagesGatewayInterface $adminPagesGateway,
    ) {
    }

    public function getName(): string
    {
        return 'get_admin_pages';
    }

    public function getDescription(): string
    {
        return 'List the back-office pages (dashboard, orders, customers, products, categories, coupons, '
            .'configuration, the proposed changes console…) with their URLs, so you can send the '
            .'administrator to the right screen. Use the optional query to filter by title.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string', 'description' => 'Keywords matched against page titles (all pages if omitted)'],
            ],
            'required' => [],
        ];
    }

    public function getRequiredCapability(): string
    {
        return Capability::CONTENT_READ;
    }

    public function isAllowed(ToolContext $ctx): bool
    {
        return $ctx->isAdmin && $ctx->adminId !== null;
    }

    public function execute(array $args, ToolContext $ctx): array
    {
        $query = isset($args['query']) && trim((string) $args['query']) !== '' ? trim((string) $args['query']) : null;

        $pages = $this->adminPagesGateway->getPages($query, $ctx->locale);

        return ['count' => \count($pages), 'pages' => $pages];
    }
}

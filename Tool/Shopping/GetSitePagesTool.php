<?php

declare(strict_types=1);

namespace CommerceAgents\Tool\Shopping;

use CommerceAgents\Agent\Tool\Capability;
use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Agent\Tool\ToolInterface;
use CommerceAgents\Tool\Shopping\Gateway\SitePagesGatewayInterface;

final readonly class GetSitePagesTool implements ToolInterface
{
    public function __construct(
        private SitePagesGatewayInterface $sitePagesGateway,
    ) {
    }

    public function getName(): string
    {
        return 'get_site_pages';
    }

    public function getDescription(): string
    {
        return 'Find store pages (categories, information pages like terms or shipping, cart, account) '
            .'with their URLs, so you can link the customer to the right page. '
            .'Use the optional query to filter by title.';
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
        return !$ctx->isAdmin;
    }

    public function execute(array $args, ToolContext $ctx): array
    {
        $query = isset($args['query']) && trim((string) $args['query']) !== '' ? trim((string) $args['query']) : null;

        $pages = $this->sitePagesGateway->getPages($query, $ctx->locale);

        return ['count' => \count($pages), 'pages' => $pages];
    }
}

<?php

declare(strict_types=1);

namespace CommerceAgents\Tool\Shopping;

use CommerceAgents\Agent\Tool\Capability;
use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Agent\Tool\ToolInterface;
use CommerceAgents\Tool\Shopping\Gateway\SiteUrlValidatorInterface;

final readonly class OpenPageTool implements ToolInterface
{
    public function __construct(
        private SiteUrlValidatorInterface $siteUrlValidator,
    ) {
    }

    public function getName(): string
    {
        return 'open_page';
    }

    public function getDescription(): string
    {
        return 'Navigate the visitor\'s browser directly to a store page (product page, category, cart, '
            .'information page…). Use a URL returned by get_site_pages, search_products, get_product_details '
            .'or prepare_checkout. Only store URLs are allowed. Prefer this over just giving a link when the '
            .'visitor asks to see or go to a page.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'url' => ['type' => 'string', 'description' => 'Store page URL from a previous tool result'],
            ],
            'required' => ['url'],
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
        $url = trim((string) $args['url']);

        if ($url === '' || !$this->siteUrlValidator->isSiteUrl($url)) {
            return ['error' => 'Only store URLs can be opened'];
        }

        return [
            'navigation' => ['url' => $url],
            'message' => 'The visitor is being redirected to this page. Tell them briefly where they are going.',
        ];
    }
}

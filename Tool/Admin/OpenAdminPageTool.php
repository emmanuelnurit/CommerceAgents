<?php

declare(strict_types=1);

namespace CommerceAgents\Tool\Admin;

use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Agent\Tool\ToolInterface;
use CommerceAgents\Tool\Shopping\Gateway\SiteUrlValidatorInterface;

final readonly class OpenAdminPageTool implements ToolInterface
{
    public function __construct(
        private SiteUrlValidatorInterface $siteUrlValidator,
    ) {
    }

    public function getName(): string
    {
        return 'open_admin_page';
    }

    public function getDescription(): string
    {
        return 'Navigate the administrator\'s browser directly to a back-office page. '
            .'Use a URL returned by get_admin_pages (or another tool result). Only back-office URLs of '
            .'this store are allowed. Prefer this over just giving a link when the administrator asks '
            .'to open or go to a screen.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'url' => ['type' => 'string', 'description' => 'Back-office page URL from a previous tool result'],
            ],
            'required' => ['url'],
        ];
    }

    public function isAllowed(ToolContext $ctx): bool
    {
        return $ctx->isAdmin && $ctx->adminId !== null;
    }

    public function execute(array $args, ToolContext $ctx): array
    {
        $url = trim((string) $args['url']);

        if ($url === '' || !$this->siteUrlValidator->isSiteUrl($url) || !$this->isBackOfficePath($url)) {
            return ['error' => 'Only back-office pages of this store can be opened'];
        }

        return [
            'navigation' => ['url' => $url],
            'message' => 'The administrator is being redirected to this page. Tell them briefly where they are going.',
        ];
    }

    private function isBackOfficePath(string $url): bool
    {
        $path = str_starts_with($url, '/') ? $url : (string) (parse_url($url, \PHP_URL_PATH) ?? '');

        return $path === '/admin' || str_starts_with($path, '/admin/');
    }
}

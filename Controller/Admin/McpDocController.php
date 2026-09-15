<?php

declare(strict_types=1);

namespace CommerceAgents\Controller\Admin;

use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;
use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Command\McpServeCommand;
use CommerceAgents\Mcp\McpToolCatalog;
use CommerceAgents\Mcp\Server\ServerInfo;
use CommerceAgents\Service\Locale\AssistantLocaleResolver;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\SecurityContext;
use Twig\Environment;

final readonly class McpDocController
{
    public function __construct(
        private AdminAccessChecker $access,
        private SecurityContext $securityContext,
        private McpToolCatalog $toolCatalog,
        private Environment $twig,
        private AssistantLocaleResolver $localeResolver,
    ) {
    }

    #[Route('/admin/merchant-agent/mcp', name: 'commerceagents_mcp_page', methods: ['GET'])]
    public function page(Request $request): Response
    {
        if ($denied = $this->access->check([], 'commerceagents', AccessManager::VIEW)) {
            return $denied;
        }

        $admin = $this->securityContext->getAdminUser();
        $login = $admin->getLogin();
        $baseUrl = $request->getSchemeAndHttpHost();
        $arguments = ['commerceagents:mcp:serve', '--admin='.$login, '--base-url='.$baseUrl];
        $confirmEnvVar = McpServeCommand::CONFIRM_ENV_VAR;

        return new Response($this->twig->render('@CommerceAgentsModule/backOffice/default-twig/merchant-chat/mcp.html.twig', [
            'serverName' => ServerInfo::NAME,
            'protocolVersions' => ServerInfo::SUPPORTED_PROTOCOL_VERSIONS,
            'adminLogin' => $login,
            'baseUrl' => $baseUrl,
            'confirmEnvVar' => $confirmEnvVar,
            'command' => $confirmEnvVar.'=1 php Thelia '.implode(' ', $arguments),
            'claudeDesktopConfig' => json_encode(
                ['mcpServers' => ['thelia' => [
                    'command' => 'php',
                    'args' => ['/path/to/thelia/Thelia', ...$arguments],
                    'env' => [$confirmEnvVar => '1'],
                ]]],
                \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES,
            ),
            'claudeCodeCommand' => 'claude mcp add thelia --env '.$confirmEnvVar.'=1 -- php /path/to/thelia/Thelia '.implode(' ', $arguments),
            'tools' => $this->toolCatalog->describe(new ToolContext(
                isAdmin: true,
                adminId: $admin->getId(),
                locale: $this->localeResolver->forAdmin($admin->getLocale()),
            )),
        ]));
    }
}

<?php

declare(strict_types=1);

namespace CommerceAgents\Hook\Theme;

use CommerceAgents\Service\AgentConfigService;
use Thelia\Core\Hook\Theme\ThemeHookInterface;
use Twig\Environment;

final readonly class ChatWidgetThemeHook implements ThemeHookInterface
{
    public function __construct(
        private AgentConfigService $configService,
        private Environment $twig,
    ) {
    }

    public function supports(string $hookName): bool
    {
        return $hookName === 'layout.body.bottom';
    }

    public function render(string $hookName, array $parameters): string
    {
        if (!$this->configService->isFrontChatEnabled()) {
            return '';
        }

        return $this->twig->render('@CommerceAgentsModule/theme-hook/chat_widget.html.twig', [
            'assistantName' => $this->configService->getAssistantName(),
        ]);
    }
}

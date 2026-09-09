<?php

declare(strict_types=1);

namespace CommerceAgents\Service\Shopping;

use CommerceAgents\Service\AgentConfigService;
use CommerceAgents\Tool\Shopping\Gateway\PolicyGatewayInterface;
use Thelia\Model\ContentQuery;

final readonly class TheliaPolicyGateway implements PolicyGatewayInterface
{
    private const MAX_TEXT_LENGTH = 2000;

    public function __construct(
        private AgentConfigService $configService,
    ) {
    }

    public function getPolicies(string $locale): array
    {
        $policies = [];

        foreach ($this->configService->getPolicyContentIds() as $contentId) {
            $content = ContentQuery::create()->findPk($contentId);
            if ($content === null || !$content->getVisible()) {
                continue;
            }

            $content->setLocale($locale);
            $policies[] = [
                'title' => $content->getTitle(),
                'text' => mb_substr(trim(strip_tags((string) $content->getDescription())), 0, self::MAX_TEXT_LENGTH),
            ];
        }

        return $policies;
    }
}

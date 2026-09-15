<?php

declare(strict_types=1);

namespace CommerceAgents\Channel\Connector;

final readonly class MattermostChannelConnector extends AbstractWebhookChannelConnector
{
    public function getCode(): string
    {
        return 'mattermost';
    }

    public function getLabel(): string
    {
        return 'Mattermost';
    }
}

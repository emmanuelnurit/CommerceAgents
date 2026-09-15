<?php

declare(strict_types=1);

namespace CommerceAgents\Channel\Connector;

final readonly class SlackChannelConnector extends AbstractWebhookChannelConnector
{
    public function getCode(): string
    {
        return 'slack';
    }

    public function getLabel(): string
    {
        return 'Slack';
    }
}

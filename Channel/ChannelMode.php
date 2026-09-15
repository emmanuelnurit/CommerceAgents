<?php

declare(strict_types=1);

namespace CommerceAgents\Channel;

/**
 * agent_channel.mode: draft queues a proposed message for BO approval
 * (existing staged-change flow), direct sends it immediately (plan MYO-226 §3.6).
 */
final class ChannelMode
{
    public const DRAFT = 'draft';
    public const DIRECT = 'direct';

    private function __construct()
    {
    }
}

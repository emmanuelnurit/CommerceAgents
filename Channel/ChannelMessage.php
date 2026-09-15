<?php

declare(strict_types=1);

namespace CommerceAgents\Channel;

final readonly class ChannelMessage
{
    /**
     * @param array $metadata free-form context (e.g. run id, trigger code) a connector may use for formatting
     */
    public function __construct(
        public ?string $subject,
        public string $body,
        public array $metadata = [],
    ) {
    }
}

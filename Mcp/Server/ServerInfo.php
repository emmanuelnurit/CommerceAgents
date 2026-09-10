<?php

declare(strict_types=1);

namespace CommerceAgents\Mcp\Server;

final class ServerInfo
{
    public const NAME = 'thelia-commerce-agents';
    public const VERSION = '0.2.0';

    public const LATEST_PROTOCOL_VERSION = '2025-11-25';

    /** Newest first. */
    public const SUPPORTED_PROTOCOL_VERSIONS = ['2025-11-25', '2025-06-18', '2025-03-26', '2024-11-05'];

    public static function negotiateProtocolVersion(?string $requested): string
    {
        return \in_array($requested, self::SUPPORTED_PROTOCOL_VERSIONS, true) ? $requested : self::LATEST_PROTOCOL_VERSION;
    }

    public static function capabilities(): array
    {
        return ['tools' => ['listChanged' => false]];
    }

    public static function info(): array
    {
        return ['name' => self::NAME, 'version' => self::VERSION];
    }
}

<?php

declare(strict_types=1);

namespace CommerceAgents\Mcp\Server;

interface TransportInterface
{
    /**
     * Blocks until the client closes the connection.
     */
    public function serve(McpServer $server): void;
}

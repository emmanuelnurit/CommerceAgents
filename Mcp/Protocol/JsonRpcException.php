<?php

declare(strict_types=1);

namespace CommerceAgents\Mcp\Protocol;

final class JsonRpcException extends \RuntimeException
{
    public function __construct(int $code, string $message, public readonly int|string|null $requestId = null)
    {
        parent::__construct($message, $code);
    }
}

<?php

declare(strict_types=1);

namespace CommerceAgents\Mcp\Protocol;

/**
 * JSON-RPC 2.0 framing used by the Model Context Protocol.
 */
final class JsonRpc
{
    public const VERSION = '2.0';

    public const PARSE_ERROR = -32700;
    public const INVALID_REQUEST = -32600;
    public const METHOD_NOT_FOUND = -32601;
    public const INVALID_PARAMS = -32602;
    public const INTERNAL_ERROR = -32603;

    /**
     * @throws JsonRpcException on malformed input
     */
    public static function parse(string $json): array
    {
        try {
            $message = json_decode($json, true, 64, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new JsonRpcException(self::PARSE_ERROR, 'Parse error: '.$exception->getMessage());
        }

        if (!\is_array($message) || array_is_list($message)) {
            throw new JsonRpcException(self::INVALID_REQUEST, 'Invalid request: expected a JSON-RPC object');
        }

        return $message;
    }

    public static function encode(array $message): string
    {
        return json_encode($message, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
    }

    public static function response(int|string|null $id, array $result): array
    {
        return ['jsonrpc' => self::VERSION, 'id' => $id, 'result' => $result];
    }

    public static function error(int|string|null $id, int $code, string $message): array
    {
        return ['jsonrpc' => self::VERSION, 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }
}

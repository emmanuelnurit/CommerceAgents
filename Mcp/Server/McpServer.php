<?php

declare(strict_types=1);

namespace CommerceAgents\Mcp\Server;

use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Agent\Tool\ToolException;
use CommerceAgents\Agent\Tool\ToolRegistry;
use CommerceAgents\Mcp\Protocol\JsonRpc;
use CommerceAgents\Mcp\Protocol\JsonRpcException;

/**
 * Serves the module ToolRegistry to an MCP client. One server = one fixed ToolContext,
 * so every gate (admin, customer, feature toggles) applies exactly as in the chat.
 */
final class McpServer
{
    /**
     * @param list<string> $hiddenTools tools never exposed over MCP (browser navigation…)
     */
    public function __construct(
        private readonly ToolRegistry $toolRegistry,
        private readonly ToolContext $toolContext,
        private readonly array $hiddenTools = [],
    ) {
    }

    /**
     * @return string|null encoded response, null for notifications
     */
    public function handle(string $rawMessage): ?string
    {
        $id = null;

        try {
            $message = JsonRpc::parse($rawMessage);
            $id = $message['id'] ?? null;

            if (($message['jsonrpc'] ?? null) !== JsonRpc::VERSION || !\is_string($message['method'] ?? null)) {
                throw new JsonRpcException(JsonRpc::INVALID_REQUEST, 'Invalid request: jsonrpc "2.0" and a string method are required');
            }

            $isNotification = !\array_key_exists('id', $message);
            $params = \is_array($message['params'] ?? null) ? $message['params'] : [];
            $result = $this->dispatch($message['method'], $params, $isNotification);

            return $isNotification ? null : JsonRpc::encode(JsonRpc::response($id, $result));
        } catch (JsonRpcException $exception) {
            return JsonRpc::encode(JsonRpc::error($id, $exception->getCode(), $exception->getMessage()));
        } catch (\Throwable $exception) {
            return JsonRpc::encode(JsonRpc::error($id, JsonRpc::INTERNAL_ERROR, $exception->getMessage()));
        }
    }

    private function dispatch(string $method, array $params, bool $isNotification): array
    {
        if (str_starts_with($method, 'notifications/')) {
            return [];
        }

        return match ($method) {
            'initialize' => $this->initialize($params),
            'ping' => [],
            'tools/list' => ['tools' => $this->listTools()],
            'tools/call' => $this->callTool($params),
            default => $isNotification
                ? []
                : throw new JsonRpcException(JsonRpc::METHOD_NOT_FOUND, sprintf('Unknown method "%s"', $method)),
        };
    }

    private function initialize(array $params): array
    {
        $requested = $params['protocolVersion'] ?? null;

        return [
            'protocolVersion' => ServerInfo::negotiateProtocolVersion(\is_string($requested) ? $requested : null),
            'capabilities' => ServerInfo::capabilities(),
            'serverInfo' => ServerInfo::info(),
        ];
    }

    private function listTools(): array
    {
        $tools = [];

        foreach ($this->toolRegistry->getToolSpecs($this->toolContext) as $spec) {
            if (\in_array($spec['name'], $this->hiddenTools, true)) {
                continue;
            }

            $tools[] = [
                'name' => $spec['name'],
                'description' => $spec['description'],
                'inputSchema' => $spec['input_schema'],
            ];
        }

        return $tools;
    }

    private function callTool(array $params): array
    {
        $name = $params['name'] ?? null;
        if (!\is_string($name) || $name === '') {
            throw new JsonRpcException(JsonRpc::INVALID_PARAMS, 'Tool name is required');
        }

        $arguments = \is_array($params['arguments'] ?? null) ? $params['arguments'] : [];

        if (\in_array($name, $this->hiddenTools, true)) {
            return $this->toolError(sprintf('Tool "%s" is not available over MCP', $name));
        }

        try {
            $result = $this->toolRegistry->execute($name, $arguments, $this->toolContext);
        } catch (ToolException $exception) {
            return $this->toolError($exception->getMessage());
        }

        return [
            'content' => [['type' => 'text', 'text' => json_encode($result, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR)]],
            'structuredContent' => $result,
            'isError' => false,
        ];
    }

    private function toolError(string $message): array
    {
        return [
            'content' => [['type' => 'text', 'text' => $message]],
            'isError' => true,
        ];
    }
}

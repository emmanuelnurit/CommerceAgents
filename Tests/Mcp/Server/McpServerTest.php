<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Mcp\Server;

use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Agent\Tool\ToolException;
use CommerceAgents\Agent\Tool\ToolInterface;
use CommerceAgents\Agent\Tool\ToolRegistry;
use CommerceAgents\Mcp\Protocol\JsonRpc;
use CommerceAgents\Mcp\Server\McpServer;
use CommerceAgents\Mcp\Server\ServerInfo;
use PHPUnit\Framework\TestCase;

final class FakeAdminTool implements ToolInterface
{
    public function __construct(private readonly string $name, private readonly bool $throws = false)
    {
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): string
    {
        return 'Fake '.$this->name;
    }

    public function getInputSchema(): array
    {
        return ['type' => 'object', 'properties' => ['pse_id' => ['type' => 'integer']], 'required' => ['pse_id']];
    }

    public function isAllowed(ToolContext $ctx): bool
    {
        return $ctx->isAdmin;
    }

    public function execute(array $args, ToolContext $ctx): array
    {
        if ($this->throws) {
            throw new ToolException('Variant not found');
        }

        return ['tool' => $this->name, 'pse_id' => $args['pse_id'], 'adminId' => $ctx->adminId];
    }
}

final class FakeShoppingTool implements ToolInterface
{
    public function getName(): string
    {
        return 'search_products';
    }

    public function getDescription(): string
    {
        return 'Front only';
    }

    public function getInputSchema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass(), 'required' => []];
    }

    public function isAllowed(ToolContext $ctx): bool
    {
        return !$ctx->isAdmin;
    }

    public function execute(array $args, ToolContext $ctx): array
    {
        return [];
    }
}

class McpServerTest extends TestCase
{
    private function server(array $hiddenTools = ['open_admin_page']): McpServer
    {
        $registry = new ToolRegistry();
        $registry->register(new FakeAdminTool('get_inventory'));
        $registry->register(new FakeAdminTool('open_admin_page'));
        $registry->register(new FakeAdminTool('failing_tool', throws: true));
        $registry->register(new FakeShoppingTool());

        return new McpServer($registry, new ToolContext(isAdmin: true, adminId: 42), $hiddenTools);
    }

    private function call(McpServer $server, array $message): ?array
    {
        $raw = $server->handle(JsonRpc::encode($message));

        return $raw === null ? null : json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
    }

    public function testInitializeNegotiatesProtocolVersion(): void
    {
        $response = $this->call($this->server(), [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-03-26', 'capabilities' => new \stdClass(), 'clientInfo' => ['name' => 'test', 'version' => '1']],
        ]);

        $this->assertSame(1, $response['id']);
        $this->assertSame('2025-03-26', $response['result']['protocolVersion']);
        $this->assertSame(ServerInfo::NAME, $response['result']['serverInfo']['name']);
        $this->assertArrayHasKey('tools', $response['result']['capabilities']);
    }

    public function testInitializeFallsBackToLatestVersionWhenClientVersionUnknown(): void
    {
        $response = $this->call($this->server(), [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '1999-01-01'],
        ]);

        $this->assertSame(ServerInfo::LATEST_PROTOCOL_VERSION, $response['result']['protocolVersion']);
    }

    public function testNotificationsGetNoResponse(): void
    {
        $this->assertNull($this->server()->handle('{"jsonrpc":"2.0","method":"notifications/initialized"}'));
        $this->assertNull($this->server()->handle('{"jsonrpc":"2.0","method":"notifications/cancelled","params":{"requestId":3}}'));
    }

    public function testPing(): void
    {
        $response = $this->call($this->server(), ['jsonrpc' => '2.0', 'id' => 'p1', 'method' => 'ping']);

        $this->assertSame(['jsonrpc' => '2.0', 'id' => 'p1', 'result' => []], $response);
    }

    public function testToolsListExposesOnlyAllowedAndVisibleTools(): void
    {
        $response = $this->call($this->server(), ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list']);

        $names = array_column($response['result']['tools'], 'name');
        $this->assertSame(['get_inventory', 'failing_tool'], $names);
        $this->assertSame('Fake get_inventory', $response['result']['tools'][0]['description']);
        $this->assertSame(['pse_id'], $response['result']['tools'][0]['inputSchema']['required']);
        $this->assertArrayNotHasKey('input_schema', $response['result']['tools'][0]);
    }

    public function testToolsCallExecutesWithServerContext(): void
    {
        $response = $this->call($this->server(), [
            'jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call',
            'params' => ['name' => 'get_inventory', 'arguments' => ['pse_id' => 12]],
        ]);

        $result = $response['result'];
        $this->assertFalse($result['isError']);
        $this->assertSame('text', $result['content'][0]['type']);
        $this->assertSame(['tool' => 'get_inventory', 'pse_id' => 12, 'adminId' => 42], json_decode($result['content'][0]['text'], true));
        $this->assertSame(['tool' => 'get_inventory', 'pse_id' => 12, 'adminId' => 42], $result['structuredContent']);
    }

    public function testToolsCallReportsToolErrorsAsIsError(): void
    {
        $missingArgument = $this->call($this->server(), [
            'jsonrpc' => '2.0', 'id' => 4, 'method' => 'tools/call', 'params' => ['name' => 'get_inventory', 'arguments' => []],
        ]);
        $this->assertTrue($missingArgument['result']['isError']);
        $this->assertStringContainsString('pse_id', $missingArgument['result']['content'][0]['text']);

        $throwing = $this->call($this->server(), [
            'jsonrpc' => '2.0', 'id' => 5, 'method' => 'tools/call', 'params' => ['name' => 'failing_tool', 'arguments' => ['pse_id' => 1]],
        ]);
        $this->assertTrue($throwing['result']['isError']);
        $this->assertStringContainsString('Variant not found', $throwing['result']['content'][0]['text']);
    }

    public function testToolsCallRefusesHiddenAndUnknownTools(): void
    {
        foreach (['open_admin_page', 'search_products', 'nope'] as $name) {
            $response = $this->call($this->server(), [
                'jsonrpc' => '2.0', 'id' => 6, 'method' => 'tools/call', 'params' => ['name' => $name, 'arguments' => ['pse_id' => 1]],
            ]);
            $this->assertTrue($response['result']['isError'], $name);
        }
    }

    public function testToolsCallWithoutNameIsInvalidParams(): void
    {
        $response = $this->call($this->server(), ['jsonrpc' => '2.0', 'id' => 7, 'method' => 'tools/call', 'params' => []]);

        $this->assertSame(JsonRpc::INVALID_PARAMS, $response['error']['code']);
    }

    public function testUnknownMethodIsMethodNotFound(): void
    {
        $response = $this->call($this->server(), ['jsonrpc' => '2.0', 'id' => 8, 'method' => 'resources/list']);

        $this->assertSame(8, $response['id']);
        $this->assertSame(JsonRpc::METHOD_NOT_FOUND, $response['error']['code']);
    }

    public function testMalformedInputIsReportedAsJsonRpcError(): void
    {
        $parseError = json_decode((string) $this->server()->handle('{oops'), true);
        $this->assertSame(JsonRpc::PARSE_ERROR, $parseError['error']['code']);
        $this->assertNull($parseError['id']);

        $invalidRequest = json_decode((string) $this->server()->handle('{"id":9,"method":"ping"}'), true);
        $this->assertSame(JsonRpc::INVALID_REQUEST, $invalidRequest['error']['code']);
        $this->assertSame(9, $invalidRequest['id']);
    }
}

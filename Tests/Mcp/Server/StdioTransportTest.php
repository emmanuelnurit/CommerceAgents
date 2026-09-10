<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Mcp\Server;

use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Agent\Tool\ToolRegistry;
use CommerceAgents\Mcp\Server\McpServer;
use CommerceAgents\Mcp\Server\StdioTransport;
use PHPUnit\Framework\TestCase;

class StdioTransportTest extends TestCase
{
    public function testAnswersEachRequestLineAndSkipsNotificationsAndBlankLines(): void
    {
        $input = fopen('php://memory', 'r+');
        fwrite($input, "{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"ping\"}\n\n{\"jsonrpc\":\"2.0\",\"method\":\"notifications/initialized\"}\n{\"jsonrpc\":\"2.0\",\"id\":2,\"method\":\"tools/list\"}\n");
        rewind($input);
        $output = fopen('php://memory', 'w+');
        $logged = [];

        $transport = new StdioTransport($input, $output, static function (string $line) use (&$logged): void {
            $logged[] = $line;
        });
        $transport->serve(new McpServer(new ToolRegistry(), new ToolContext(isAdmin: true, adminId: 1)));

        rewind($output);
        $lines = array_values(array_filter(explode("\n", (string) stream_get_contents($output))));

        $this->assertCount(2, $lines);
        $this->assertSame(['jsonrpc' => '2.0', 'id' => 1, 'result' => []], json_decode($lines[0], true));
        $this->assertSame(['jsonrpc' => '2.0', 'id' => 2, 'result' => ['tools' => []]], json_decode($lines[1], true));
        $this->assertContains('MCP client disconnected', $logged);
    }
}

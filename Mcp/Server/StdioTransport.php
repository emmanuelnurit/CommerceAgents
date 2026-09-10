<?php

declare(strict_types=1);

namespace CommerceAgents\Mcp\Server;

/**
 * Newline-delimited JSON-RPC over stdin/stdout. stdout carries protocol messages only;
 * diagnostics go through the optional logger (stderr in the console command).
 *
 * @phpstan-type Logger \Closure(string): void
 */
final class StdioTransport implements TransportInterface
{
    /** @var resource */
    private $input;

    /** @var resource */
    private $output;

    /**
     * @param resource|null $input
     * @param resource|null $output
     */
    public function __construct(
        $input = null,
        $output = null,
        private readonly ?\Closure $logger = null,
    ) {
        $this->input = $input ?? fopen('php://stdin', 'r') ?: throw new \RuntimeException('Cannot open stdin');
        $this->output = $output ?? fopen('php://stdout', 'w') ?: throw new \RuntimeException('Cannot open stdout');
    }

    public function serve(McpServer $server): void
    {
        $this->log('MCP server ready (stdio)');

        while (($line = fgets($this->input)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $this->log('<- '.$line);
            $response = $server->handle($line);

            if ($response !== null) {
                $this->log('-> '.$response);
                fwrite($this->output, $response."\n");
                fflush($this->output);
            }
        }

        $this->log('MCP client disconnected');
    }

    private function log(string $message): void
    {
        if ($this->logger !== null) {
            ($this->logger)($message);
        }
    }
}

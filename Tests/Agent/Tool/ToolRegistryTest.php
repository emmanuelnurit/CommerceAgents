<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Agent\Tool;

use CommerceAgents\Agent\Tool\AgentActionLoggerInterface;
use CommerceAgents\Agent\Tool\Capability;
use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Agent\Tool\ToolException;
use CommerceAgents\Agent\Tool\ToolInterface;
use CommerceAgents\Agent\Tool\ToolRegistry;
use PHPUnit\Framework\TestCase;

class FakeAgentActionLogger implements AgentActionLoggerInterface
{
    /** @var list<array{0: string, 1: ?string, 2: string, 3: ?string}> */
    public array $calls = [];

    public function log(string $toolName, ?string $capability, string $status, ToolContext $ctx, array $arguments = [], ?string $error = null): void
    {
        $this->calls[] = [$toolName, $capability, $status, $error];
    }
}

class FakeEchoTool implements ToolInterface
{
    public function getRequiredCapability(): string
    {
        return Capability::CATALOG_READ;
    }

    public function getName(): string
    {
        return 'echo';
    }

    public function getDescription(): string
    {
        return 'Echoes input';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['text' => ['type' => 'string']],
            'required' => ['text'],
        ];
    }

    public function isAllowed(ToolContext $ctx): bool
    {
        return $ctx->isAdmin;
    }

    public function execute(array $args, ToolContext $ctx): array
    {
        return ['echo' => $args['text']];
    }
}

class ToolRegistryTest extends TestCase
{
    private function registry(): ToolRegistry
    {
        $r = new ToolRegistry();
        $r->register(new FakeEchoTool());

        return $r;
    }

    public function testExecutesRegisteredTool(): void
    {
        $ctx = new ToolContext(isAdmin: true);
        $result = $this->registry()->execute('echo', ['text' => 'hi'], $ctx);
        $this->assertSame(['echo' => 'hi'], $result);
    }

    public function testUnknownToolThrows(): void
    {
        $this->expectException(ToolException::class);
        $this->registry()->execute('nope', [], new ToolContext(isAdmin: true));
    }

    public function testMissingRequiredArgThrows(): void
    {
        $this->expectException(ToolException::class);
        $this->registry()->execute('echo', [], new ToolContext(isAdmin: true));
    }

    public function testGateDeniesExecution(): void
    {
        $this->expectException(ToolException::class);
        $this->registry()->execute('echo', ['text' => 'hi'], new ToolContext(isAdmin: false));
    }

    public function testConstructorRegistersIterableTools(): void
    {
        $registry = new ToolRegistry([new FakeEchoTool()]);
        $specs = $registry->getToolSpecs(new ToolContext(isAdmin: true));
        $this->assertSame('echo', $specs[0]['name']);
    }

    public function testExportsSpecsForLlm(): void
    {
        $specs = $this->registry()->getToolSpecs(new ToolContext(isAdmin: true));
        $this->assertSame('echo', $specs[0]['name']);
        $this->assertArrayHasKey('input_schema', $specs[0]);
    }

    public function testLogsSuccessfulExecution(): void
    {
        $logger = new FakeAgentActionLogger();
        $registry = new ToolRegistry([new FakeEchoTool()], $logger);

        $registry->execute('echo', ['text' => 'hi'], new ToolContext(isAdmin: true));

        $this->assertSame([['echo', Capability::CATALOG_READ, 'success', null]], $logger->calls);
    }

    public function testLogsDeniedExecution(): void
    {
        $logger = new FakeAgentActionLogger();
        $registry = new ToolRegistry([new FakeEchoTool()], $logger);

        try {
            $registry->execute('echo', ['text' => 'hi'], new ToolContext(isAdmin: false));
        } catch (ToolException) {
        }

        $this->assertSame([['echo', Capability::CATALOG_READ, 'denied', null]], $logger->calls);
    }

    public function testLogsUnknownToolExecution(): void
    {
        $logger = new FakeAgentActionLogger();
        $registry = new ToolRegistry([], $logger);

        try {
            $registry->execute('nope', [], new ToolContext(isAdmin: true));
        } catch (ToolException) {
        }

        $this->assertSame('nope', $logger->calls[0][0]);
        $this->assertSame('error', $logger->calls[0][2]);
    }

    public function testLogsMissingArgumentAsError(): void
    {
        $logger = new FakeAgentActionLogger();
        $registry = new ToolRegistry([new FakeEchoTool()], $logger);

        try {
            $registry->execute('echo', [], new ToolContext(isAdmin: true));
        } catch (ToolException) {
        }

        $this->assertSame('error', $logger->calls[0][2]);
    }

    public function testNoLoggerConfiguredDoesNotBreakExecution(): void
    {
        $result = $this->registry()->execute('echo', ['text' => 'hi'], new ToolContext(isAdmin: true));
        $this->assertSame(['echo' => 'hi'], $result);
    }
}

<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Agent\Tool;

use CommerceAgents\Agent\Tool\Capability;
use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Agent\Tool\ToolException;
use CommerceAgents\Agent\Tool\ToolInterface;
use CommerceAgents\Agent\Tool\ToolRegistry;
use PHPUnit\Framework\TestCase;

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
}

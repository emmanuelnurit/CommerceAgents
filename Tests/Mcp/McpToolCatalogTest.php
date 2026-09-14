<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Mcp;

use CommerceAgents\Agent\Tool\Capability;
use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Agent\Tool\ToolInterface;
use CommerceAgents\Agent\Tool\ToolRegistry;
use CommerceAgents\Mcp\McpToolCatalog;
use PHPUnit\Framework\TestCase;

final class CatalogFakeTool implements ToolInterface
{
    public function getRequiredCapability(): string
    {
        return Capability::CATALOG_READ;
    }

    public function __construct(private readonly string $name, private readonly bool $adminOnly = true)
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
        return ['type' => 'object', 'properties' => ['id' => ['type' => 'integer', 'description' => 'An id']], 'required' => ['id']];
    }

    public function isAllowed(ToolContext $ctx): bool
    {
        return $this->adminOnly ? $ctx->isAdmin : !$ctx->isAdmin;
    }

    public function execute(array $args, ToolContext $ctx): array
    {
        return [];
    }
}

class McpToolCatalogTest extends TestCase
{
    public function testDescribesVisibleToolsForTheContextAndFlagsWrites(): void
    {
        $registry = new ToolRegistry();
        $registry->register(new CatalogFakeTool('get_inventory'));
        $registry->register(new CatalogFakeTool('update_stock'));
        $registry->register(new CatalogFakeTool('open_admin_page'));
        $registry->register(new CatalogFakeTool('search_products', adminOnly: false));

        $tools = (new McpToolCatalog($registry))->describe(new ToolContext(isAdmin: true, adminId: 1));

        $this->assertSame(['get_inventory', 'update_stock'], array_column($tools, 'name'));
        $this->assertFalse($tools[0]['writes']);
        $this->assertTrue($tools[1]['writes']);
        $this->assertSame('An id', $tools[0]['inputSchema']['properties']['id']['description']);
    }

    public function testHiddenToolsAreTheNavigationOnes(): void
    {
        $this->assertTrue(McpToolCatalog::isHidden('open_page'));
        $this->assertTrue(McpToolCatalog::isHidden('open_admin_page'));
        $this->assertFalse(McpToolCatalog::isHidden('get_pricing'));
    }
}

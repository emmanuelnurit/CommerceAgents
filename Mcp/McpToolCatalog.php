<?php

declare(strict_types=1);

namespace CommerceAgents\Mcp;

use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Agent\Tool\ToolRegistry;

/**
 * The subset of the ToolRegistry an MCP client may see: everything the context allows,
 * minus tools that only make sense inside the chat (browser navigation).
 */
final readonly class McpToolCatalog
{
    public const HIDDEN_TOOLS = ['open_admin_page', 'open_page'];

    public function __construct(private ToolRegistry $toolRegistry)
    {
    }

    public static function isHidden(string $toolName): bool
    {
        return \in_array($toolName, self::HIDDEN_TOOLS, true);
    }

    /**
     * @return list<array{name: string, description: string, inputSchema: array, writes: bool}>
     */
    public function describe(ToolContext $context): array
    {
        $tools = [];

        foreach ($this->toolRegistry->getToolSpecs($context) as $spec) {
            if (self::isHidden($spec['name'])) {
                continue;
            }

            $tools[] = [
                'name' => $spec['name'],
                'description' => $spec['description'],
                'inputSchema' => $spec['input_schema'],
                'writes' => str_starts_with($spec['name'], 'update_') || str_starts_with($spec['name'], 'create_'),
            ];
        }

        return $tools;
    }
}

<?php

declare(strict_types=1);

namespace CommerceAgents\Agent\Tool;

use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;

class ToolRegistry
{
    /** @var array<string, ToolInterface> */
    private array $tools = [];

    /**
     * @param iterable<ToolInterface> $tools
     */
    public function __construct(
        #[TaggedIterator('commerce_agents.tool')] iterable $tools = [],
    ) {
        foreach ($tools as $tool) {
            $this->register($tool);
        }
    }

    public function register(ToolInterface $tool): void
    {
        $this->tools[$tool->getName()] = $tool;
    }

    public function execute(string $name, array $args, ToolContext $ctx): array
    {
        $tool = $this->tools[$name] ?? throw new ToolException(\sprintf('Unknown tool "%s"', $name));

        if (!$this->isAllowed($tool, $ctx)) {
            throw new ToolException(\sprintf('Tool "%s" not allowed in this context', $name));
        }

        $this->validateArguments($tool->getInputSchema(), $args, $name);

        return $tool->execute($args, $ctx);
    }

    /**
     * For a context carrying an agent definition, the granted capabilities are
     * the whole rule: an autonomous run has no admin or customer session, so
     * the per-tool session rules do not apply (plan MYO-226 §3.4). The
     * historical chat contexts keep relying on each tool's own rules.
     */
    public function isAllowed(ToolInterface $tool, ToolContext $ctx): bool
    {
        if ($ctx->capabilities !== null) {
            return $ctx->hasCapability($tool->getRequiredCapability());
        }

        return $tool->isAllowed($ctx);
    }

    /**
     * Specs of the tools allowed for this context, normalized as
     * {name, description, input_schema}. Tools the context cannot call
     * are never exposed to the LLM.
     */
    public function getToolSpecs(ToolContext $ctx): array
    {
        $specs = [];
        foreach ($this->tools as $tool) {
            if (!$this->isAllowed($tool, $ctx)) {
                continue;
            }
            $specs[] = [
                'name' => $tool->getName(),
                'description' => $tool->getDescription(),
                'input_schema' => $tool->getInputSchema(),
            ];
        }

        return $specs;
    }

    private function validateArguments(array $schema, array $args, string $toolName): void
    {
        foreach ($schema['required'] ?? [] as $field) {
            if (!\array_key_exists($field, $args)) {
                throw new ToolException(\sprintf('Tool "%s": missing required argument "%s"', $toolName, $field));
            }
        }

        foreach ($schema['properties'] ?? [] as $field => $definition) {
            if (!\array_key_exists($field, $args)) {
                continue;
            }
            $expected = $definition['type'] ?? null;
            if ($expected !== null && !$this->matchesType($args[$field], $expected)) {
                throw new ToolException(\sprintf('Tool "%s": argument "%s" must be of type %s', $toolName, $field, $expected));
            }
        }
    }

    private function matchesType(mixed $value, string $type): bool
    {
        return match ($type) {
            'string' => \is_string($value),
            'number' => \is_int($value) || \is_float($value),
            'integer' => \is_int($value),
            'boolean' => \is_bool($value),
            'array' => \is_array($value),
            default => true,
        };
    }
}

<?php

declare(strict_types=1);

namespace CommerceAgents\Service\SpecialtyPane;

use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;

/**
 * Resolution order (MYO-327 architecture decision, see the comment on that
 * issue): preset_code exact match first, then an unambiguous capability
 * signature, then the generic pane as a guaranteed last resort.
 *
 * $genericPane is wired separately from the tagged iterator (not tagged
 * itself) so the fallback never depends on iteration/priority order: only
 * the 5 specialty panes race each other via supports(), and the generic
 * pane is only ever consulted once none of them matched.
 */
final class SpecialtyResultsPaneRegistry
{
    /** @var list<SpecialtyResultsPaneInterface> */
    private readonly array $panes;

    /**
     * @param iterable<SpecialtyResultsPaneInterface> $panes
     */
    public function __construct(
        #[TaggedIterator('commerce_agents.specialty_pane')] iterable $panes,
        private readonly GenericResultsPane $genericPane,
    ) {
        $this->panes = $panes instanceof \Traversable ? iterator_to_array($panes, false) : $panes;
    }

    /**
     * @param list<string> $capabilities
     */
    public function resolve(?string $presetCode, array $capabilities): SpecialtyResultsPaneInterface
    {
        foreach ($this->panes as $pane) {
            if ($pane->supports($presetCode, $capabilities)) {
                return $pane;
            }
        }

        return $this->genericPane;
    }
}

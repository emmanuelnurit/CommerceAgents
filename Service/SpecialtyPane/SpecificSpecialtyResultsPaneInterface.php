<?php

declare(strict_types=1);

namespace CommerceAgents\Service\SpecialtyPane;

/**
 * Marker implemented by the 5 concrete specialty panes only -- never by
 * GenericResultsPane. CommerceAgents::configureServices() tags this
 * interface via instanceof(), the same mechanism used for every other
 * extension point in this module (ToolInterface, ChangeApplierInterface,
 * ...): unlike a tag attached directly to a Definition, an instanceof()
 * conditional survives the module-wide `load()` autodiscovery that runs
 * after individual service registration and would otherwise silently drop
 * a directly-attached tag. GenericResultsPane stays untagged and is wired
 * as SpecialtyResultsPaneRegistry's separate, unconditional fallback
 * argument so it can never race the specific panes.
 */
interface SpecificSpecialtyResultsPaneInterface extends SpecialtyResultsPaneInterface
{
}

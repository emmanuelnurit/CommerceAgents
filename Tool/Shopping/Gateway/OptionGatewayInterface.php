<?php

declare(strict_types=1);

namespace CommerceAgents\Tool\Shopping\Gateway;

interface OptionGatewayInterface
{
    /**
     * Every option value the catalog actually uses ("Couleur: Orange", "Taille: M"),
     * most used first.
     *
     * @return list<array{id: int, title: string, attribute: string, variantCount: int}>
     */
    public function getValues(string $locale, int $limit): array;

    /**
     * Closest option value to a free-text term, or null when nothing is close.
     *
     * @return array{id: int, title: string, attribute: string, variantCount: int}|null
     */
    public function findValueByName(string $term, string $locale): ?array;
}

<?php

declare(strict_types=1);

namespace CommerceAgents\Service;

final readonly class SystemPromptFactory
{
    public function shopping(string $assistantName, string $locale): string
    {
        return sprintf(
            'You are %s, the shopping assistant of this online store. '
            .'Only discuss topics related to this store and its products. '
            .'Never invent prices or discounts, never ask for payment card details. '
            .'Always answer in %s — the language the visitor selected on the store — '
            .'even if the customer writes in another language.',
            $assistantName,
            $this->languageName($locale),
        );
    }

    public function merchant(string $locale): string
    {
        return sprintf(
            'You are the merchant assistant of this online store back-office, working for the store staff. '
            .'You are read-only in this phase: you can analyse sales, listings, inventory, pricing and campaigns '
            .'through your tools, but you cannot change anything yet. '
            .'Never invent figures: every number you give must come from a tool result. '
            .'Always answer in %s — the language selected in the administrator profile — '
            .'even if the administrator writes in another language.',
            $this->languageName($locale),
        );
    }

    private function languageName(string $locale): string
    {
        $name = \Locale::getDisplayLanguage($locale, 'en');

        if ($name === '' || $name === \Locale::getPrimaryLanguage($locale)) {
            return $locale;
        }

        return $name;
    }
}

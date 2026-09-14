<?php

declare(strict_types=1);

/*
 * Bundled model catalog. Prices are per million tokens in the currency given
 * by each provider section ("currency"), as published by the provider on the
 * date given in "priced_at". Mistral publishes its tariffs in EUR, the other
 * providers in USD; the internal cost accounting stays in USD and converts
 * with the "usd_to_eur" rate below ("rated_at" = date of that rate). The
 * back-office lets the merchant override prices and discover new models
 * through the provider APIs; rows edited by hand are never overwritten by
 * this catalog.
 *
 * "tier" buckets the models by reasoning depth for the agent model selector:
 * fast (cheap, low latency), balanced (daily driver), deep (heavy reasoning).
 */
return [
    'usd_to_eur' => 0.90,
    'rated_at' => '2026-09-14',
    'providers' => [
        'anthropic' => [
            'priced_at' => '2026-06-24',
            'currency' => 'USD',
            'models' => [
                ['id' => 'claude-opus-5', 'name' => 'Claude Opus 5', 'tier' => 'deep', 'input' => 5.00, 'output' => 25.00, 'context' => 1000000],
                ['id' => 'claude-sonnet-5', 'name' => 'Claude Sonnet 5', 'tier' => 'balanced', 'input' => 2.00, 'output' => 10.00, 'context' => 1000000],
                ['id' => 'claude-opus-4-8', 'name' => 'Claude Opus 4.8', 'tier' => 'deep', 'input' => 5.00, 'output' => 25.00, 'context' => 1000000],
                ['id' => 'claude-opus-4-7', 'name' => 'Claude Opus 4.7', 'tier' => 'deep', 'input' => 5.00, 'output' => 25.00, 'context' => 1000000],
                ['id' => 'claude-opus-4-6', 'name' => 'Claude Opus 4.6', 'tier' => 'deep', 'input' => 5.00, 'output' => 25.00, 'context' => 1000000],
                ['id' => 'claude-sonnet-4-6', 'name' => 'Claude Sonnet 4.6', 'tier' => 'balanced', 'input' => 3.00, 'output' => 15.00, 'context' => 1000000],
                ['id' => 'claude-haiku-4-5', 'name' => 'Claude Haiku 4.5', 'tier' => 'fast', 'input' => 1.00, 'output' => 5.00, 'context' => 200000],
                ['id' => 'claude-fable-5-1', 'name' => 'Claude Fable 5.1', 'tier' => 'deep', 'input' => 10.00, 'output' => 50.00, 'context' => 1000000],
            ],
        ],
        'mistral' => [
            'priced_at' => '2026-09-14',
            'currency' => 'EUR',
            'models' => [
                ['id' => 'mistral-large-latest', 'name' => 'Mistral Large 3', 'tier' => 'deep', 'input' => 0.45, 'output' => 1.35, 'context' => 256000],
                ['id' => 'magistral-medium-latest', 'name' => 'Magistral Medium', 'tier' => 'deep', 'input' => 1.80, 'output' => 7.20, 'context' => 128000],
                ['id' => 'mistral-medium-latest', 'name' => 'Mistral Medium 3.5', 'tier' => 'balanced', 'input' => 1.35, 'output' => 6.75, 'context' => 256000],
                ['id' => 'mistral-small-latest', 'name' => 'Mistral Small 4', 'tier' => 'balanced', 'input' => 0.14, 'output' => 0.54, 'context' => null],
                ['id' => 'codestral-latest', 'name' => 'Codestral', 'tier' => 'balanced', 'input' => 0.27, 'output' => 0.81, 'context' => 128000],
                ['id' => 'ministral-8b-latest', 'name' => 'Ministral 8B', 'tier' => 'fast', 'input' => 0.14, 'output' => 0.14, 'context' => 128000],
                ['id' => 'ministral-3b-latest', 'name' => 'Ministral 3B', 'tier' => 'fast', 'input' => 0.09, 'output' => 0.09, 'context' => 128000],
            ],
        ],
        'openai-compatible' => [
            'priced_at' => '2026-09-10',
            'currency' => 'USD',
            'models' => [
                ['id' => 'gpt-5', 'name' => 'GPT-5', 'tier' => 'deep', 'input' => 1.25, 'output' => 10.00, 'context' => null],
                ['id' => 'gpt-5-mini', 'name' => 'GPT-5 mini', 'tier' => 'balanced', 'input' => 0.25, 'output' => 2.00, 'context' => null],
                ['id' => 'gpt-5-nano', 'name' => 'GPT-5 nano', 'tier' => 'fast', 'input' => 0.05, 'output' => 0.40, 'context' => null],
                ['id' => 'gpt-4.1', 'name' => 'GPT-4.1', 'tier' => 'deep', 'input' => 2.00, 'output' => 8.00, 'context' => null],
                ['id' => 'gpt-4.1-mini', 'name' => 'GPT-4.1 mini', 'tier' => 'balanced', 'input' => 0.40, 'output' => 1.60, 'context' => null],
                ['id' => 'gpt-4.1-nano', 'name' => 'GPT-4.1 nano', 'tier' => 'fast', 'input' => 0.10, 'output' => 0.40, 'context' => null],
            ],
        ],
    ],
];

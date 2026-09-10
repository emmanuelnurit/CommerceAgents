<?php

declare(strict_types=1);

/*
 * Bundled model catalog. Prices are USD per million tokens, as published by
 * each provider on the date given in "priced_at". The back-office lets the
 * merchant override prices and discover new models through the provider APIs;
 * rows edited by hand are never overwritten by this catalog.
 */
return [
    'anthropic' => [
        'priced_at' => '2026-06-24',
        'models' => [
            ['id' => 'claude-opus-5', 'name' => 'Claude Opus 5', 'input' => 5.00, 'output' => 25.00, 'context' => 1000000],
            ['id' => 'claude-sonnet-5', 'name' => 'Claude Sonnet 5', 'input' => 2.00, 'output' => 10.00, 'context' => 1000000],
            ['id' => 'claude-opus-4-8', 'name' => 'Claude Opus 4.8', 'input' => 5.00, 'output' => 25.00, 'context' => 1000000],
            ['id' => 'claude-opus-4-7', 'name' => 'Claude Opus 4.7', 'input' => 5.00, 'output' => 25.00, 'context' => 1000000],
            ['id' => 'claude-opus-4-6', 'name' => 'Claude Opus 4.6', 'input' => 5.00, 'output' => 25.00, 'context' => 1000000],
            ['id' => 'claude-sonnet-4-6', 'name' => 'Claude Sonnet 4.6', 'input' => 3.00, 'output' => 15.00, 'context' => 1000000],
            ['id' => 'claude-haiku-4-5', 'name' => 'Claude Haiku 4.5', 'input' => 1.00, 'output' => 5.00, 'context' => 200000],
            ['id' => 'claude-fable-5-1', 'name' => 'Claude Fable 5.1', 'input' => 10.00, 'output' => 50.00, 'context' => 1000000],
        ],
    ],
    'mistral' => [
        'priced_at' => '2026-09-10',
        'models' => [
            ['id' => 'mistral-large-latest', 'name' => 'Mistral Large 3', 'input' => 0.50, 'output' => 1.50, 'context' => 256000],
            ['id' => 'mistral-medium-latest', 'name' => 'Mistral Medium 3.5', 'input' => 1.50, 'output' => 7.50, 'context' => 256000],
            ['id' => 'mistral-small-latest', 'name' => 'Mistral Small 4', 'input' => 0.15, 'output' => 0.60, 'context' => null],
            ['id' => 'ministral-8b-latest', 'name' => 'Ministral 8B', 'input' => 0.15, 'output' => 0.15, 'context' => 128000],
            ['id' => 'ministral-3b-latest', 'name' => 'Ministral 3B', 'input' => 0.10, 'output' => 0.10, 'context' => 128000],
            ['id' => 'codestral-latest', 'name' => 'Codestral', 'input' => 0.30, 'output' => 0.90, 'context' => 128000],
        ],
    ],
    'openai-compatible' => [
        'priced_at' => '2026-09-10',
        'models' => [
            ['id' => 'gpt-5', 'name' => 'GPT-5', 'input' => 1.25, 'output' => 10.00, 'context' => null],
            ['id' => 'gpt-5-mini', 'name' => 'GPT-5 mini', 'input' => 0.25, 'output' => 2.00, 'context' => null],
            ['id' => 'gpt-5-nano', 'name' => 'GPT-5 nano', 'input' => 0.05, 'output' => 0.40, 'context' => null],
            ['id' => 'gpt-4.1', 'name' => 'GPT-4.1', 'input' => 2.00, 'output' => 8.00, 'context' => null],
            ['id' => 'gpt-4.1-mini', 'name' => 'GPT-4.1 mini', 'input' => 0.40, 'output' => 1.60, 'context' => null],
            ['id' => 'gpt-4.1-nano', 'name' => 'GPT-4.1 nano', 'input' => 0.10, 'output' => 0.40, 'context' => null],
        ],
    ],
];

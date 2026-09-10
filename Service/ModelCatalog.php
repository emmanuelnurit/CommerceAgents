<?php

declare(strict_types=1);

namespace CommerceAgents\Service;

use CommerceAgents\Agent\Llm\LlmClientFactory;
use CommerceAgents\Agent\Llm\LlmConfig;
use CommerceAgents\Agent\Llm\ModelDiscovery;
use CommerceAgents\Model\AgentModel;
use CommerceAgents\Model\AgentModelQuery;
use Propel\Runtime\ActiveQuery\Criteria;

/**
 * Catalog of LLM models per provider with their prices (USD per million
 * tokens). Seeded from Config/models.php, extended from the provider APIs,
 * and editable in the back-office. A row edited by hand ("manual" source)
 * is never overwritten by the bundled catalog.
 */
final readonly class ModelCatalog
{
    public const SOURCE_CATALOG = 'catalog';
    public const SOURCE_API = 'api';
    public const SOURCE_MANUAL = 'manual';

    public function __construct(
        private ModelDiscovery $modelDiscovery,
    ) {
    }

    /**
     * Inserts the bundled models and refreshes the prices of rows still
     * carrying the catalog source. Returns the number of rows written.
     */
    public function seedFromBundledCatalog(): int
    {
        $catalog = require __DIR__.'/../Config/models.php';
        $written = 0;

        foreach ($catalog as $provider => $section) {
            $pricedAt = new \DateTimeImmutable($section['priced_at']);

            foreach ($section['models'] as $entry) {
                $model = AgentModelQuery::create()->filterByProvider($provider)->filterByModelId($entry['id'])->findOne();

                if ($model === null) {
                    $model = (new AgentModel())
                        ->setProvider($provider)
                        ->setModelId($entry['id'])
                        ->setEnabled(1)
                        ->setSource(self::SOURCE_CATALOG);
                } elseif ($model->getSource() === self::SOURCE_MANUAL) {
                    continue;
                }

                $model
                    ->setName($entry['name'])
                    ->setPriceInput(self::decimal($entry['input']))
                    ->setPriceOutput(self::decimal($entry['output']))
                    ->setContextWindow($entry['context'])
                    ->setSource(self::SOURCE_CATALOG)
                    ->setPricedAt($pricedAt);

                if ($model->isModified() || $model->isNew()) {
                    $model->save();
                    ++$written;
                }
            }
        }

        return $written;
    }

    /**
     * Asks the provider which models the account can use. Unknown models are
     * added without prices; known ones get their last_seen_at refreshed.
     *
     * @return array{added: int, seen: int}
     */
    public function refreshFromProvider(LlmConfig $config): array
    {
        $now = new \DateTimeImmutable();
        $added = 0;
        $seen = 0;
        $aliasGroups = [];

        foreach ($this->modelDiscovery->list($config) as $discovered) {
            $model = AgentModelQuery::create()->filterByProvider($config->provider)->filterByModelId($discovered['id'])->findOne();

            if ($model === null) {
                $model = (new AgentModel())
                    ->setProvider($config->provider)
                    ->setModelId($discovered['id'])
                    ->setName($discovered['name'])
                    ->setEnabled(0)
                    ->setSource(self::SOURCE_API);
                ++$added;
            }

            $model->setLastSeenAt($now)->save();
            ++$seen;

            if ($discovered['aliases'] !== []) {
                $aliasGroups[] = [$discovered['id'], ...$discovered['aliases']];
            }
        }

        $this->propagatePricesAcrossAliases($config->provider, $aliasGroups);

        return ['added' => $added, 'seen' => $seen];
    }

    /**
     * A provider may expose the same model under several ids (Mistral
     * "ministral-3b-2512" and "ministral-3b-latest"). Rows without a price
     * inherit the price of a priced row of the same group.
     *
     * @param list<list<string>> $aliasGroups
     */
    private function propagatePricesAcrossAliases(string $provider, array $aliasGroups): void
    {
        foreach ($aliasGroups as $ids) {
            $rows = AgentModelQuery::create()->filterByProvider($provider)->filterByModelId($ids, Criteria::IN)->find()->getData();

            $priced = array_values(array_filter($rows, static fn (AgentModel $row): bool => $row->getPriceInput() !== null && $row->getPriceOutput() !== null));
            if ($priced === []) {
                continue;
            }
            $reference = $priced[0];

            foreach ($rows as $row) {
                if ($row->getPriceInput() !== null && $row->getPriceOutput() !== null) {
                    continue;
                }
                $row
                    ->setPriceInput($reference->getPriceInput())
                    ->setPriceOutput($reference->getPriceOutput())
                    ->setContextWindow($row->getContextWindow() ?? $reference->getContextWindow())
                    ->setPricedAt($reference->getPricedAt())
                    ->save();
            }
        }
    }

    public function hasBundledRows(): bool
    {
        return AgentModelQuery::create()->filterBySource(self::SOURCE_CATALOG)->exists();
    }

    /**
     * @return AgentModel[]
     */
    public function listByProvider(string $provider, bool $enabledOnly = false): array
    {
        $query = AgentModelQuery::create()->filterByProvider($provider)->orderByModelId();
        if ($enabledOnly) {
            $query->filterByEnabled(1);
        }

        return $query->find()->getData();
    }

    /**
     * @return array<string, AgentModel[]> provider => models
     */
    public function listAll(): array
    {
        $byProvider = [];
        foreach (LlmClientFactory::PROVIDERS as $provider) {
            $byProvider[$provider] = $this->listByProvider($provider);
        }

        return $byProvider;
    }

    public function find(string $provider, string $modelId): ?AgentModel
    {
        return AgentModelQuery::create()->filterByProvider($provider)->filterByModelId($modelId)->findOne();
    }

    /**
     * @return array{input: ?float, output: ?float}
     */
    public function pricesFor(string $provider, string $modelId): array
    {
        $model = $this->find($provider, $modelId);

        return [
            'input' => $model?->getPriceInput() !== null ? (float) $model->getPriceInput() : null,
            'output' => $model?->getPriceOutput() !== null ? (float) $model->getPriceOutput() : null,
        ];
    }

    /**
     * Applies the merchant's edits: prices, name and enabled flag per row id.
     *
     * @param array<int, array{price_input?: string, price_output?: string, enabled?: string, name?: string}> $edits
     */
    public function applyEdits(array $edits): int
    {
        $updated = 0;

        foreach ($edits as $id => $fields) {
            $model = AgentModelQuery::create()->findPk((int) $id);
            if ($model === null) {
                continue;
            }

            $priceInput = self::parsePrice($fields['price_input'] ?? '');
            $priceOutput = self::parsePrice($fields['price_output'] ?? '');
            $priceChanged = $priceInput !== $model->getPriceInput() || $priceOutput !== $model->getPriceOutput();

            $model
                ->setPriceInput($priceInput)
                ->setPriceOutput($priceOutput)
                ->setEnabled(($fields['enabled'] ?? '0') === '1' ? 1 : 0);

            if (isset($fields['name']) && trim($fields['name']) !== '') {
                $model->setName(trim($fields['name']));
            }

            if ($priceChanged) {
                $model->setSource(self::SOURCE_MANUAL)->setPricedAt(new \DateTimeImmutable());
            }

            if ($model->isModified()) {
                $model->save();
                ++$updated;
            }
        }

        return $updated;
    }

    public function addManual(string $provider, string $modelId, ?string $name, ?string $priceInput, ?string $priceOutput): AgentModel
    {
        $model = $this->find($provider, $modelId) ?? (new AgentModel())->setProvider($provider)->setModelId($modelId);
        $model
            ->setName($name !== null && trim($name) !== '' ? trim($name) : $modelId)
            ->setPriceInput(self::parsePrice($priceInput ?? ''))
            ->setPriceOutput(self::parsePrice($priceOutput ?? ''))
            ->setEnabled(1)
            ->setSource(self::SOURCE_MANUAL)
            ->setPricedAt(new \DateTimeImmutable());
        $model->save();

        return $model;
    }

    public static function parsePrice(string $raw): ?string
    {
        $raw = trim(str_replace(',', '.', $raw));
        if ($raw === '' || !is_numeric($raw) || (float) $raw < 0) {
            return null;
        }

        return self::decimal((float) $raw);
    }

    private static function decimal(float $value): string
    {
        return number_format($value, 6, '.', '');
    }
}

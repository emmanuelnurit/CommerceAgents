<?php

declare(strict_types=1);

namespace CommerceAgents\Service;

use CommerceAgents\Agent\Llm\LlmClientFactory;
use CommerceAgents\Agent\Llm\LlmConfig;
use CommerceAgents\Agent\Llm\ModelDiscovery;
use CommerceAgents\CommerceAgents;
use CommerceAgents\Model\AgentModel;
use CommerceAgents\Model\AgentModelQuery;
use Propel\Runtime\ActiveQuery\Criteria;
use Thelia\Core\Translation\Translator;

/**
 * Catalog of LLM models per provider with their prices per million tokens,
 * in the currency each provider publishes (EUR for Mistral, USD elsewhere).
 * Seeded from Config/models.php, extended from the provider APIs, and
 * editable in the back-office. A row edited by hand ("manual" source) is
 * never overwritten by the bundled catalog.
 */
final readonly class ModelCatalog
{
    public const SOURCE_CATALOG = 'catalog';
    public const SOURCE_API = 'api';
    public const SOURCE_MANUAL = 'manual';

    public function __construct(
        private ModelDiscovery $modelDiscovery,
        private Translator $translator,
    ) {
    }

    /**
     * Inserts the bundled models and refreshes the prices of rows still
     * carrying the catalog source. Returns the number of rows written.
     * Rows edited by hand keep their prices, name and currency (their
     * prices were typed in the currency of the time); only their empty
     * tier is backfilled so they stay grouped in the selector.
     */
    public function seedFromBundledCatalog(): int
    {
        $written = 0;

        foreach (self::bundledCatalog()['providers'] as $provider => $section) {
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
                    $model->setTier($model->getTier() ?? $entry['tier']);
                    if ($model->isModified()) {
                        $model->save();
                        ++$written;
                    }
                    continue;
                } elseif ($model->getSource() === self::SOURCE_API) {
                    // An API-discovered row waits disabled for curation;
                    // entering the bundled catalog is that curation. A row
                    // already "catalog" keeps the merchant's enabled flag.
                    $model->setEnabled(1);
                }

                $model
                    ->setName($entry['name'])
                    ->setPriceInput(self::decimal($entry['input']))
                    ->setPriceOutput(self::decimal($entry['output']))
                    ->setContextWindow($entry['context'])
                    ->setTier($entry['tier'])
                    ->setCurrency($section['currency'])
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
     * Prices in USD per million tokens, whatever currency the row carries:
     * the internal cost accounting (agent_message.cost, BudgetGuard) stays
     * in USD and converts with the rate maintained in Config/models.php.
     *
     * @return array{input: ?float, output: ?float}
     */
    public function pricesFor(string $provider, string $modelId): array
    {
        $model = $this->find($provider, $modelId);
        $currency = $model?->getCurrency() ?? 'USD';

        return [
            'input' => self::toUsd($model?->getPriceInput() !== null ? (float) $model->getPriceInput() : null, $currency),
            'output' => self::toUsd($model?->getPriceOutput() !== null ? (float) $model->getPriceOutput() : null, $currency),
        ];
    }

    public static function toUsd(?float $price, string $currency): ?float
    {
        if ($price === null || $currency !== 'EUR') {
            return $price;
        }

        return round($price / self::usdToEurRate(), 6);
    }

    public static function usdToEurRate(): float
    {
        return (float) self::bundledCatalog()['usd_to_eur'];
    }

    /**
     * The models offered by the agent model selector: enabled, priced, and
     * ordered by reasoning tier then input price. Null provider = every
     * provider. This is the data contract of the back-office agent form.
     *
     * @return ModelChoice[]
     */
    public function getSelectableModels(?string $provider = null): array
    {
        $providers = $provider !== null ? [$provider] : LlmClientFactory::PROVIDERS;

        $choices = [];
        foreach ($providers as $providerCode) {
            foreach ($this->listByProvider($providerCode, enabledOnly: true) as $row) {
                if ($row->getPriceInput() === null || $row->getPriceOutput() === null) {
                    continue;
                }
                $choices[] = $this->choiceFromRow($row);
            }
        }

        $rank = static fn (ModelChoice $c): array => [array_search($c->tier, ModelChoice::TIERS, true), (float) $c->priceInput, $c->modelId];
        usort($choices, static fn (ModelChoice $a, ModelChoice $b): int => $rank($a) <=> $rank($b));

        return $choices;
    }

    /**
     * Rows predating the tier column fall back to "balanced"; rows predating
     * the currency column were priced when everything was USD.
     */
    public function choiceFromRow(AgentModel $row): ModelChoice
    {
        $tier = \in_array($row->getTier(), ModelChoice::TIERS, true) ? $row->getTier() : ModelChoice::TIER_BALANCED;

        return new ModelChoice(
            modelId: $row->getModelId(),
            name: $row->getName() ?? $row->getModelId(),
            tier: $tier,
            tierLabel: $this->translator->trans(ModelChoice::TIER_LABELS[$tier], [], CommerceAgents::DOMAIN_NAME),
            priceInput: self::formatPrice((string) $row->getPriceInput()),
            priceOutput: self::formatPrice((string) $row->getPriceOutput()),
            currency: $row->getCurrency() ?? 'USD',
            contextWindow: $row->getContextWindow(),
            isDefault: $row->getModelId() === (LlmClientFactory::DEFAULT_MODELS[$row->getProvider()] ?? null),
        );
    }

    /**
     * Trims the storage decimals ("0.090000") down to a display price
     * ("0.09"), never shorter than two decimals.
     */
    public static function formatPrice(string $stored): string
    {
        $trimmed = rtrim(rtrim($stored, '0'), '.');
        $decimals = \strlen(substr(strrchr($trimmed, '.') ?: '', 1));

        return number_format((float) $stored, max(2, $decimals), '.', '');
    }

    /**
     * @return array{usd_to_eur: float, rated_at: string, providers: array<string, array{priced_at: string, currency: string, models: list<array{id: string, name: string, tier: string, input: float, output: float, context: ?int}>}>}
     */
    private static function bundledCatalog(): array
    {
        static $catalog = null;

        return $catalog ??= require __DIR__.'/../Config/models.php';
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

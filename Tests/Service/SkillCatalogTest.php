<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Service;

use CommerceAgents\Agent\Llm\ModelDiscovery;
use CommerceAgents\Model\AgentConversation;
use CommerceAgents\Model\AgentDefinitionQuery;
use CommerceAgents\Model\AgentMessage;
use CommerceAgents\Model\AgentRun;
use CommerceAgents\Model\AgentStagedChange;
use CommerceAgents\Service\AgentDefinitionManager;
use CommerceAgents\Service\AgentDefinitionSeeder;
use CommerceAgents\Service\AgentPresets;
use CommerceAgents\Service\AgentSpendRepository;
use CommerceAgents\Service\CapabilityCatalog;
use CommerceAgents\Service\Locale\AssistantLocaleResolver;
use CommerceAgents\Service\ModelCatalog;
use CommerceAgents\Service\Run\AbandonedCartFinder;
use CommerceAgents\Service\Run\AgentRunQueue;
use CommerceAgents\Service\Run\LowStockFinder;
use CommerceAgents\Service\ScopeCatalog;
use CommerceAgents\Service\SkillCatalog;
use CommerceAgents\Service\TriggerCatalog;
use CommerceAgents\StagedChange\StagedChangeData;
use CommerceAgents\Tests\Service\Locale\FakeSiteDefaultLocaleProvider;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Thelia\Core\Translation\Translator;
use Thelia\Test\IntegrationTestCase;

/**
 * MYO-506: `state`, `acceptanceRate` and `costPerProposalEur` must all be
 * derived from real rows (`agent_definition`, `agent_staged_change`,
 * `agent_message`) -- never a hardcoded number (AC2/AC3/AC4). A `soon` skill
 * must never become activable just because its code happens to be passed in.
 */
class SkillCatalogTest extends IntegrationTestCase
{
    private SkillCatalog $catalog;

    protected function setUp(): void
    {
        parent::setUp();

        $definitionManager = new AgentDefinitionManager(
            new ModelCatalog(new ModelDiscovery(new MockHttpClient()), new Translator(new RequestStack())),
            new TriggerCatalog(new Translator(new RequestStack())),
            new AgentRunQueue(
                new NullLogger(),
                new AbandonedCartFinder(),
                new LowStockFinder(),
                new AssistantLocaleResolver(new FakeSiteDefaultLocaleProvider('en_US')),
            ),
            new NullLogger(),
        );

        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturnCallback(
            static fn (string $name, array $params = []): string => '/'.$name.'/'.($params['id'] ?? ''),
        );

        $this->catalog = new SkillCatalog($definitionManager, new AgentSpendRepository(), $urlGenerator, new CapabilityCatalog(new Translator(new RequestStack())));
    }

    public function testSoonSkillIsNeverActivable(): void
    {
        $soon = self::findRow($this->catalog->all(), 'campaign_proposal');

        self::assertSame('soon', $soon['state']);
        self::assertNull($soon['agentDefinitionId']);
        self::assertNull($soon['editUrl']);

        $this->expectException(\RuntimeException::class);
        $this->catalog->activate('campaign_proposal');
    }

    /**
     * Regression net for MYO-519: `SkillCatalog::all()` fed `brief.html.twig`
     * (`{% if skill.guidedSettings %}`) a `SOON` row with no `guidedSettings`
     * key at all. With `strict_variables: true` (active in `dev`) that is a
     * fatal Twig error, but `phpunit.xml.dist` forces `APP_DEBUG=0`, which
     * disables `strict_variables` for every PHPUnit run -- so the 500 shipped
     * with a fully green suite. `array_key_exists()`, not `isset()`/`??`: the
     * key must be *present*, even though its value is legitimately `null` for
     * every never-activated or `soon` row.
     */
    public function testEveryRowHasTheFullRowContract(): void
    {
        $contractKeys = [
            'code', 'labelKey', 'icon', 'color', 'state',
            'agentDefinitionId', 'acceptanceRate', 'costPerProposalEur', 'editUrl',
            'guidedSettings',
        ];

        foreach ($this->catalog->all() as $row) {
            foreach ($contractKeys as $key) {
                self::assertArrayHasKey($key, $row, \sprintf('skill "%s" is missing row key "%s"', $row['code'] ?? '?', $key));
            }
        }
    }

    public function testActivateCreatesAgentDefinitionFromPresetAndIsIdempotent(): void
    {
        $code = AgentPresets::STUCK_ORDERS_WATCH;

        $this->catalog->activate($code);
        $first = self::findRow($this->catalog->all(), $code);
        self::assertSame('active', $first['state']);
        self::assertNotNull($first['agentDefinitionId']);
        self::assertSame('/commerceagents_agents_edit/'.$first['agentDefinitionId'], $first['editUrl']);

        // Idempotent: calling it again must not create a second agent_definition.
        $this->catalog->activate($code);
        self::assertSame(1, AgentDefinitionQuery::create()->filterByPresetCode($code)->count());
    }

    public function testDeactivateDisablesWithoutDeletingHistory(): void
    {
        $code = AgentPresets::CUSTOMER_REVIEWS_REPLY;
        $this->catalog->activate($code);
        $definitionId = self::findRow($this->catalog->all(), $code)['agentDefinitionId'];

        $this->catalog->deactivate($code);
        $inactiveRow = self::findRow($this->catalog->all(), $code);

        self::assertSame('inactive', $inactiveRow['state']);
        self::assertSame($definitionId, $inactiveRow['agentDefinitionId'], 'the agent_definition row must still exist, just disabled');

        // Idempotent too.
        $this->catalog->deactivate($code);
        self::assertSame('inactive', self::findRow($this->catalog->all(), $code)['state']);
    }

    public function testAcceptanceRateAggregatesRealAppliedAndRejectedStagedChanges(): void
    {
        $code = AgentPresets::CART_ABANDONED;
        $this->catalog->activate($code);
        $definitionId = self::findRow($this->catalog->all(), $code)['agentDefinitionId'];

        $conversation = (new AgentConversation())->setType('run');
        $conversation->save();

        self::stageChange($conversation->getId(), $definitionId, StagedChangeData::STATUS_APPLIED);
        self::stageChange($conversation->getId(), $definitionId, StagedChangeData::STATUS_APPLIED);
        self::stageChange($conversation->getId(), $definitionId, StagedChangeData::STATUS_APPLIED);
        self::stageChange($conversation->getId(), $definitionId, StagedChangeData::STATUS_REJECTED);
        self::stageChange($conversation->getId(), $definitionId, StagedChangeData::STATUS_PENDING); // must not count

        $row = self::findRow($this->catalog->all(), $code);
        self::assertSame(0.75, $row['acceptanceRate']);
    }

    public function testAggregatesAreNullWithoutAnyStagedChangeHistory(): void
    {
        $code = AgentPresets::PRODUCT_SHEET_AUDIT;
        $this->catalog->activate($code);

        $row = self::findRow($this->catalog->all(), $code);
        self::assertNull($row['acceptanceRate']);
        self::assertNull($row['costPerProposalEur']);
    }

    public function testCostPerProposalEurAggregatesRealSpendAcrossProposals(): void
    {
        $code = AgentPresets::STOCK_WATCH_RESTOCK;
        $this->catalog->activate($code);
        $definitionId = self::findRow($this->catalog->all(), $code)['agentDefinitionId'];

        $conversation = (new AgentConversation())->setType('run');
        $conversation->save();

        $run = (new AgentRun())
            ->setAgentDefinitionId($definitionId)
            ->setConversationId($conversation->getId())
            ->setStatus(AgentRunQueue::STATUS_DONE);
        $run->save();

        (new AgentMessage())->setConversationId($conversation->getId())->setRole('assistant')->setCost('2.000000')->save();
        (new AgentMessage())->setConversationId($conversation->getId())->setRole('assistant')->setCost('2.000000')->save();

        self::stageChange($conversation->getId(), $definitionId, StagedChangeData::STATUS_APPLIED);
        self::stageChange($conversation->getId(), $definitionId, StagedChangeData::STATUS_REJECTED);

        $row = self::findRow($this->catalog->all(), $code);
        // (2 + 2) USD lifetime spend / 2 proposals = 2 USD/proposal, converted with the fixed catalog rate (MYO-489/495).
        self::assertSame(round(2.0 * ModelCatalog::usdToEurRate(), 4), $row['costPerProposalEur']);
    }

    /**
     * MYO-508 AC3: the protected conversational copilot has no guided
     * settings button/card, however it is activated.
     */
    public function testGuidedSettingsIsNullForTheProtectedConversationalCopilot(): void
    {
        $row = self::findRow($this->catalog->all(), AgentDefinitionSeeder::MERCHANT_CODE);

        self::assertNull($row['guidedSettings']);
    }

    public function testGuidedSettingsIsNullBeforeActivation(): void
    {
        $row = self::findRow($this->catalog->all(), AgentPresets::CART_ABANDONED);

        self::assertNull($row['agentDefinitionId']);
        self::assertNull($row['guidedSettings']);
    }

    /**
     * MYO-508 AC4: right after activation, `role_prompt` is exactly the
     * preset's own default text, which is always identical to its "warm"
     * tone variant -- so no drift, and the tone selector pre-fills correctly.
     */
    public function testGuidedSettingsDetectsTheMatchingToneWithNoDriftRightAfterActivation(): void
    {
        $this->catalog->activate(AgentPresets::CART_ABANDONED);

        $gs = self::findRow($this->catalog->all(), AgentPresets::CART_ABANDONED)['guidedSettings'];

        self::assertSame('tone', $gs['variantKind']);
        self::assertSame(AgentPresets::TONE_WARM, $gs['currentVariant']);
        self::assertFalse($gs['drift']);
    }

    /**
     * MYO-508 AC4: a role_prompt hand-edited in the expert wizard no longer
     * matches any known variant -- strict text equality, no fuzzy match.
     */
    public function testGuidedSettingsDetectsDriftWhenRolePromptWasHandEdited(): void
    {
        $code = AgentPresets::CUSTOMER_REVIEWS_REPLY;
        $this->catalog->activate($code);
        $definitionId = self::findRow($this->catalog->all(), $code)['agentDefinitionId'];

        AgentDefinitionQuery::create()->findPk($definitionId)->setRolePrompt('Something the merchant typed by hand.')->save();

        $gs = self::findRow($this->catalog->all(), $code)['guidedSettings'];

        self::assertSame('tone', $gs['variantKind']);
        self::assertNull($gs['currentVariant']);
        self::assertTrue($gs['drift']);
    }

    /**
     * A preset with no tone/detail variants (Stuck orders) never reports
     * drift -- there is nothing to compare against.
     */
    public function testGuidedSettingsHasNoVariantSectionForAPresetWithoutVariants(): void
    {
        $this->catalog->activate(AgentPresets::STUCK_ORDERS_WATCH);

        $gs = self::findRow($this->catalog->all(), AgentPresets::STUCK_ORDERS_WATCH)['guidedSettings'];

        self::assertNull($gs['variantKind']);
        self::assertSame([], $gs['variantOptions']);
        self::assertFalse($gs['drift']);
    }

    public function testGuidedSettingsExposesRealThresholdAndScheduleValues(): void
    {
        $this->catalog->activate(AgentPresets::STOCK_WATCH_RESTOCK);
        $stockGs = self::findRow($this->catalog->all(), AgentPresets::STOCK_WATCH_RESTOCK)['guidedSettings'];
        self::assertSame(['kind' => 'threshold', 'value' => 5, 'min' => 1, 'max' => 50], $stockGs['trigger']);

        $this->catalog->activate(AgentPresets::CUSTOMER_REVIEWS_REPLY);
        $reviewsGs = self::findRow($this->catalog->all(), AgentPresets::CUSTOMER_REVIEWS_REPLY)['guidedSettings'];
        self::assertSame(['kind' => 'schedule', 'time' => '10:00'], $reviewsGs['trigger']);

        $this->catalog->activate(AgentPresets::CART_ABANDONED);
        $cartGs = self::findRow($this->catalog->all(), AgentPresets::CART_ABANDONED)['guidedSettings'];
        self::assertSame(['kind' => 'delay_hours', 'value' => 24, 'min' => 1, 'max' => 72, 'minAmount' => null], $cartGs['trigger']);
    }

    public function testSaveGuidedSettingsRejectsAnUnconfirmedDrift(): void
    {
        $code = AgentPresets::CUSTOMER_REVIEWS_REPLY;
        $this->catalog->activate($code);
        $definitionId = self::findRow($this->catalog->all(), $code)['agentDefinitionId'];
        AgentDefinitionQuery::create()->findPk($definitionId)->setRolePrompt('Custom text.')->save();

        $this->expectException(\RuntimeException::class);
        $this->catalog->saveGuidedSettings($code, [
            'variant' => null, 'threshold' => null, 'delayHours' => null, 'minAmount' => null,
            'time' => null, 'dailyCap' => null, 'monthlyBudgetUsd' => null,
            'categoryTitles' => [], 'customerScope' => null,
        ]);
    }

    /**
     * MYO-508 AC2/AC3: full guided save -- variant replaces the whole text,
     * scope sentences are appended after it, the matching trigger's
     * threshold/daily-cap and the monthly budget persist to the real rows.
     */
    public function testSaveGuidedSettingsAppliesVariantThresholdCapScopeAndBudget(): void
    {
        $code = AgentPresets::STOCK_WATCH_RESTOCK;
        $this->catalog->activate($code);
        $definitionId = self::findRow($this->catalog->all(), $code)['agentDefinitionId'];

        $this->catalog->saveGuidedSettings($code, [
            'variant' => AgentPresets::DETAIL_DETAILED,
            'threshold' => 10,
            'delayHours' => null,
            'minAmount' => null,
            'time' => null,
            'dailyCap' => 3,
            'monthlyBudgetUsd' => 15.5,
            'categoryTitles' => ['Chaises'],
            'customerScope' => ScopeCatalog::CUSTOMER_SCOPE_EXCLUDE_RESELLERS,
        ]);

        $definition = AgentDefinitionQuery::create()->findPk($definitionId);
        $expectedPrompt = AgentPresets::find($code)['rolePromptVariants'][AgentPresets::DETAIL_DETAILED]
            .' Concentre-toi uniquement sur les produits des catégories : Chaises.'
            .' N\'inclus jamais les comptes revendeurs dans ton périmètre.';
        self::assertSame($expectedPrompt, $definition->getRolePrompt());
        self::assertSame('15.5000', $definition->getMonthlyBudgetUsd());

        // A scope sentence was appended, so role_prompt no longer equals the pure
        // "detailed" variant text -- AC4's drift detection flags that on reopen too,
        // by design (ScopeCatalog is write-only, never re-read to pre-fill a variant).
        $gs = self::findRow($this->catalog->all(), $code)['guidedSettings'];
        self::assertNull($gs['currentVariant']);
        self::assertTrue($gs['drift']);
        self::assertSame(10, $gs['trigger']['value']);
        self::assertSame(3, $gs['dailyCap']);
    }

    /**
     * Without any category/customer scope selected (the default), the saved
     * text is exactly the chosen variant -- no drift on reopen.
     */
    public function testSaveGuidedSettingsWithoutScopeKeepsTheVariantMatching(): void
    {
        $code = AgentPresets::CUSTOMER_REVIEWS_REPLY;
        $this->catalog->activate($code);

        $this->catalog->saveGuidedSettings($code, [
            'variant' => AgentPresets::TONE_DIRECT,
            'threshold' => null, 'delayHours' => null, 'minAmount' => null,
            'time' => null, 'dailyCap' => null, 'monthlyBudgetUsd' => null,
            'categoryTitles' => [], 'customerScope' => ScopeCatalog::CUSTOMER_SCOPE_ALL,
        ]);

        $gs = self::findRow($this->catalog->all(), $code)['guidedSettings'];
        self::assertSame(AgentPresets::TONE_DIRECT, $gs['currentVariant']);
        self::assertFalse($gs['drift']);
    }

    /**
     * A preset without tone/detail variants (Stuck orders) always saves from
     * its own single preset text, never from whatever role_prompt currently
     * holds -- this is what keeps repeated scope saves from piling up
     * duplicate sentences (see {@see SkillCatalog::saveGuidedSettings()}).
     */
    public function testSaveGuidedSettingsWithoutVariantsResetsToThePresetBaseTextEachTime(): void
    {
        $code = AgentPresets::STUCK_ORDERS_WATCH;
        $this->catalog->activate($code);

        $this->catalog->saveGuidedSettings($code, [
            'variant' => null, 'threshold' => null, 'delayHours' => null, 'minAmount' => null,
            'time' => '11:30', 'dailyCap' => null, 'monthlyBudgetUsd' => null,
            'categoryTitles' => ['Bureaux'], 'customerScope' => null,
        ]);
        $this->catalog->saveGuidedSettings($code, [
            'variant' => null, 'threshold' => null, 'delayHours' => null, 'minAmount' => null,
            'time' => '11:30', 'dailyCap' => null, 'monthlyBudgetUsd' => null,
            'categoryTitles' => ['Étagères'], 'customerScope' => null,
        ]);

        $definitionId = self::findRow($this->catalog->all(), $code)['agentDefinitionId'];
        $definition = AgentDefinitionQuery::create()->findPk($definitionId);
        self::assertSame(
            AgentPresets::find($code)['rolePrompt'].' Concentre-toi uniquement sur les produits des catégories : Étagères.',
            $definition->getRolePrompt(),
        );

        $gs = self::findRow($this->catalog->all(), $code)['guidedSettings'];
        self::assertSame('11:30', $gs['trigger']['time']);
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return array<string, mixed>
     */
    private static function findRow(array $rows, string $code): array
    {
        foreach ($rows as $row) {
            if ($row['code'] === $code) {
                return $row;
            }
        }

        self::fail(\sprintf('No skill row for code "%s"', $code));
    }

    private static function stageChange(int $conversationId, int $agentDefinitionId, string $status): void
    {
        $change = (new AgentStagedChange())
            ->setConversationId($conversationId)
            ->setAgentDefinitionId($agentDefinitionId)
            ->setTargetType('customer_email')
            ->setTargetId(1)
            ->setStatus($status);
        $change->save();
    }
}

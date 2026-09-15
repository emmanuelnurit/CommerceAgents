<?php

declare(strict_types=1);

namespace CommerceAgents\Service\Run;

use CommerceAgents\Agent\AgentEvent;
use CommerceAgents\Agent\AgentRuntime;
use CommerceAgents\Agent\Llm\LlmClientFactoryInterface;
use CommerceAgents\Agent\Llm\LlmMessage;
use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Agent\Tool\ToolRegistry;
use CommerceAgents\Model\AgentDefinition;
use CommerceAgents\Model\AgentRun;
use CommerceAgents\Service\AgentConfigService;
use CommerceAgents\Service\AgentSpendRepository;
use CommerceAgents\Service\BudgetGuard;
use CommerceAgents\Service\ConversationService;
use CommerceAgents\Service\CostCalculator;
use CommerceAgents\Service\Locale\AssistantLocaleResolver;
use CommerceAgents\Service\ModelCatalog;
use CommerceAgents\Service\SystemPromptFactory;
use Psr\Log\LoggerInterface;

/**
 * Executes one queued agent_run: budget gates, a dedicated conversation, the
 * capability-scoped tool loop, then the persisted outcome (plan MYO-226 §3.3
 * to §3.5). The LLM client comes from a factory interface so the whole path
 * runs against a scripted client in tests.
 */
final readonly class AgentRunner
{
    /** Consecutive failures after which the agent is auto-paused (plan §3.3). */
    public const CIRCUIT_BREAKER_THRESHOLD = 3;

    private const SUMMARY_MAX_LENGTH = 2000;

    public function __construct(
        private AgentConfigService $configService,
        private LlmClientFactoryInterface $llmClientFactory,
        private ToolRegistry $toolRegistry,
        private ConversationService $conversationService,
        private SystemPromptFactory $systemPromptFactory,
        private BudgetGuard $budgetGuard,
        private AgentSpendRepository $spendRepository,
        private ModelCatalog $modelCatalog,
        private LoggerInterface $logger,
        private AssistantLocaleResolver $localeResolver,
    ) {
    }

    public function executeRun(AgentRun $run, \DateTimeImmutable $now = new \DateTimeImmutable()): AgentRun
    {
        if ($run->getStatus() !== AgentRunQueue::STATUS_QUEUED) {
            return $run;
        }

        $definition = $run->getAgentDefinition();

        if (!$definition->getEnabled()) {
            return $this->finish($run, AgentRunQueue::STATUS_FAILED, error: 'Agent definition is disabled');
        }

        // Global budget first, then the per-agent one: both gates run BEFORE
        // any LLM call (plan §3.5).
        if ($this->budgetGuard->status($now)->isBlocked()) {
            return $this->finish($run, AgentRunQueue::STATUS_SKIPPED_BUDGET, error: 'Global monthly LLM budget reached');
        }

        $monthlyBudget = $definition->getMonthlyBudgetUsd();
        if ($monthlyBudget !== null && $this->spendRepository->monthlyCost($definition->getId(), $now) >= (float) $monthlyBudget) {
            return $this->finish($run, AgentRunQueue::STATUS_SKIPPED_BUDGET, error: \sprintf('Agent monthly budget of %.4f USD reached', (float) $monthlyBudget));
        }

        // Provider and model are read at every run: a back-office change takes
        // effect on the next execution without any cache to purge (plan §3.8).
        $llmConfig = $this->configService->getLlmConfigForAgent($definition->getProvider(), $definition->getModel());
        if ($llmConfig->apiKey === '') {
            $this->registerFailure($definition);

            return $this->finish($run, AgentRunQueue::STATUS_FAILED, error: \sprintf('No API key configured for provider "%s"', $llmConfig->provider));
        }

        $context = $this->decodeContext($run);
        $locale = $this->localeResolver->forAgentRun();

        $conversation = $this->conversationService->getOrCreate(
            'agent',
            'agent_run:'.$run->getId(),
            null,
            $locale,
            isset($context['admin_id']) ? (int) $context['admin_id'] : null,
        );

        $run
            ->setStatus(AgentRunQueue::STATUS_RUNNING)
            ->setConversationId($conversation->getId())
            ->setStartedAt(\DateTime::createFromImmutable($now));
        $run->save();

        $toolContext = new ToolContext(
            isAdmin: true,
            adminId: isset($context['admin_id']) ? (int) $context['admin_id'] : null,
            conversationId: $conversation->getId(),
            sessionId: 'agent_run:'.$run->getId(),
            locale: $locale,
            agentDefinitionId: $definition->getId(),
            capabilities: $this->capabilityCodes($definition),
        );

        $instruction = $this->instruction($context);
        $this->conversationService->appendMessage($conversation->getId(), 'user', $instruction);

        $runtime = new AgentRuntime(
            $this->llmClientFactory->create($llmConfig->provider),
            $this->toolRegistry,
            max(1, $definition->getMaxIterations()),
        );

        $prices = $this->modelCatalog->pricesFor($llmConfig->provider, $llmConfig->model);
        $costOf = static fn (int $tokensIn, int $tokensOut): ?float => CostCalculator::cost($prices['input'], $prices['output'], $tokensIn, $tokensOut);

        $summary = '';
        $error = null;

        try {
            $assistantText = '';
            $pendingTokensIn = 0;
            $pendingTokensOut = 0;

            foreach ($runtime->runTurn([LlmMessage::user($instruction)], $this->systemPromptFactory->agent($definition->getTitle(), (string) $definition->getRolePrompt(), $locale), $toolContext, $llmConfig) as $event) {
                switch ($event->type) {
                    case AgentEvent::TEXT_DELTA:
                        $assistantText .= $event->payload['text'];
                        break;

                    case AgentEvent::USAGE:
                        $pendingTokensIn += $event->payload['input_tokens'];
                        $pendingTokensOut += $event->payload['output_tokens'];
                        break;

                    case AgentEvent::TOOL_CALL:
                        $this->conversationService->appendMessage(
                            $conversation->getId(),
                            'assistant',
                            $assistantText,
                            [['id' => $event->payload['id'], 'name' => $event->payload['name'], 'arguments' => $event->payload['arguments']]],
                            $pendingTokensIn,
                            $pendingTokensOut,
                            $llmConfig->model,
                            $costOf($pendingTokensIn, $pendingTokensOut),
                        );
                        $assistantText = '';
                        $pendingTokensIn = 0;
                        $pendingTokensOut = 0;
                        break;

                    case AgentEvent::TOOL_RESULT:
                        $this->conversationService->appendMessage(
                            $conversation->getId(),
                            'tool',
                            json_encode($event->payload['result'], \JSON_THROW_ON_ERROR),
                            ['tool_call_id' => $event->payload['id']],
                        );
                        break;

                    case AgentEvent::ERROR:
                        $error = (string) $event->payload['message'];
                        break;
                }
            }

            if ($assistantText !== '') {
                $this->conversationService->appendMessage($conversation->getId(), 'assistant', $assistantText, null, $pendingTokensIn, $pendingTokensOut, $llmConfig->model, $costOf($pendingTokensIn, $pendingTokensOut));
                $summary = $assistantText;
            } elseif ($pendingTokensIn > 0 || $pendingTokensOut > 0) {
                $this->conversationService->addTokensToLatestAssistantMessage($conversation->getId(), $pendingTokensIn, $pendingTokensOut, $llmConfig->model, $costOf($pendingTokensIn, $pendingTokensOut));
            }
        } catch (\Throwable $exception) {
            $error = $exception->getMessage();
            $this->logger->error('[commerce-agents] run crashed: '.$exception->getMessage(), [
                'run_id' => $run->getId(),
                'agent' => $definition->getCode(),
                'exception' => $exception::class,
            ]);
        }

        if ($error !== null) {
            $this->registerFailure($definition);

            return $this->finish($run, AgentRunQueue::STATUS_FAILED, summary: $summary, error: $error);
        }

        $definition->setConsecutiveFailures(0);
        $definition->save();

        return $this->finish($run, AgentRunQueue::STATUS_DONE, summary: $summary);
    }

    /**
     * @return list<string>
     */
    private function capabilityCodes(AgentDefinition $definition): array
    {
        $codes = [];
        foreach ($definition->getAgentCapabilities() as $capability) {
            $codes[] = $capability->getCapability();
        }

        return $codes;
    }

    private function instruction(array $context): string
    {
        $instruction = isset($context['instruction']) && trim((string) $context['instruction']) !== ''
            ? trim((string) $context['instruction'])
            : 'Carry out your mission now.';

        $payload = array_diff_key($context, array_flip(['instruction', 'admin_id']));
        if ($payload !== []) {
            $instruction .= "\n\nTrigger context:\n".json_encode($payload, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT);
        }

        return $instruction;
    }

    private function decodeContext(AgentRun $run): array
    {
        $decoded = json_decode((string) $run->getContext(), true);

        return \is_array($decoded) ? $decoded : [];
    }

    private function registerFailure(AgentDefinition $definition): void
    {
        $failures = $definition->getConsecutiveFailures() + 1;
        $definition->setConsecutiveFailures($failures);

        if ($failures >= self::CIRCUIT_BREAKER_THRESHOLD && $definition->getEnabled()) {
            $definition->setEnabled(0);
            $this->logger->warning('[commerce-agents] agent auto-paused after consecutive failures', [
                'agent' => $definition->getCode(),
                'failures' => $failures,
            ]);
        }

        $definition->save();
    }

    private function finish(AgentRun $run, string $status, string $summary = '', ?string $error = null): AgentRun
    {
        $run
            ->setStatus($status)
            ->setSummary($summary !== '' ? mb_substr($summary, 0, self::SUMMARY_MAX_LENGTH) : null)
            ->setError($error)
            ->setFinishedAt(new \DateTime());
        $run->save();

        return $run;
    }
}

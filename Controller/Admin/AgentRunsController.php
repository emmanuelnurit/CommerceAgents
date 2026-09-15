<?php

declare(strict_types=1);

namespace CommerceAgents\Controller\Admin;

use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;
use CommerceAgents\Model\AgentActionLogQuery;
use CommerceAgents\Model\AgentDefinition;
use CommerceAgents\Model\AgentDefinitionQuery;
use CommerceAgents\Model\AgentRun;
use CommerceAgents\Model\AgentRunQuery;
use CommerceAgents\Model\AgentStagedChangeQuery;
use CommerceAgents\Model\AgentTrigger;
use CommerceAgents\Service\Run\AgentRunQueue;
use CommerceAgents\Service\Run\AgentTriggerType;
use CommerceAgents\Service\Run\TriggerCatalogMapping;
use CommerceAgents\Service\TriggerCatalog;
use Propel\Runtime\ActiveQuery\Criteria;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Translation\Translator;
use Twig\Environment;

/**
 * "Run history" screen (MYO-319/MYO-321): list + detail of `agent_run` rows,
 * the data half of the dashboard CTA the frontend ticket wires up separately.
 */
final readonly class AgentRunsController
{
    private const PER_PAGE = 20;

    /** @var array<string, string> raw agent_run.status => translatable label */
    private const STATUS_LABELS = [
        AgentRunQueue::STATUS_QUEUED => 'Queued',
        AgentRunQueue::STATUS_RUNNING => 'Running',
        AgentRunQueue::STATUS_DONE => 'Done',
        AgentRunQueue::STATUS_FAILED => 'Failed',
        AgentRunQueue::STATUS_SKIPPED_BUDGET => 'Budget exceeded',
    ];

    public function __construct(
        private AdminAccessChecker $access,
        private UrlGeneratorInterface $urlGenerator,
        private TriggerCatalog $triggerCatalog,
        private Translator $translator,
        private Environment $twig,
    ) {
    }

    #[Route('/admin/module/CommerceAgents/agents/runs', name: 'commerceagents_agents_runs', methods: ['GET'])]
    public function list(Request $request): Response
    {
        if ($denied = $this->access->check([], 'commerceagents', AccessManager::VIEW)) {
            return $denied;
        }

        $agentId = (int) $request->query->get('agentId', 0);
        $status = (string) $request->query->get('status', '');
        if ($status !== '' && !\array_key_exists($status, self::STATUS_LABELS)) {
            $status = '';
        }
        $page = max(1, (int) $request->query->get('page', 1));

        $query = AgentRunQuery::create()
            ->orderByStartedAt(Criteria::DESC)
            ->orderById(Criteria::DESC);
        if ($agentId > 0) {
            $query->filterByAgentDefinitionId($agentId);
        }
        if ($status !== '') {
            $query->filterByStatus($status);
        }

        $pager = $query->paginate($page, self::PER_PAGE);

        $rows = [];
        foreach ($pager->getResults() as $run) {
            $rows[] = $this->toRow($run);
        }

        $agents = array_map(
            static fn (AgentDefinition $definition): array => ['id' => $definition->getId(), 'title' => $definition->getTitle()],
            AgentDefinitionQuery::create()->orderByTitle()->find()->getData(),
        );

        return new Response($this->twig->render('@CommerceAgentsModule/backOffice/default-twig/runs/list.html.twig', [
            'runs' => $rows,
            'agents' => $agents,
            'statuses' => $this->statusOptions(),
            'filters' => [
                'agentId' => $agentId > 0 ? $agentId : null,
                'status' => $status !== '' ? $status : null,
            ],
            'currentPage' => $pager->getPage(),
            'lastPage' => $pager->getLastPage(),
        ]));
    }

    #[Route('/admin/module/CommerceAgents/agents/runs/{id}', name: 'commerceagents_agents_runs_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id): Response
    {
        if ($denied = $this->access->check([], 'commerceagents', AccessManager::VIEW)) {
            return $denied;
        }

        $run = AgentRunQuery::create()->findPk($id);
        if ($run === null) {
            throw new NotFoundHttpException(\sprintf('Agent run %d not found', $id));
        }

        $actionLogs = [];
        $stagedChanges = [];
        if ($run->getConversationId() !== null) {
            $actionLogs = AgentActionLogQuery::create()
                ->filterByConversationId($run->getConversationId())
                ->orderById(Criteria::ASC)
                ->find()
                ->getData();
            $stagedChanges = AgentStagedChangeQuery::create()
                ->filterByConversationId($run->getConversationId())
                ->orderById(Criteria::ASC)
                ->find()
                ->getData();
        }

        $definition = $run->getAgentDefinition();

        return new Response($this->twig->render('@CommerceAgentsModule/backOffice/default-twig/runs/detail.html.twig', [
            'run' => [
                'id' => $run->getId(),
                'agentTitle' => $definition->getTitle(),
                'agentEditUrl' => $this->urlGenerator->generate('commerceagents_agents_edit', ['id' => $definition->getId()]),
                'startedAt' => $run->getStartedAt(),
                'finishedAt' => $run->getFinishedAt(),
                'durationLabel' => $this->formatDuration($run->getStartedAt(), $run->getFinishedAt()),
                'statusBadge' => $this->statusBadge($run->getStatus()),
                'triggerLabel' => $this->triggerLabel($run->getAgentTrigger()),
                'summary' => $run->getSummary(),
                'error' => $run->getError(),
            ],
            'actionLogs' => array_map(static fn ($log): array => [
                'id' => $log->getId(),
                'toolName' => $log->getToolName(),
                'capability' => $log->getCapability(),
                'channel' => $log->getChannel(),
                'status' => $log->getStatus(),
                'arguments' => json_decode((string) $log->getArguments(), true) ?? [],
                'error' => $log->getError(),
                'createdAt' => $log->getCreatedAt(),
            ], $actionLogs),
            'stagedChanges' => array_map(static fn ($change): array => [
                'id' => $change->getId(),
                'targetType' => $change->getTargetType(),
                'targetId' => $change->getTargetId(),
                'payloadBefore' => json_decode((string) $change->getPayloadBefore(), true) ?? [],
                'payloadAfter' => json_decode((string) $change->getPayloadAfter(), true) ?? [],
                'status' => $change->getStatus(),
                'error' => $change->getError(),
                'createdAt' => $change->getCreatedAt(),
            ], $stagedChanges),
            'listUrl' => $this->urlGenerator->generate('commerceagents_agents_runs'),
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    private function toRow(AgentRun $run): array
    {
        $definition = $run->getAgentDefinition();

        return [
            'id' => $run->getId(),
            'agentTitle' => $definition->getTitle(),
            'agentEditUrl' => $this->urlGenerator->generate('commerceagents_agents_edit', ['id' => $definition->getId()]),
            'startedAt' => $run->getStartedAt(),
            'durationLabel' => $this->formatDuration($run->getStartedAt(), $run->getFinishedAt()),
            'statusBadge' => $this->statusBadge($run->getStatus()),
            'triggerLabel' => $this->triggerLabel($run->getAgentTrigger()),
            'summaryExcerpt' => self::excerpt($run->getSummary()),
            'errorExcerpt' => self::excerpt($run->getError()),
            'showUrl' => $this->urlGenerator->generate('commerceagents_agents_runs_show', ['id' => $run->getId()]),
        ];
    }

    /**
     * @return list<array{code: string, label: string}>
     */
    private function statusOptions(): array
    {
        $options = [];
        foreach (self::STATUS_LABELS as $code => $label) {
            $options[] = ['code' => $code, 'label' => $this->translator->trans($label, [], 'commerceagents')];
        }

        return $options;
    }

    /**
     * @return array{label: string, class: string}
     */
    private function statusBadge(string $status): array
    {
        [$label, $class] = match ($status) {
            AgentRunQueue::STATUS_DONE => ['Success', 'text-bg-success'],
            AgentRunQueue::STATUS_FAILED, AgentRunQueue::STATUS_SKIPPED_BUDGET => ['Failed', 'text-bg-danger'],
            default => ['In progress', 'text-bg-secondary'],
        };

        return ['label' => $this->translator->trans($label, [], 'commerceagents'), 'class' => $class];
    }

    /**
     * The technical (type, event_name) pair a trigger carries is never a
     * {@see TriggerCatalog} code by itself (MYO-344:
     * {@see \CommerceAgents\Service\Run\TriggerCatalogMapping} is the single
     * source of truth for that mapping), so it goes through the reverse
     * lookup before it can be turned into a business label.
     */
    private function triggerLabel(?AgentTrigger $trigger): string
    {
        if ($trigger === null) {
            return $this->translator->trans('Manual', [], 'commerceagents');
        }

        if ($trigger->getType() === AgentTriggerType::CRON) {
            return $this->translator->trans('Schedule', [], 'commerceagents');
        }

        $code = TriggerCatalogMapping::catalogCodeFor($trigger);

        return $this->triggerCatalog->label($code ?? (string) $trigger->getEventName());
    }

    private function formatDuration(?\DateTimeInterface $startedAt, ?\DateTimeInterface $finishedAt): ?string
    {
        if ($startedAt === null) {
            return null;
        }
        if ($finishedAt === null) {
            return $this->translator->trans('In progress', [], 'commerceagents');
        }

        $seconds = max(0, $finishedAt->getTimestamp() - $startedAt->getTimestamp());
        if ($seconds < 60) {
            return $this->translator->trans('%seconds% s', ['%seconds%' => $seconds], 'commerceagents');
        }

        return $this->translator->trans('%minutes% min %seconds% s', [
            '%minutes%' => intdiv($seconds, 60),
            '%seconds%' => $seconds % 60,
        ], 'commerceagents');
    }

    private static function excerpt(?string $text, int $length = 150): ?string
    {
        if ($text === null || trim($text) === '') {
            return null;
        }

        $trimmed = trim($text);

        return mb_strlen($trimmed) > $length ? mb_substr($trimmed, 0, $length).'…' : $trimmed;
    }
}

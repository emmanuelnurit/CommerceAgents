<?php

declare(strict_types=1);

namespace CommerceAgents\Command;

use CommerceAgents\Model\AgentDefinitionQuery;
use CommerceAgents\Service\Run\AgentRunQueue;
use CommerceAgents\Service\Run\PseudoCronGuard;
use CommerceAgents\Service\Run\RunDueService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Thelia\Model\AdminQuery;

#[AsCommand(
    name: 'commerce-agents:run-due',
    description: 'Queues the due cron and business triggers of the configurable agents, then drains the queued runs',
)]
final class RunDueCommand extends Command
{
    public function __construct(
        private readonly AgentRunQueue $queue,
        private readonly RunDueService $runDueService,
        private readonly PseudoCronGuard $pseudoCronGuard,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('agent', null, InputOption::VALUE_REQUIRED, 'Code of an agent to run manually right now (« Exécuter maintenant »)')
            ->addOption('admin', null, InputOption::VALUE_REQUIRED, 'Login of the administrator a manual run acts for (staged changes authorship)')
            ->addOption('instruction', null, InputOption::VALUE_REQUIRED, 'Extra instruction passed to a manual run')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum number of queued runs to drain', '20');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $now = new \DateTimeImmutable();

        if (($code = (string) $input->getOption('agent')) !== '') {
            $definition = AgentDefinitionQuery::create()->findOneByCode($code);
            if ($definition === null) {
                $output->writeln(\sprintf('<error>Unknown agent "%s"</error>', $code));

                return Command::FAILURE;
            }
            if (!$definition->getEnabled()) {
                $output->writeln(\sprintf('<error>Agent "%s" is disabled; enable it before running it manually</error>', $code));

                return Command::FAILURE;
            }

            $adminId = null;
            if (($login = (string) $input->getOption('admin')) !== '') {
                $admin = AdminQuery::create()->findOneByLogin($login);
                if ($admin === null) {
                    $output->writeln(\sprintf('<error>Administrator "%s" not found</error>', $login));

                    return Command::FAILURE;
                }
                $adminId = $admin->getId();
            }

            $context = [];
            if (($instruction = (string) $input->getOption('instruction')) !== '') {
                $context['instruction'] = $instruction;
            }

            $run = $this->queue->enqueueManual($definition, $context, $adminId);
            $output->writeln(\sprintf('Queued manual run #%d for agent "%s"', $run->getId(), $code));
        }

        $this->pseudoCronGuard->markSystemTick($now);

        $drainedRuns = $this->runDueService->run($now, max(1, (int) $input->getOption('limit')));
        foreach ($drainedRuns as $run) {
            $output->writeln(\sprintf(
                '#%d %s → %s%s',
                $run->getId(),
                $run->getAgentDefinition()->getCode(),
                $run->getStatus(),
                $run->getError() !== null ? ' — '.$run->getError() : '',
            ));
        }

        $output->writeln(\sprintf('%d run(s) processed.', \count($drainedRuns)));

        return Command::SUCCESS;
    }
}

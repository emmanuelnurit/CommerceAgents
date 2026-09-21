<?php

declare(strict_types=1);

namespace CommerceAgents\Command;

use CommerceAgents\Agent\Llm\LlmClientFactory;
use CommerceAgents\Service\AgentConfigService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * MYO-502 AC-c: there was no non-interactive way to set the LLM provider API
 * key — only the back-office form (session + CSRF), which a deploy/demo-prep
 * script cannot safely drive. The key is read from an env var, never from an
 * argument or option, so it never lands in shell history or a process list
 * (`ps aux` on the container would expose a CLI argument to anyone with
 * container access — see RotateSecretsCommand's --old-secret/--new-secret
 * for the pattern this deliberately avoids for the credential itself).
 */
#[AsCommand(
    name: 'commerce-agents:config:set-api-key',
    description: 'Sets the encrypted LLM provider API key from the COMMERCE_AGENTS_API_KEY env var (MYO-502 AC-c)',
)]
final class SetLlmApiKeyCommand extends Command
{
    private const ENV_VAR = 'COMMERCE_AGENTS_API_KEY';

    public function __construct(
        private readonly AgentConfigService $configService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('provider', InputArgument::REQUIRED, \sprintf('LLM provider (%s)', implode(', ', LlmClientFactory::PROVIDERS)))
            ->addOption('model', null, InputOption::VALUE_REQUIRED, 'Model to use for this provider (defaults to the provider\'s built-in default)', '')
            ->addOption('base-url', null, InputOption::VALUE_REQUIRED, 'Optional custom base URL', '')
            ->addOption('activate', null, InputOption::VALUE_NONE, 'Also make this provider the active one')
            ->setHelp(\sprintf('Pass the key via the %s environment variable, never as a CLI argument.', self::ENV_VAR));
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $provider = (string) $input->getArgument('provider');
        if (!\in_array($provider, LlmClientFactory::PROVIDERS, true)) {
            $io->error(\sprintf('Unknown provider "%s". Expected one of: %s', $provider, implode(', ', LlmClientFactory::PROVIDERS)));

            return Command::FAILURE;
        }

        $apiKey = getenv(self::ENV_VAR);
        if ($apiKey === false || $apiKey === '') {
            $io->error(\sprintf('Set the %s environment variable to the key value before running this command.', self::ENV_VAR));

            return Command::FAILURE;
        }

        $this->configService->setProviderSettings(
            $provider,
            $apiKey,
            (string) $input->getOption('base-url'),
            (string) $input->getOption('model'),
        );

        if ($input->getOption('activate')) {
            $this->configService->setProvider($provider);
        }

        $io->success(\sprintf('API key configured for provider "%s"%s.', $provider, $input->getOption('activate') ? ' (now active)' : ''));

        return Command::SUCCESS;
    }
}

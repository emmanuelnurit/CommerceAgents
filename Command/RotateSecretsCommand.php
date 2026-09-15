<?php

declare(strict_types=1);

namespace CommerceAgents\Command;

use CommerceAgents\CommerceAgents;
use CommerceAgents\Service\Channel\ChannelSettingsEncryptor;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Thelia\Model\ModuleConfigQuery;

/**
 * B6 rotation procedure (README § Production deployment prerequisites): the
 * README used to document re-entry by hand in the back office as "the
 * supported path" because no bulk re-encryption command existed. Re-entry by
 * hand does not scale past a couple of rows and is not something a rotation
 * runbook can verify by execution, so this command replaces it -- it decrypts
 * every stored provider API key and channel connector settings blob with the
 * *old* kernel.secret and re-encrypts it with the *new* one, in place. Both
 * secrets are passed explicitly on the command line rather than read from
 * %kernel.secret%, because by the time an operator runs this the container is
 * already compiled against whichever value is currently in .env.local -- only
 * one of the two secrets could ever be that one.
 */
#[AsCommand(
    name: 'commerce-agents:rotate-secrets',
    description: 'Re-encrypts LLM provider API keys and channel connector settings after a kernel.secret rotation (B6)',
)]
final class RotateSecretsCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('old-secret', null, InputOption::VALUE_REQUIRED, 'kernel.secret / APP_SECRET value the stored data is currently encrypted with')
            ->addOption('new-secret', null, InputOption::VALUE_REQUIRED, 'kernel.secret / APP_SECRET value to re-encrypt with')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'List what would be re-encrypted without writing anything');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $oldSecret = (string) $input->getOption('old-secret');
        $newSecret = (string) $input->getOption('new-secret');
        $dryRun = (bool) $input->getOption('dry-run');

        if ('' === $oldSecret || '' === $newSecret) {
            $io->error('Both --old-secret and --new-secret are required.');

            return Command::FAILURE;
        }

        if ($oldSecret === $newSecret) {
            $io->error('--old-secret and --new-secret are identical; nothing to rotate.');

            return Command::FAILURE;
        }

        $oldEncryptor = new ChannelSettingsEncryptor($oldSecret);
        $newEncryptor = new ChannelSettingsEncryptor($newSecret);

        $rotated = 0;
        $skipped = 0;

        foreach (ModuleConfigQuery::create()->filterByModuleId(CommerceAgents::getModuleId())->find() as $config) {
            $name = $config->getName();
            if (!self::isEncryptedSettingName($name)) {
                continue;
            }

            $stored = $config->getValue();
            if (null === $stored || '' === $stored) {
                continue;
            }

            try {
                $plaintext = $oldEncryptor->decryptString($stored);
            } catch (\RuntimeException) {
                $io->warning(\sprintf('%s: could not decrypt with --old-secret (already rotated, corrupted, or pre-encryption legacy plaintext) -- left untouched', $name));
                ++$skipped;
                continue;
            }

            if (!$dryRun) {
                CommerceAgents::setConfigValue($name, $newEncryptor->encryptString($plaintext));
            }

            $io->writeln(\sprintf('%s%s: re-encrypted', $dryRun ? '[dry-run] ' : '', $name));
            ++$rotated;
        }

        $io->success(\sprintf('%s%d row(s) re-encrypted, %d skipped.', $dryRun ? '[dry-run] ' : '', $rotated, $skipped));

        return Command::SUCCESS;
    }

    private static function isEncryptedSettingName(string $name): bool
    {
        return str_starts_with($name, 'api_key_') || 1 === preg_match('/^channel_connector_.+_settings$/', $name);
    }
}

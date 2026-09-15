<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Command;

use CommerceAgents\Command\RotateSecretsCommand;
use CommerceAgents\CommerceAgents;
use CommerceAgents\Service\Channel\ChannelSettingsEncryptor;
use Symfony\Component\Console\Tester\CommandTester;
use Thelia\Test\IntegrationTestCase;

/**
 * MYO-407 B6: covers the command that replaced "re-enter every value by hand
 * after a kernel.secret rotation" (README § Production deployment
 * prerequisites). The end-to-end rotation itself (isolated worktree + real
 * kernel.secret change) was verified manually per the ticket; this test locks
 * in the row-selection and decrypt/re-encrypt logic against regressions.
 */
final class RotateSecretsCommandTest extends IntegrationTestCase
{
    private const OLD_SECRET = 'old-secret-for-rotate-command-test';
    private const NEW_SECRET = 'new-secret-for-rotate-command-test';

    public function testRotatesProviderKeyAndChannelSettingsInPlace(): void
    {
        $oldEncryptor = new ChannelSettingsEncryptor(self::OLD_SECRET);
        CommerceAgents::setConfigValue('api_key_mistral', $oldEncryptor->encryptString('sk-test-provider-key'));
        CommerceAgents::setConfigValue('channel_connector_mail_settings', $oldEncryptor->encrypt(['store_email' => 'shop@example.test']));

        $tester = new CommandTester(new RotateSecretsCommand());
        $exitCode = $tester->execute([
            '--old-secret' => self::OLD_SECRET,
            '--new-secret' => self::NEW_SECRET,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('api_key_mistral: re-encrypted', $tester->getDisplay());
        $this->assertStringContainsString('channel_connector_mail_settings: re-encrypted', $tester->getDisplay());
        $this->assertStringContainsString('2 row(s) re-encrypted, 0 skipped.', $tester->getDisplay());

        $newEncryptor = new ChannelSettingsEncryptor(self::NEW_SECRET);
        $this->assertSame('sk-test-provider-key', $newEncryptor->decryptString((string) CommerceAgents::getConfigValue('api_key_mistral')));
        $this->assertSame(['store_email' => 'shop@example.test'], $newEncryptor->decrypt(CommerceAgents::getConfigValue('channel_connector_mail_settings')));

        $this->expectException(\RuntimeException::class);
        $oldEncryptor->decryptString((string) CommerceAgents::getConfigValue('api_key_mistral'));
    }

    public function testDryRunReportsWithoutWriting(): void
    {
        $oldEncryptor = new ChannelSettingsEncryptor(self::OLD_SECRET);
        $originalStored = $oldEncryptor->encryptString('sk-test-provider-key');
        CommerceAgents::setConfigValue('api_key_mistral', $originalStored);

        $tester = new CommandTester(new RotateSecretsCommand());
        $tester->execute([
            '--old-secret' => self::OLD_SECRET,
            '--new-secret' => self::NEW_SECRET,
            '--dry-run' => true,
        ]);

        $this->assertStringContainsString('[dry-run] api_key_mistral: re-encrypted', $tester->getDisplay());
        $this->assertSame($originalStored, CommerceAgents::getConfigValue('api_key_mistral'));
    }

    public function testRowThatDoesNotDecryptWithOldSecretIsSkippedNotOverwritten(): void
    {
        $legacyPlaintext = 'sk-legacy-plaintext-key';
        CommerceAgents::setConfigValue('api_key_mistral', $legacyPlaintext);

        $tester = new CommandTester(new RotateSecretsCommand());
        $exitCode = $tester->execute([
            '--old-secret' => self::OLD_SECRET,
            '--new-secret' => self::NEW_SECRET,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('could not decrypt with --old-secret', $tester->getDisplay());
        $this->assertStringContainsString('0 row(s) re-encrypted, 1 skipped.', $tester->getDisplay());
        $this->assertSame($legacyPlaintext, CommerceAgents::getConfigValue('api_key_mistral'));
    }

    public function testRefusesWhenOldAndNewSecretsAreIdentical(): void
    {
        $tester = new CommandTester(new RotateSecretsCommand());
        $exitCode = $tester->execute([
            '--old-secret' => self::OLD_SECRET,
            '--new-secret' => self::OLD_SECRET,
        ]);

        $this->assertSame(1, $exitCode);
    }
}

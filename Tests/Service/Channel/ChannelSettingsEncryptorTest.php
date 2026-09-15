<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Service\Channel;

use CommerceAgents\Service\Channel\ChannelSettingsEncryptor;
use PHPUnit\Framework\TestCase;

class ChannelSettingsEncryptorTest extends TestCase
{
    public function testRoundTripReturnsTheOriginalSettings(): void
    {
        $encryptor = new ChannelSettingsEncryptor('app-secret-for-tests');
        $settings = ['url' => 'https://hooks.slack.example/T000/B000/xyz-secret-token'];

        $stored = $encryptor->encrypt($settings);

        $this->assertSame($settings, $encryptor->decrypt($stored));
    }

    public function testStoredPayloadNeverContainsThePlaintextSecret(): void
    {
        $encryptor = new ChannelSettingsEncryptor('app-secret-for-tests');
        $settings = ['url' => 'https://hooks.slack.example/T000/B000/xyz-secret-token'];

        $stored = $encryptor->encrypt($settings);

        $this->assertStringNotContainsString('xyz-secret-token', $stored);
        $this->assertStringNotContainsString('slack', $stored);
    }

    public function testEmptyOrNullPayloadDecryptsToAnEmptyArray(): void
    {
        $encryptor = new ChannelSettingsEncryptor('app-secret-for-tests');

        $this->assertSame([], $encryptor->decrypt(null));
        $this->assertSame([], $encryptor->decrypt(''));
    }

    public function testDecryptingWithADifferentSecretFails(): void
    {
        $stored = (new ChannelSettingsEncryptor('secret-one'))->encrypt(['to' => 'merchant@example.com']);

        $this->expectException(\RuntimeException::class);
        (new ChannelSettingsEncryptor('secret-two'))->decrypt($stored);
    }

    public function testTamperedPayloadFailsToDecrypt(): void
    {
        $encryptor = new ChannelSettingsEncryptor('app-secret-for-tests');
        $stored = $encryptor->encrypt(['to' => 'merchant@example.com']);

        $tampered = substr($stored, 0, -4).'abcd';

        $this->expectException(\RuntimeException::class);
        $encryptor->decrypt($tampered);
    }

    public function testTwoEncryptionsOfTheSameSettingsProduceDifferentCiphertexts(): void
    {
        $encryptor = new ChannelSettingsEncryptor('app-secret-for-tests');
        $settings = ['to' => 'merchant@example.com'];

        $this->assertNotSame($encryptor->encrypt($settings), $encryptor->encrypt($settings));
    }

    /**
     * MYO-276: encryptString/decryptString back AgentConfigService's LLM
     * provider API key storage, the same primitives as the channel settings
     * array above but for a single opaque value (no JSON envelope).
     */
    public function testStringRoundTripReturnsTheOriginalValue(): void
    {
        $encryptor = new ChannelSettingsEncryptor('app-secret-for-tests');

        $stored = $encryptor->encryptString('sk-live-abc123');

        $this->assertSame('sk-live-abc123', $encryptor->decryptString($stored));
        $this->assertStringNotContainsString('sk-live-abc123', $stored);
    }

    public function testDecryptingAPlaintextLegacyValueFails(): void
    {
        $encryptor = new ChannelSettingsEncryptor('app-secret-for-tests');

        $this->expectException(\RuntimeException::class);
        $encryptor->decryptString('sk-live-plaintext-legacy-key');
    }
}

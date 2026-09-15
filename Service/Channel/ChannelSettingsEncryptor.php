<?php

declare(strict_types=1);

namespace CommerceAgents\Service\Channel;

/**
 * Encrypts agent_channel.settings (tokens, webhook URLs…) and LLM provider
 * API keys with sodium secretbox before either ever reaches the database, so
 * a dump or an export never carries a usable secret in the clear (plan
 * MYO-226 §3.6, MYO-276). The key is derived from Thelia's kernel secret via
 * a keyed hash, not stored anywhere else, so a stored blob only decrypts on
 * an install sharing that secret.
 */
final readonly class ChannelSettingsEncryptor
{
    private string $key;

    public function __construct(string $appSecret)
    {
        $this->key = sodium_crypto_generichash($appSecret, '', \SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }

    public function encrypt(array $settings): string
    {
        return $this->encryptString(json_encode($settings, \JSON_THROW_ON_ERROR));
    }

    /**
     * @throws \RuntimeException when the payload is corrupted or was encrypted with a different key
     */
    public function decrypt(?string $stored): array
    {
        if ($stored === null || $stored === '') {
            return [];
        }

        $decoded = json_decode($this->decryptString($stored), true);

        return \is_array($decoded) ? $decoded : [];
    }

    public function encryptString(string $value): string
    {
        $nonce = random_bytes(\SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($value, $nonce, $this->key);

        return base64_encode($nonce.$ciphertext);
    }

    /**
     * @throws \RuntimeException when the payload is corrupted or was encrypted with a different key
     */
    public function decryptString(string $stored): string
    {
        $raw = base64_decode($stored, true);
        if ($raw === false || \strlen($raw) < \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new \RuntimeException('Corrupted encrypted payload');
        }

        $nonce = substr($raw, 0, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = substr($raw, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $this->key);
        if ($plaintext === false) {
            throw new \RuntimeException('Unable to decrypt payload: wrong key or tampered data');
        }

        return $plaintext;
    }
}

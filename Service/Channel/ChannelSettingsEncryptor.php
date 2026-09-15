<?php

declare(strict_types=1);

namespace CommerceAgents\Service\Channel;

/**
 * Encrypts agent_channel.settings (tokens, webhook URLs…) with sodium
 * secretbox before it ever reaches the database, so a dump or an export
 * never carries a usable secret in the clear (plan MYO-226 §3.6). The key is
 * derived from Thelia's kernel secret via a keyed hash, not stored anywhere
 * else, so a settings blob only decrypts on an install sharing that secret.
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
        $nonce = random_bytes(\SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox(json_encode($settings, \JSON_THROW_ON_ERROR), $nonce, $this->key);

        return base64_encode($nonce.$ciphertext);
    }

    /**
     * @throws \RuntimeException when the payload is corrupted or was encrypted with a different key
     */
    public function decrypt(?string $stored): array
    {
        if ($stored === null || $stored === '') {
            return [];
        }

        $raw = base64_decode($stored, true);
        if ($raw === false || \strlen($raw) < \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new \RuntimeException('Corrupted channel settings payload');
        }

        $nonce = substr($raw, 0, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = substr($raw, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $this->key);
        if ($plaintext === false) {
            throw new \RuntimeException('Unable to decrypt channel settings: wrong key or tampered payload');
        }

        $decoded = json_decode($plaintext, true);

        return \is_array($decoded) ? $decoded : [];
    }
}

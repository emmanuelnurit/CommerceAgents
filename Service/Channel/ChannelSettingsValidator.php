<?php

declare(strict_types=1);

namespace CommerceAgents\Service\Channel;

use CommerceAgents\Service\Security\OutboundUrlValidatorInterface;

/**
 * Minimal JSON Schema validation for connector settings (MYO-300): required
 * properties and their "format" (email / uri) are all a connector schema
 * uses today, so a hand-rolled check keeps this generic across any current
 * or future connector without pulling in a JSON Schema library. A "uri"
 * property additionally goes through the SSRF guard (MYO-276) since it is a
 * webhook URL the server will POST to.
 */
final readonly class ChannelSettingsValidator
{
    public function __construct(
        private OutboundUrlValidatorInterface $urlValidator,
    ) {
    }

    /**
     * @return list<string> human-readable error messages; empty means valid
     */
    public function validate(array $schema, array $values): array
    {
        $errors = [];

        foreach ($schema['required'] ?? [] as $name) {
            if (trim((string) ($values[$name] ?? '')) === '') {
                $errors[] = \sprintf('Le champ "%s" est requis.', $schema['properties'][$name]['description'] ?? $name);
            }
        }

        foreach ($schema['properties'] ?? [] as $name => $definition) {
            $value = trim((string) ($values[$name] ?? ''));
            if ($value === '') {
                continue;
            }

            $format = $definition['format'] ?? null;
            if ($format === 'email' && filter_var($value, \FILTER_VALIDATE_EMAIL) === false) {
                $errors[] = \sprintf('"%s" doit être une adresse e-mail valide.', $definition['description'] ?? $name);
            }

            if ($format === 'uri') {
                if (filter_var($value, \FILTER_VALIDATE_URL) === false) {
                    $errors[] = \sprintf('"%s" doit être une URL valide.', $definition['description'] ?? $name);
                } elseif (!$this->urlValidator->isAllowed($value)) {
                    $errors[] = \sprintf('"%s" doit être une URL HTTPS publique (adresse interne ou privée refusée).', $definition['description'] ?? $name);
                }
            }
        }

        return $errors;
    }
}

<?php

declare(strict_types=1);

namespace CommerceAgents\Service;

use CommerceAgents\Agent\Llm\LlmClientFactory;
use CommerceAgents\Agent\Llm\LlmConfig;
use CommerceAgents\Agent\Llm\LlmEvent;
use CommerceAgents\Agent\Llm\LlmMessage;

final readonly class ConnectionTester
{
    public function __construct(
        private LlmClientFactory $llmClientFactory,
    ) {
    }

    /**
     * Sends a one-word probe to the configured provider and reports the outcome.
     * Never returns the API key in any form.
     *
     * @return array{success: bool, message: string}
     */
    public function test(LlmConfig $config): array
    {
        if ($config->apiKey === '') {
            return ['success' => false, 'message' => 'No API key configured'];
        }

        try {
            $client = $this->llmClientFactory->create($config->provider);
        } catch (\InvalidArgumentException $exception) {
            return ['success' => false, 'message' => $exception->getMessage()];
        }

        $probeConfig = new LlmConfig(
            provider: $config->provider,
            model: $config->model,
            apiKey: $config->apiKey,
            baseUrl: $config->baseUrl,
            maxTokens: 16,
        );

        try {
            $events = $client->streamChat(
                [LlmMessage::user('Reply with the single word: pong')],
                [],
                '',
                $probeConfig,
            );

            foreach ($events as $event) {
                if ($event->type === LlmEvent::ERROR) {
                    return ['success' => false, 'message' => $event->text];
                }
                if ($event->type === LlmEvent::TEXT_DELTA || $event->type === LlmEvent::TURN_END) {
                    return [
                        'success' => true,
                        'message' => sprintf('Connection OK (%s / %s)', $config->provider, $config->model),
                    ];
                }
            }

            return ['success' => false, 'message' => 'No response from provider'];
        } catch (\Throwable $exception) {
            return ['success' => false, 'message' => $exception->getMessage()];
        }
    }
}

<?php

declare(strict_types=1);

namespace CommerceAgents\Service\Suggestion;

/**
 * One quick-reply chip rendered under the assistant's last message
 * (MYO-282 §5.1). A "navigate" suggestion always carries a real, resolved
 * store URL; a "message" suggestion relays through the existing chat pipeline
 * exactly like the hero chips already do — no new client behaviour to write.
 */
final readonly class Suggestion
{
    private function __construct(
        public string $id,
        public string $label,
        private string $actionType,
        private ?string $url = null,
        private ?string $text = null,
    ) {
    }

    public static function navigate(string $id, string $label, string $url): self
    {
        return new self($id, $label, 'navigate', url: $url);
    }

    public static function message(string $id, string $label, string $text): self
    {
        return new self($id, $label, 'message', text: $text);
    }

    /**
     * @return array{id: string, label: string, action: array{type: string, url?: string, text?: string}}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'action' => $this->actionType === 'navigate'
                ? ['type' => 'navigate', 'url' => $this->url]
                : ['type' => 'message', 'text' => $this->text],
        ];
    }
}

<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Service;

use CommerceAgents\Agent\Llm\LlmMessage;
use CommerceAgents\Service\LanguageName;
use CommerceAgents\Service\LanguageReminder;
use PHPUnit\Framework\TestCase;

class LanguageReminderTest extends TestCase
{
    public function testTheDirectiveRidesOnTheLastUserTurn(): void
    {
        $history = LanguageReminder::apply([
            LlmMessage::user('Bonjour'),
            LlmMessage::assistant('Hello!'),
            LlmMessage::user('montre-moi deux fauteuils'),
        ], 'en_US');

        $this->assertStringContainsString('montre-moi deux fauteuils', $history[2]->content);
        $this->assertStringContainsString('Write your answer in English', $history[2]->content);
        // Only the current turn carries it, so a replay never stacks directives.
        $this->assertStringNotContainsString('Write your answer in', $history[0]->content);
        $this->assertSame('Hello!', $history[1]->content);
    }

    public function testTheLanguageIsNamedInItselfToo(): void
    {
        $history = LanguageReminder::apply([LlmMessage::user('Hello')], 'fr_FR');

        $this->assertStringContainsString('Write your answer in French (français)', $history[0]->content);
    }

    public function testAnAssistantTailIsSkippedToReachTheUserTurn(): void
    {
        $history = LanguageReminder::apply([
            LlmMessage::user('chairs'),
            LlmMessage::assistant('', []),
            LlmMessage::toolResult('call_1', ['count' => 0]),
        ], 'it_IT');

        $this->assertStringContainsString('Write your answer in Italian (italiano)', $history[0]->content);
        $this->assertSame('tool', $history[2]->role);
    }

    public function testAHistoryWithoutAnyUserTurnIsLeftAlone(): void
    {
        $history = LanguageReminder::apply([LlmMessage::assistant('Hello')], 'en_US');

        $this->assertSame('Hello', $history[0]->content);
    }

    public function testAnEmptyHistoryIsLeftAlone(): void
    {
        $this->assertSame([], LanguageReminder::apply([], 'en_US'));
    }

    public function testAnUnknownLocaleFallsBackToItsCode(): void
    {
        $this->assertSame('xx_XX', LanguageName::of('xx_XX'));
    }
}

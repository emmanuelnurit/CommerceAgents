<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Agent\Llm;

use CommerceAgents\Agent\Llm\AnthropicClient;
use CommerceAgents\Agent\Llm\LlmClientFactory;
use CommerceAgents\Agent\Llm\OpenAiCompatibleClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;

class LlmClientFactoryTest extends TestCase
{
    private function factory(): LlmClientFactory
    {
        return new LlmClientFactory(new MockHttpClient());
    }

    public function testAnthropicProvider(): void
    {
        $this->assertInstanceOf(AnthropicClient::class, $this->factory()->create('anthropic'));
    }

    public function testOpenAiCompatibleProvider(): void
    {
        $this->assertInstanceOf(OpenAiCompatibleClient::class, $this->factory()->create('openai-compatible'));
    }

    public function testUnknownProviderThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->factory()->create('gemini');
    }
}

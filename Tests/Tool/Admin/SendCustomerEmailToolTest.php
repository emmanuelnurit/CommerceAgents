<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Tool\Admin;

use CommerceAgents\Agent\Tool\AgentOutboundMessageLoggerInterface;
use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Tool\Admin\SendCustomerEmailTool;
use PHPUnit\Framework\TestCase;

class FakeOutboundMessageLogger implements AgentOutboundMessageLoggerInterface
{
    /** @var list<array{channel: string, recipient: ?string, status: string, error: ?string, bodyExcerpt: ?string}> */
    public array $logged = [];

    public function log(ToolContext $ctx, string $channel, ?string $recipient, string $status, ?string $error = null, ?string $bodyExcerpt = null): void
    {
        $this->logged[] = ['channel' => $channel, 'recipient' => $recipient, 'status' => $status, 'error' => $error, 'bodyExcerpt' => $bodyExcerpt];
    }
}

class SendCustomerEmailToolTest extends TestCase
{
    /**
     * Automatic-run context (MYO-340): cart_abandoned_relaunch /
     * welcome_new_customer only ever run via AgentRunQueue::enqueue(), which
     * never populates admin_id -- the tool must still work here.
     */
    private function automaticRunContext(): ToolContext
    {
        return new ToolContext(isAdmin: true, adminId: null, conversationId: 9, agentDefinitionId: 3, agentRunId: 77, channel: ToolContext::CHANNEL_RUN);
    }

    public function testGateRequiresAdminWithId(): void
    {
        $tool = new SendCustomerEmailTool(new FakeCustomerAdminGateway(), new FakeStagingGateway());

        $this->assertTrue($tool->isAllowed(new ToolContext(isAdmin: true, adminId: 1)));
        $this->assertFalse($tool->isAllowed(new ToolContext(isAdmin: false)));
        $this->assertFalse($tool->isAllowed(new ToolContext(isAdmin: true, adminId: null)));
    }

    public function testResolvesRecipientFromCustomerIdOnlyNeverFromModelArguments(): void
    {
        $gateway = new FakeCustomerAdminGateway(['customerId' => 42, 'email' => 'jean@example.com']);
        $staging = new FakeStagingGateway(['changeId' => 5, 'status' => 'pending']);
        $tool = new SendCustomerEmailTool($gateway, $staging);

        $tool->execute(['customer_id' => 42, 'body' => 'Bonjour'], $this->automaticRunContext());

        $this->assertSame(['customerId' => 42, 'locale' => 'fr_FR'], $gateway->lastCall);
        $this->assertSame(['stageCustomerEmail', 42, 'jean@example.com', null, 'Bonjour'], $staging->lastCall);
    }

    public function testAlwaysStagesAndNeverSendsDirectly(): void
    {
        $gateway = new FakeCustomerAdminGateway(['customerId' => 42, 'email' => 'jean@example.com']);
        $staging = new FakeStagingGateway(['changeId' => 5, 'status' => 'pending', 'before' => [], 'after' => ['recipient' => 'jean@example.com']]);
        $tool = new SendCustomerEmailTool($gateway, $staging);

        $result = $tool->execute(['customer_id' => 42, 'subject' => 'Bienvenue', 'body' => 'Bonjour'], $this->automaticRunContext());

        $this->assertSame(5, $result['staged_change']['changeId']);
        $this->assertSame('pending', $result['staged_change']['status']);
        $this->assertStringContainsString('requires human approval', $result['message']);
    }

    public function testCustomerNotFoundReturnsErrorWithoutStaging(): void
    {
        $gateway = new FakeCustomerAdminGateway(null);
        $staging = new FakeStagingGateway();
        $tool = new SendCustomerEmailTool($gateway, $staging);

        $result = $tool->execute(['customer_id' => 999, 'body' => 'Bonjour'], $this->automaticRunContext());

        $this->assertArrayHasKey('error', $result);
        $this->assertSame([], $staging->lastCall);
    }

    public function testCustomerWithoutEmailReturnsErrorWithoutStaging(): void
    {
        $gateway = new FakeCustomerAdminGateway(['customerId' => 42, 'email' => null]);
        $staging = new FakeStagingGateway();
        $tool = new SendCustomerEmailTool($gateway, $staging);

        $result = $tool->execute(['customer_id' => 42, 'body' => 'Bonjour'], $this->automaticRunContext());

        $this->assertArrayHasKey('error', $result);
        $this->assertSame([], $staging->lastCall);
    }

    public function testInvalidCustomerIdReturnsErrorWithoutCallingTheGateway(): void
    {
        $gateway = new FakeCustomerAdminGateway(['customerId' => 42, 'email' => 'jean@example.com']);
        $staging = new FakeStagingGateway();
        $tool = new SendCustomerEmailTool($gateway, $staging);

        $result = $tool->execute(['customer_id' => 0, 'body' => 'Bonjour'], $this->automaticRunContext());

        $this->assertArrayHasKey('error', $result);
        $this->assertSame([], $gateway->lastCall);
        $this->assertSame([], $staging->lastCall);
    }

    public function testEmptyBodyReturnsErrorWithoutStaging(): void
    {
        $gateway = new FakeCustomerAdminGateway(['customerId' => 42, 'email' => 'jean@example.com']);
        $staging = new FakeStagingGateway();
        $tool = new SendCustomerEmailTool($gateway, $staging);

        $result = $tool->execute(['customer_id' => 42, 'body' => '   '], $this->automaticRunContext());

        $this->assertArrayHasKey('error', $result);
        $this->assertSame([], $staging->lastCall);
    }

    public function testGatewayErrorIsPassedThroughWithoutLogging(): void
    {
        $gateway = new FakeCustomerAdminGateway(['customerId' => 42, 'email' => 'jean@example.com']);
        $staging = new FakeStagingGateway(['error' => 'No conversation context']);
        $logger = new FakeOutboundMessageLogger();
        $tool = new SendCustomerEmailTool($gateway, $staging, $logger);

        $result = $tool->execute(['customer_id' => 42, 'body' => 'Bonjour'], $this->automaticRunContext());

        $this->assertSame('No conversation context', $result['error']);
        $this->assertSame([], $logger->logged);
    }

    public function testSuccessLogsTheOutboundMessageAsStaged(): void
    {
        $gateway = new FakeCustomerAdminGateway(['customerId' => 42, 'email' => 'jean@example.com']);
        $staging = new FakeStagingGateway(['changeId' => 5, 'status' => 'pending']);
        $logger = new FakeOutboundMessageLogger();
        $tool = new SendCustomerEmailTool($gateway, $staging, $logger);

        $tool->execute(['customer_id' => 42, 'body' => 'Bonjour'], $this->automaticRunContext());

        $this->assertCount(1, $logger->logged);
        $this->assertSame('mail', $logger->logged[0]['channel']);
        $this->assertSame('jean@example.com', $logger->logged[0]['recipient']);
        $this->assertSame(AgentOutboundMessageLoggerInterface::STATUS_STAGED, $logger->logged[0]['status']);
        $this->assertSame('Bonjour', $logger->logged[0]['bodyExcerpt']);
    }

    public function testBodyExcerptIsTruncatedAt280Characters(): void
    {
        $gateway = new FakeCustomerAdminGateway(['customerId' => 42, 'email' => 'jean@example.com']);
        $staging = new FakeStagingGateway(['changeId' => 5, 'status' => 'pending']);
        $logger = new FakeOutboundMessageLogger();
        $tool = new SendCustomerEmailTool($gateway, $staging, $logger);

        $longBody = str_repeat('a', 300);
        $tool->execute(['customer_id' => 42, 'body' => $longBody], $this->automaticRunContext());

        $excerpt = $logger->logged[0]['bodyExcerpt'];
        $this->assertSame(281, mb_strlen($excerpt));
        $this->assertStringEndsWith('…', $excerpt);
    }

    public function testSubjectIsOptionalAndTrimmed(): void
    {
        $gateway = new FakeCustomerAdminGateway(['customerId' => 42, 'email' => 'jean@example.com']);
        $staging = new FakeStagingGateway();
        $tool = new SendCustomerEmailTool($gateway, $staging);

        $tool->execute(['customer_id' => 42, 'subject' => '  Bienvenue  ', 'body' => 'Bonjour'], $this->automaticRunContext());
        $this->assertSame('Bienvenue', $staging->lastCall[3]);

        $tool->execute(['customer_id' => 42, 'body' => 'Bonjour'], $this->automaticRunContext());
        $this->assertNull($staging->lastCall[3]);
    }

    public function testSchema(): void
    {
        $schema = (new SendCustomerEmailTool(new FakeCustomerAdminGateway(), new FakeStagingGateway()))->getInputSchema();

        $this->assertSame(['customer_id', 'body'], $schema['required']);
    }
}

<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Command;

use CommerceAgents\Agent\Tool\ToolRegistry;
use CommerceAgents\Command\McpServeCommand;
use CommerceAgents\Service\ConversationService;
use CommerceAgents\Service\Locale\AssistantLocaleResolver;
use CommerceAgents\Service\Locale\SiteDefaultLocaleProviderInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Routing\RouterInterface;
use Thelia\Test\IntegrationTestCase;

/**
 * MYO-284 B3: `--admin=<login>` resolves that administrator with no password
 * or token check. Requiring a dedicated environment variable is the only
 * confirmation shape that works here: the command's stdin is reserved for
 * the MCP JSON-RPC transport once it starts, so an interactive prompt is not
 * an option (see McpServeCommand::CONFIRM_ENV_VAR docblock).
 *
 * Extends IntegrationTestCase (real DB) rather than a plain TestCase so the
 * "confirmed" test below can exercise the real AdminQuery lookup that sits
 * right after the gate, without stubbing internals of the command.
 */
final class McpServeCommandTest extends IntegrationTestCase
{
    private function command(): McpServeCommand
    {
        return new McpServeCommand(
            new ToolRegistry(),
            new ConversationService(),
            $this->createStub(RouterInterface::class),
            new AssistantLocaleResolver($this->createStub(SiteDefaultLocaleProviderInterface::class)),
        );
    }

    protected function setUp(): void
    {
        parent::setUp();
        // Never let a leftover value from the real environment leak into the test.
        putenv(McpServeCommand::CONFIRM_ENV_VAR);
        unset($_SERVER[McpServeCommand::CONFIRM_ENV_VAR], $_ENV[McpServeCommand::CONFIRM_ENV_VAR]);
    }

    protected function tearDown(): void
    {
        putenv(McpServeCommand::CONFIRM_ENV_VAR);
        unset($_SERVER[McpServeCommand::CONFIRM_ENV_VAR], $_ENV[McpServeCommand::CONFIRM_ENV_VAR]);
        parent::tearDown();
    }

    public function testAdminImpersonationIsRefusedWithoutTheConfirmationEnvVar(): void
    {
        $tester = new CommandTester($this->command());

        $exitCode = $tester->execute(['--admin' => 'admin']);

        $this->assertSame(Command::INVALID, $exitCode);
        $this->assertStringContainsString(McpServeCommand::CONFIRM_ENV_VAR, $tester->getDisplay(true));
    }

    public function testConfirmedRequestReachesTheAdminLookupInsteadOfBeingRefused(): void
    {
        $_SERVER[McpServeCommand::CONFIRM_ENV_VAR] = '1';

        $tester = new CommandTester($this->command());
        // An admin login that cannot exist: reaches AdminQuery and fails
        // there ("not found") instead of stopping at the stdio transport
        // (which would otherwise block this test reading real stdin), which
        // is exactly what proves the confirmation gate let the request past.
        $exitCode = $tester->execute(['--admin' => 'no-such-admin-'.uniqid('', true)]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $display = $tester->getDisplay(true);
        $this->assertStringContainsString('not found', $display);
        $this->assertStringNotContainsString(McpServeCommand::CONFIRM_ENV_VAR, $display);
    }
}

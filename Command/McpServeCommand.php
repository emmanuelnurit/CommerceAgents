<?php

declare(strict_types=1);

namespace CommerceAgents\Command;

use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Agent\Tool\ToolRegistry;
use CommerceAgents\Mcp\Server\McpServer;
use CommerceAgents\Mcp\Server\StdioTransport;
use CommerceAgents\Service\ConversationService;
use CommerceAgents\Service\Locale\AssistantLocaleResolver;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RouterInterface;
use Thelia\Model\AdminQuery;
use Thelia\Model\ConfigQuery;
use Thelia\Model\CurrencyQuery;

#[AsCommand(
    name: 'commerceagents:mcp:serve',
    description: 'Expose the merchant agent tools to an MCP client (Claude Desktop, Claude Code) over stdio',
)]
final class McpServeCommand extends Command
{
    /**
     * `--admin=<login>` impersonates that administrator with no password or
     * token check. Shell access to run this command is already a trust
     * boundary, but without this gate it also lets anyone with partial/shared
     * shell access (e.g. a system account, a CI runner) silently act as a
     * different, more privileged admin (MYO-284 B3, confirmed independently
     * by MYO-278). Requiring this dedicated, explicit opt-in keeps the
     * command usable non-interactively (stdin is reserved for the MCP JSON-RPC
     * transport, so an interactive confirmation prompt is not an option here).
     */
    public const CONFIRM_ENV_VAR = 'COMMERCEAGENTS_MCP_ADMIN_CONFIRMED';

    public function __construct(
        private readonly ToolRegistry $toolRegistry,
        private readonly ConversationService $conversationService,
        private readonly RouterInterface $router,
        private readonly AssistantLocaleResolver $localeResolver,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('admin', null, InputOption::VALUE_REQUIRED, 'Login of the administrator the MCP client acts as')
            ->addOption('locale', null, InputOption::VALUE_REQUIRED, 'Locale of the tool results (default: the administrator locale)')
            ->addOption('base-url', null, InputOption::VALUE_REQUIRED, 'Public base URL used in generated links (default: url_site config)')
            ->addOption('debug', null, InputOption::VALUE_NONE, 'Log protocol messages on stderr');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $errorOutput = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;

        $login = (string) $input->getOption('admin');
        if ($login === '') {
            $errorOutput->writeln('<error>--admin=<login> is required</error>');

            return Command::INVALID;
        }

        if (!$this->isConfirmed()) {
            $errorOutput->writeln(\sprintf(
                '<error>--admin=%s impersonates that administrator with no further check. Set %s=1 in the environment running this command to confirm you intend that.</error>',
                $login,
                self::CONFIRM_ENV_VAR,
            ));

            return Command::INVALID;
        }

        $admin = AdminQuery::create()->findOneByLogin($login);
        if ($admin === null) {
            $errorOutput->writeln(\sprintf('<error>Administrator "%s" not found</error>', $login));

            return Command::FAILURE;
        }

        $locale = (string) ($input->getOption('locale') ?: $this->localeResolver->forAdmin($admin->getLocale()));
        $this->configureRouterContext((string) ($input->getOption('base-url') ?: ConfigQuery::read('url_site', '')));

        $conversation = $this->conversationService->getOrCreate('merchant', 'mcp:'.$login, null, $locale, $admin->getId());

        $toolContext = new ToolContext(
            isAdmin: true,
            adminId: $admin->getId(),
            conversationId: $conversation->getId(),
            sessionId: 'mcp:'.$login,
            locale: $locale,
            currencyCode: CurrencyQuery::create()->filterByByDefault(true)->findOne()?->getCode() ?? 'EUR',
        );

        // Nothing but JSON-RPC may reach stdout.
        ini_set('display_errors', 'stderr');

        $logger = $input->getOption('debug')
            ? static fn (string $message) => $errorOutput->writeln('<comment>[mcp]</comment> '.$message)
            : null;

        (new StdioTransport(logger: $logger))->serve(new McpServer($this->toolRegistry, $toolContext));

        return Command::SUCCESS;
    }

    private function isConfirmed(): bool
    {
        $value = $_SERVER[self::CONFIRM_ENV_VAR] ?? getenv(self::CONFIRM_ENV_VAR);

        return \in_array(\is_string($value) ? strtolower($value) : $value, ['1', 'true', 'yes', true], true);
    }

    private function configureRouterContext(string $baseUrl): void
    {
        if ($baseUrl === '') {
            return;
        }

        $this->router->getContext()->fromRequest(Request::create($baseUrl));
    }
}

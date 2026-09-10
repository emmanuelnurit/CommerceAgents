<?php

declare(strict_types=1);

namespace CommerceAgents\Command;

use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Agent\Tool\ToolRegistry;
use CommerceAgents\Mcp\Server\McpServer;
use CommerceAgents\Mcp\Server\StdioTransport;
use CommerceAgents\Service\ConversationService;
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
    /** Browser navigation tools make no sense outside the back-office chat. */
    private const HIDDEN_TOOLS = ['open_admin_page', 'open_page'];

    public function __construct(
        private readonly ToolRegistry $toolRegistry,
        private readonly ConversationService $conversationService,
        private readonly RouterInterface $router,
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

        $admin = AdminQuery::create()->findOneByLogin($login);
        if ($admin === null) {
            $errorOutput->writeln(sprintf('<error>Administrator "%s" not found</error>', $login));

            return Command::FAILURE;
        }

        $locale = (string) ($input->getOption('locale') ?: ($admin->getLocale() ?: 'en_US'));
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

        (new StdioTransport(logger: $logger))->serve(new McpServer($this->toolRegistry, $toolContext, self::HIDDEN_TOOLS));

        return Command::SUCCESS;
    }

    private function configureRouterContext(string $baseUrl): void
    {
        if ($baseUrl === '') {
            return;
        }

        $this->router->getContext()->fromRequest(Request::create($baseUrl));
    }
}

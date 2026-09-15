# CommerceAgents

AI assistants for a Thelia 3 store, powered by LLM agents with tool calling:

- **Shopping assistant** (front office): a chat widget that searches the catalog, explains products, fills the cart, points to the checkout, tracks the customer's orders and answers policy questions.
- **Merchant assistant** (back office): a chat for administrators that reads sales analytics, listings, stock, prices and campaigns, and proposes price or stock changes that a human approves before anything is written.
- **MCP server**: the merchant tools exposed to Claude Desktop, Claude Code or any Model Context Protocol client, with the same gates and approval flow.

Providers: Anthropic (default), Mistral, and any OpenAI-compatible API (OpenAI, OpenRouter…). Prompts and UI in English and French; the assistants answer in the session language.

Inspired by the [anthropics/commerce-agents](https://github.com/anthropics/commerce-agents) blueprint, ported natively to PHP so it runs wherever Thelia runs.

## Requirements

- Thelia 3.0 with the `default-twig` back-office theme (the merchant pages extend its layout and use its repositories)
- PHP 8.3 or later
- An API key for at least one provider

## Installation

```bash
# from the Thelia project root
git clone https://github.com/emmanuelnurit/CommerceAgents.git local/modules/CommerceAgents
php Thelia module:refresh
php Thelia module:activate CommerceAgents
php Thelia cache:clear
```

Activation creates the four module tables and seeds the model catalog. Upgrades run the SQL files in `Config/update/` and re-seed the catalog without touching rows edited by hand.

Then open **Modules › CommerceAgents › Configure** in the back office and set a provider key. The front widget and both assistants stay silent until a key is configured.

## Configuration

Everything lives in the module configuration page (`/admin/module/configure?module_code=CommerceAgents`), in five tabs:

| Tab | Settings |
|---|---|
| Dashboard | Active provider and model, month-to-date spend, alerts |
| Providers | One API key, base URL and model per provider; the active provider; a connection test against the configured model |
| Models | Catalog of models with prices (USD per million tokens) and context window. Bundled from `Config/models.php`, refreshable from the provider API, editable by hand. Only enabled models are offered in the provider tab |
| Assistants | Assistant name, front chat on/off, cart / checkout / orders capabilities, daily message limit per visitor session, ids of the contents used as policies |
| Budget | Monthly budget in USD for both assistants together, warning threshold, optional hard pause when the budget is reached |
| Usage | Calls, tokens and cost today, this month and per model over 30 days |

Settings are stored as module config values (`CommerceAgents::getConfigValue()`); API keys never reach the browser after being saved.

## Shopping assistant (front office)

- Injected on every page through the Flexy theme hook `layout.body.bottom`; no template change needed. Alpine.js and the CSS ship with the module, without a build step.
- Endpoint `POST /agent/chat`, Server-Sent Events response. The widget keeps the conversation across pages in `sessionStorage` and shows a cart reminder with a checkout call to action.
- Works for guests. Order history and profile require a logged-in customer and never expose another customer's data.

Tools available to the model:

| Tool | Role |
|---|---|
| `search_products` | keyword search with category and price filters, taxed prices in the session currency |
| `get_product_details` | description, variants, availability, prices |
| `add_to_cart`, `get_cart` | current session cart only |
| `prepare_checkout` | cart summary and checkout link; never triggers a payment |
| `get_orders`, `get_my_profile` | logged-in customer only |
| `get_policies` | terms, shipping and return contents |
| `get_site_pages`, `open_page` | find store pages and navigate the visitor's browser to them |

## Merchant assistant (back office)

Menu entry **Merchant Agent** (`/admin/merchant-agent`), plus a popup chat available on every admin screen. Restricted to administrators with the `commerceagents` module permission (view to chat, update to approve proposals).

| Tool | Role |
|---|---|
| `get_analytics` | revenue, order count, top sellers, status breakdown over a sliding period |
| `get_listings` | products with visibility, position, category and back-office URL |
| `get_inventory` | variants sorted by stock, optional low-stock threshold |
| `get_pricing` | prices and promo per variant |
| `get_campaigns` | coupons and catalog sales |
| `get_admin_pages`, `open_admin_page` | find back-office screens and navigate to them |
| `update_price`, `update_stock` | **create a pending proposal**, nothing is written |

### Proposed changes

Every write lands in `agent_staged_change` with the before/after payload and the proposing administrator. The **Proposed changes** console (`/admin/merchant-agent/changes`) lists them; approving applies the change through the standard Thelia event (`PRODUCT_UPDATE_PRODUCT_SALE_ELEMENT`), so caches, hooks and listeners run as if the change came from the product form. Rejections and application errors are kept for audit.

Adding a new kind of write: one tool that stages a change, plus one `ChangeApplierInterface` implementation for its `target_type`. Both are auto-registered.

## Configurable agents — triggers

Configurable agents (`agent_definition`) run outside a chat, on a trigger (`agent_trigger`). No trigger ever executes an agent inline in an HTTP request: it only inserts a queued `agent_run`, deduplicated by `dedup_key`. A separate drain step, `commerce-agents:run-due`, turns queued runs into LLM calls.

| Trigger `type` | Fires on | `conditions` JSON |
|---|---|---|
| `event` | One of the whitelisted Thelia events (`event_name`): order paid, order status changed, new customer account | `target_statuses` (status ids, order status change only), `min_amount` |
| `cron` | Its own `cron_expression` (« Planification ») | — |
| `abandoned_cart` | `cron_expression`, then a query for carts with items and no order, older than `delay_hours` | `delay_hours` (default 24) |
| `low_stock` | `cron_expression`, then a query for visible sale elements at or under `threshold` | `threshold` (default 5) |

**Install a real cron** (recommended):

```cron
* * * * * php /path/to/thelia Thelia commerce-agents:run-due >> var/log/commerce-agents-run-due.log 2>&1
```

Without one, a **pseudo-cron fallback** drains the queue from back-office traffic once the real cron has not ticked for 10 minutes, at most once every 5 minutes — enough to keep triggers moving on a store with no crontab access, never a substitute for one at any real volume.

## Channels

A configurable agent can push a message out through `send_to_channel`, the single channel tool exposed to the model (capability `channels.send`). The channel — mail, a Mattermost/Slack webhook, or a third-party one — is picked by the agent's `agent_channel` configuration, never by the model.

| `agent_channel.mode` | Behaviour |
|---|---|
| `draft` (default) | The message becomes a pending `agent_staged_change` (`target_type = channel_message`). Nothing leaves until an administrator approves it in **Proposed changes**; approval runs the same connector as `direct` would have. |
| `direct` | The connector sends the message immediately. |

Built-in connectors: `mail` (Thelia's mailer) and `webhook` (generic incoming-webhook POST of `{"text": "..."}`, which both Mattermost and Slack accept — no per-provider integration needed for V1).

`agent_channel.settings` (webhook URLs, tokens…) is encrypted with sodium `secretbox` before it reaches the database (`ChannelSettingsEncryptor`, key derived from `kernel.secret`); `TheliaChannelGateway` decrypts it transparently when a tool or applier needs it.

Registering a connector from any module: implement `Channel\ChannelConnectorInterface` under an autowired/autoconfigured service — the `commerce_agents.channel_connector` tag is applied automatically to every implementation, exactly like `ToolInterface` and `ChangeApplierInterface`, so `ChannelConnectorRegistry` picks it up with no further wiring.

## MCP server

```bash
php Thelia commerceagents:mcp:serve --admin=<login> [--locale=fr_FR] [--base-url=https://store.tld] [--debug]
```

- Transport: stdio, newline-delimited JSON-RPC 2.0. Protocol versions 2024-11-05 through 2025-11-25.
- Exposes the merchant tools above except the navigation ones. Writes go through the same proposal flow.
- `--admin` is required: the client acts as that administrator. `--base-url` fixes generated links, since the CLI has no request (defaults to the `url_site` setting).
- No LLM call is made by the store: the MCP client brings its own model.
- The back office documents it, with ready-to-paste Claude Desktop and Claude Code configuration, under **Merchant Agent › MCP server**.

Claude Desktop entry:

```json
{
  "mcpServers": {
    "thelia": {
      "command": "php",
      "args": ["/path/to/thelia/Thelia", "commerceagents:mcp:serve", "--admin=thelia", "--base-url=https://store.tld"]
    }
  }
}
```

Security model: whoever can run the command acts as the given administrator. There is no HTTP transport and no API key; keep it to local or containerized access.

## Architecture

```
Agent/          AgentRuntime (LLM loop + tool calls, streaming events), LLM clients, ToolRegistry, ToolContext
Tool/           Shopping/*, Admin/* and Channel/* tools; each depends on a gateway interface
Channel/        ChannelConnectorInterface, registry, mail/webhook connectors
Service/        Thelia gateways (Propel, DataAccessService, events), config, budget, cost, conversations, streaming
StagedChange/   proposal manager, appliers, repository contract
Mcp/            JSON-RPC framing, MCP server, stdio transport, tool catalog
Controller/     Front chat endpoint; admin chat, proposals console, MCP page, configuration actions
Hook/           Back-office hooks (menu, popup chat, configuration page) and the front theme hook
Command/        commerceagents:mcp:serve
Config/         module.xml, Propel schema, SQL, bundled model catalog
templates/      Twig for the back office (default-twig) and the front widget assets
Tests/          PHPUnit unit tests with fake gateways
```

Key points:

- **One tool catalog, three consumers.** The chat runtimes and the MCP server all call `ToolRegistry::getToolSpecs()` and `ToolRegistry::execute()`. A tool is exposed to the model only if `isAllowed(ToolContext)` says so, and its arguments are validated against its JSON schema before execution.
- **ToolContext** carries who is talking (admin id or customer id), the conversation, the locale and the currency. Tools never read the session themselves.
- **Gateways** isolate Thelia access behind interfaces (`Tool/*/Gateway/*Interface.php`), which keeps tools unit-testable with fakes.
- **Streaming**: `ChatStreamer` freezes a copy of the session before opening the SSE stream, so mid-stream readers (tax engine, security context) never restart the PHP session after headers are sent.
- **LLM clients** normalize Anthropic, Mistral and OpenAI-style tool calling to one `LlmEvent` stream; `HistorySanitizer` keeps tool call / result pairs consistent across providers.
- **Costs**: every assistant message stores tokens, model and cost; `BudgetGuard` blocks new turns when the monthly budget is exhausted and blocking is enabled.

### Database

| Table | Content |
|---|---|
| `agent_conversation` | type (`shopping` / `merchant`), customer or admin id, session reference, locale |
| `agent_message` | role, content, tool calls, tokens in/out, model, cost |
| `agent_staged_change` | conversation, admin, target type and id, before/after payloads, status, approver, error |
| `agent_model` | provider, model id, name, prices, context window, enabled, source |
| `agent_channel` | agent definition, connector code, encrypted settings, mode (`draft`/`direct`), enabled |

### Security

- Tool calls are validated server-side: schema, then context gate. The model never sees data outside the caller's scope.
- Merchant writes always go through human approval; the console needs the update permission on the module.
- Provider keys stay server-side; the front widget only talks to `/agent/chat`.
- Message length is capped, visitors get a daily message limit, and the monthly budget caps spend.

## Development

```bash
vendor/bin/phpunit                                   # module test suite
php Thelia cache:clear                               # after adding a hook, tool or command
```

Most tests run without a database: tools are tested against fake gateways, the runtime against a fake LLM client, the MCP server against an in-memory registry. The trigger detection queries (`Tests/EventListener`, `Tests/Service/Run`) are the exception — they exercise real Thelia events and Propel fixtures through `Thelia\Test\IntegrationTestCase`, so they need the Thelia test database (`php bin/test-prepare` from the project root) and run from there, e.g. `vendor/bin/phpunit -c phpunit.xml.dist local/modules/CommerceAgents/Tests/Service/Run/AgentRunQueueTest.php`.

Adding a tool:

1. Implement `Agent\Tool\ToolInterface` under `Tool/Shopping` or `Tool/Admin`, behind a gateway interface.
2. Alias the gateway interface to its Thelia implementation in `CommerceAgents::configureServices()`.
3. Write the unit test with a fake gateway. The tool is picked up automatically by the registry, the chats and the MCP server.

## License

LGPL-3.0-or-later.

# CommerceAgents

AI assistants for a Thelia 3 store, powered by LLM agents with tool calling:

- **Shopping assistant** (front office): a chat widget that searches the catalog, explains products, fills the cart, points to the checkout, tracks the customer's orders and answers policy questions.
- **Merchant assistant** (back office): a chat for administrators that reads sales analytics, listings, stock, prices and campaigns, and proposes price or stock changes that a human approves before anything is written.
- **MCP server**: the merchant tools exposed to Claude Desktop, Claude Code or any Model Context Protocol client, with the same gates and approval flow.

Providers for the chat assistants: **Mistral (`ministral-3b-latest`, default)**, Anthropic, and any OpenAI-compatible API (OpenAI, OpenRouter…) — one active provider at a time, picked in the **Providers** configuration tab. Configurable agents (below) are Mistral-only in V1. Prompts and UI in English, French, Spanish and Italian; the assistants answer in the session language.

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

> **Branch note.** The `main` branch on GitHub currently lags the `myorg` development branch: most of what this README describes (default Mistral provider, configurable agents, triggers, channels, proactive shopping assistant) has not been merged upstream yet (tracked as MYO-241, blocked on a GitHub token). Until that merge lands, check out `myorg` explicitly after cloning (`git checkout myorg`) to get the version documented here.

Activation creates the module's tables (see **Database** below) and seeds the model catalog. Upgrades run the SQL files in `Config/update/` and re-seed the catalog without touching rows edited by hand.

Then open **Modules › CommerceAgents › Configure** in the back office and set a provider key. The front widget and both assistants stay silent until a key is configured.

## Configuration

Everything lives in the module configuration page (`/admin/module/configure?module_code=CommerceAgents`). The top of the page is always visible: a **Mistral hero card** (API key, default model, connection test, key-configured status badge) and a **spend follow-up card** (spend this month, budget bar, AI calls this month). The gear icon opens a **General settings** panel with five tabs:

| Tab | Settings |
|---|---|
| Providers | One API key, base URL and model per provider; the active provider; a connection test against the configured model |
| Models | Catalog of models with prices and context window (prices are per-provider currency — EUR for Mistral, USD for Anthropic and OpenAI-compatible; see below). Bundled from `Config/models.php`, refreshable from the provider API, editable by hand. Only enabled models are offered in the provider tab |
| Assistants | Assistant name, front chat on/off, cart / checkout / orders capabilities, daily message limit per visitor session, ids of the contents used as policies |
| Budget | Monthly budget for both assistants together, warning threshold, optional hard pause when the budget is reached |
| Usage | Calls, tokens and cost today, this month and per model over 30 days |

Settings are stored as module config values (`CommerceAgents::getConfigValue()`); API keys never reach the browser after being saved.

Mistral publishes its tariffs in EUR; `Service/ModelCatalog` converts them to USD (rate maintained in `Config/models.php`) so the shopping/merchant assistants' budget, spend and usage figures stay in one currency (USD) internally, regardless of which provider is active. The **Models** tab still shows each model's price in the currency its provider bills in (EUR for Mistral, USD for Anthropic and OpenAI-compatible). Configurable agents (below) are the one place a merchant enters a budget directly in EUR — it is converted to USD on save for the same internal accounting.

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

### Proactive assistant

Alongside the reactive chat, the widget's JS (`chat-widget.js`) watches visitor behaviour in `sessionStorage` and calls `POST /agent/chat/proactive-check` to surface an unprompted bubble for one of seven scenarios, each a `ProactiveScenarioResolverInterface` implementation registered under `commerce_agents.proactive_scenario_resolver`:

| Scenario | Signal | What it offers |
|---|---|---|
| Hesitation | repeated cart-drawer opens or idle time on a product | help finding what the visitor is looking for |
| Abandoned cart | cart idle in session | a nudge back to checkout |
| Cross-sell promo | a promoted product viewed | a related item on promotion |
| Stock rupture | a low/out-of-stock product viewed | in-stock alternatives |
| Eligible coupon | an item added to cart that matches an active coupon | the matching coupon |
| Coupon tier (palier) | same as above, ≥2 amount-tiered coupons configured | a progress bar to the next discount tier |
| Welcome coupon | first visit of a session, a coupon that looks like a welcome offer | that coupon |

`Service/ProactiveGuard` gates every bubble, in order: was it dismissed this session → minimum delay since the last one (90s) → not the same scenario repeated → monthly budget not exhausted. `POST /agent/chat/proactive-apply-coupon` and `POST /agent/chat/proactive-dismiss` handle the visitor's response. State lives on `agent_conversation` (`proactive_*` columns), never exposed to another visitor.

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

`GET /admin/merchant-agent/changes/agent/{agentDefinitionId}/suggestions` returns pending changes for one configurable agent as pre-formatted JSON (`{"suggestions": [{id, targetType, icon, accent, title, body, ctaLabel, createdAt}, ...]}`) — no business logic ships to the client. This is the data contract the back-office dashboard's "AI agents" card and suggestions popup (`default-twig` theme, outside this module) consume. `POST /admin/merchant-agent/changes/{id}/approve|reject` accept the same AJAX call the popup makes, returning HTTP 409 (`already_handled`) for a double-click and 422 for a real error, so the popup can stay non-blocking.

## Configurable agents

Menu entry **AI agents** (`/admin/module/CommerceAgents/agents`) lets a merchant create standing agents that run outside any chat. Each agent (`agent_definition`) has a name, a free-text role prompt, a model (picked from the Mistral catalog only in V1 — configurable agents do not yet support Anthropic or OpenAI-compatible models), a monthly budget in EUR, one or more capabilities, one or more triggers, and at most one channel.

Creating one starts from either a blank form or one of four presets (`Service/AgentPresets`) that pre-fill the role prompt, model tier, trigger and channel — nothing is persisted until the merchant saves:

| Preset | Role | Default trigger | Channel |
|---|---|---|---|
| Abandoned cart relaunch | Gentle e-mail reminder 24h after a cart is abandoned | `cart_abandoned`, 24h | mail |
| Welcome new customers | Personalised welcome message on sign-up | `new_customer` | mail |
| Daily sales summary | End-of-day report on revenue, orders, best seller | `schedule`, 19:00 | webhook |
| Start from scratch | Everything blank | — | — |

Capabilities (`agent_capability`, catalog in `Service/CapabilityCatalog`) are grouped **read** (catalog, content, customers, orders, analytics — no approval needed) and **write** (prices, stock, cart, checkout — every write still lands as a pending `agent_staged_change`, same approval flow as the merchant chat). `channels.send` is its own capability, ungrouped.

The card list shows each agent's last run with a status badge: queued (hourglass), running (spinner), done (green check), failed or skipped for budget (red warning), or "never run yet". **Run now** executes an agent immediately, bypassing its triggers, from the same card.

> **V1 limitation.** The channel step of the wizard only records the merchant's intent (`agent_channel` row, connector `email` or `webhook`); a real settings form (webhook URL, from-address…) is a placeholder pending MYO-231. Until then, wire channel settings through the preset defaults or by hand in the database.

### Triggers

Triggers (`agent_trigger`) decide when a configurable agent runs. No trigger ever executes an agent inline in an HTTP request: it only inserts a queued `agent_run`, deduplicated by `dedup_key`. A separate drain step, `commerce-agents:run-due`, turns queued runs into LLM calls.

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
Agent/          AgentRuntime (LLM loop + tool calls, streaming events), LLM clients, ToolRegistry, ToolContext, Proactive/ resolver interface + 2 resolvers
Tool/           Shopping/*, Admin/* and Channel/* tools; each depends on a gateway interface
Channel/        ChannelConnectorInterface, registry, mail/webhook connectors
Service/        Thelia gateways (Propel, DataAccessService, events), config, budget, cost, conversations, streaming,
                 configurable agents (AgentDefinitionManager, presets, capability/trigger catalogs, run queue),
                 proactive guard/session and the remaining scenario resolvers (Proactive/, plus two at the Service/ root)
StagedChange/   proposal manager, appliers, repository contract
Mcp/            JSON-RPC framing, MCP server, stdio transport, tool catalog
Controller/     Front chat + proactive endpoints; admin chat, agents CRUD, proposals console, MCP page, configuration actions
Hook/           Back-office hooks (menu, popup chat, configuration page) and the front theme hook
Command/        commerceagents:mcp:serve, commerce-agents:run-due
Config/         module.xml, Propel schema, SQL, bundled model catalog
templates/      Twig for the back office (default-twig) and the front widget assets
Tests/          PHPUnit unit tests with fake gateways
docs/design/    Committed HTML mockups (e.g. the dashboard suggestions popup)
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
| `agent_conversation` | type (`shopping` / `merchant`), customer or admin id, session reference, locale, proactive bubble state |
| `agent_message` | role, content, tool calls, tokens in/out, model, cost |
| `agent_staged_change` | conversation or agent definition, admin, target type and id, before/after payloads, status, approver, error |
| `agent_model` | provider, model id, name, prices, currency, context window, enabled, source |
| `agent_definition` | title, role prompt, model, monthly budget, auto-apply, enabled — a configurable agent |
| `agent_capability` | agent definition, capability code (read/write) |
| `agent_trigger` | agent definition, type (`event`/`cron`/`abandoned_cart`/`low_stock`), cron expression, `conditions` JSON |
| `agent_run` | agent definition, trigger, status (`queued`/`running`/`done`/`failed`/`skipped_budget`), `dedup_key`, timestamps |
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

## Known limitations (V1)

- The `main` branch on GitHub lags the `myorg` development branch — see the branch note under **Installation**.
- Configurable agents only offer Mistral models in the wizard; the shopping and merchant chat assistants can use any configured provider.
- The channel step of the agent wizard records intent only; a real per-connector settings form is pending (MYO-231).
- UI and prompts ship in English, French, Spanish and Italian (`I18n/`) — narrower than the back-office theme's locale set.
- No dedicated screen lists past `agent_run` rows; a run's outcome is read from the "Last run" badge on its agent's card, or from `var/log/commerce-agents-run-due.log` if a real cron is installed.

See `CHANGELOG.md` for the full V1 feature list and `docs/guide-exploitation.md` (French) for a short day-to-day operator guide.

## License

LGPL-3.0-or-later.

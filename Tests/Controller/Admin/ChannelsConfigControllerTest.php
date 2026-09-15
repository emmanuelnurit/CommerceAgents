<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Controller\Admin;

use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Model\AgentChannel;
use CommerceAgents\Model\AgentDefinition;
use CommerceAgents\Service\Channel\ChannelConnectorConfigService;
use CommerceAgents\Service\Channel\TheliaChannelGateway;
use CommerceAgents\Tool\Channel\SendToChannelTool;
use Propel\Runtime\Propel;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Thelia\Model\ConfigQuery;
use Thelia\Model\ModuleConfigQuery;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;

/**
 * End-to-end coverage of the "Canaux" tab (MYO-300): real routes, real DI
 * container, real DB — not just the unit-level connector/service tests.
 * Proves the full "configure once centrally, an agent picks it up" pipeline
 * the ticket's acceptance criteria describe.
 */
final class ChannelsConfigControllerTest extends WebIntegrationTestCase
{
    private AdminSessionInjector $injector;

    protected function setUp(): void
    {
        parent::setUp();

        // ModuleConfigQuery caches values in a process-wide static; each test
        // runs in its own rolled-back transaction, but the cache survives the
        // rollback, so a value read/written by a previous test would
        // otherwise leak in here as a false positive.
        ModuleConfigQuery::resetConfigCache();
        // Same leakage risk for the core `config` table (MYO-332): a test
        // writing store_email must not bleed into the next one.
        ConfigQuery::resetCache();

        $this->injector = new AdminSessionInjector();
        $this->getService(EventDispatcherInterface::class)->addSubscriber($this->injector);

        $factory = new FixtureFactory($this->getPropelConnection());
        $admin = $factory->admin();
        $admin->eraseCredentials();
        $this->injector->setAdmin($admin);
    }

    protected function tearDown(): void
    {
        $this->injector->clear();
        parent::tearDown();
    }

    public function testModuleConfigurationPageListsConnectorsFromTheRegistryNotHardcoded(): void
    {
        $this->assertPageRenders('/admin/module/CommerceAgents');

        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('data-ca-pane="channels"', $html);
        self::assertStringContainsString('data-testid="ca-channel-mail"', $html);
        self::assertStringContainsString('data-testid="ca-channel-mattermost"', $html);
        self::assertStringContainsString('data-testid="ca-channel-slack"', $html);
        self::assertStringContainsString('>Mattermost<', $html);
        self::assertStringContainsString('>Slack<', $html);
    }

    public function testSavingValidMailSettingsPersistsThemEncrypted(): void
    {
        $this->client->request('POST', '/admin/module/commerceagents/channels/mail/save', [
            '_token' => $this->csrfToken(),
            'settings' => ['to' => 'ops@example.com'],
        ]);

        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertTrue($payload['success'], $payload['message'] ?? 'no message');

        $stored = $this->rawStoredValue('channel_connector_mail_settings');
        self::assertNotNull($stored);
        self::assertStringNotContainsString('ops@example.com', $stored, 'the raw DB value must never contain the plaintext setting');

        self::assertSame(['to' => 'ops@example.com'], $this->getService(ChannelConnectorConfigService::class)->getSettings('mail'));
    }

    public function testSavingAPrivateWebhookUrlIsRejectedWithAClearMessageAndNothingIsPersisted(): void
    {
        $configService = $this->getService(ChannelConnectorConfigService::class);
        $before = $configService->getSettings('mattermost');

        $this->client->request('POST', '/admin/module/commerceagents/channels/mattermost/save', [
            '_token' => $this->csrfToken(),
            'settings' => ['url' => 'https://169.254.169.254/latest/meta-data'],
        ]);

        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertFalse($payload['success']);
        self::assertNotEmpty($payload['message']);

        self::assertSame($before, $configService->getSettings('mattermost'), 'a rejected URL must never be persisted');
    }

    public function testSavingWithAMissingRequiredFieldReturnsAFormErrorNotA500(): void
    {
        $this->client->catchExceptions(false);

        $this->client->request('POST', '/admin/module/commerceagents/channels/slack/save', [
            '_token' => $this->csrfToken(),
            'settings' => ['url' => ''],
        ]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertFalse($payload['success']);
    }

    public function testTestConnectionEndpointReportsFailureWithoutPersistingAnything(): void
    {
        $configService = $this->getService(ChannelConnectorConfigService::class);
        $before = $configService->getSettings('mail');

        $this->client->request('POST', '/admin/module/commerceagents/channels/mail/test', [
            '_token' => $this->csrfToken(),
            'settings' => ['to' => 'not-an-email'],
        ]);

        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertFalse($payload['success']);
        self::assertSame($before, $configService->getSettings('mail'), 'the test-connection endpoint must never call persist()');
    }

    /**
     * MYO-332: a merchant with no store e-mail configured must get a clear,
     * explicit failure from the BO "Tester" button — not a mail silently
     * sent without a "From" header (rejected by most MTAs as spam).
     */
    public function testTestConnectionEndpointFailsExplicitlyWhenStoreEmailIsEmpty(): void
    {
        ConfigQuery::write('store_email', '');

        $this->client->request('POST', '/admin/module/commerceagents/channels/mail/test', [
            '_token' => $this->csrfToken(),
            'settings' => ['to' => 'merchant@example.com'],
        ]);

        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertFalse($payload['success']);
        self::assertStringContainsString('expéditeur', $payload['message'] ?? '');
    }

    /**
     * The ticket's hardest acceptance criterion: "Un agent configuré sur un
     * canal envoie réellement un message de bout en bout". This drives the
     * real save endpoint, the real TheliaChannelGateway, and the real
     * SendToChannelTool + MailChannelConnector + Symfony Mailer — every class
     * production uses, no fakes — through a real agent_channel row. Only the
     * transport is the dev-env null:// mailer (no external network in this
     * sandbox), so this proves the wiring is correct end to end rather than
     * proving actual mailbox delivery.
     */
    public function testAnAgentConfiguredOnTheCentralMailChannelSendsEndToEnd(): void
    {
        // MYO-332: the "From" address comes from the store configuration, not
        // from this test's DB fixtures — the test DB has no store_email set
        // (unlike the dev DB this test used to accidentally depend on), so it
        // must be posed explicitly here.
        ConfigQuery::write('store_email', 'contact@example.com');

        $this->client->request('POST', '/admin/module/commerceagents/channels/mail/save', [
            '_token' => $this->csrfToken(),
            'settings' => ['to' => 'merchant@example.com'],
        ]);
        self::assertTrue(json_decode((string) $this->client->getResponse()->getContent(), true)['success']);

        $definition = new AgentDefinition();
        $definition
            ->setCode('e2e-channel-agent-'.uniqid())
            ->setTitle('E2E channel agent')
            ->setRolePrompt('Test')
            ->setEnabled(1)
            ->save($this->getPropelConnection());

        (new AgentChannel())
            ->setAgentDefinitionId($definition->getId())
            ->setConnectorCode('mail')
            ->setMode('direct')
            ->setEnabled(1)
            ->save($this->getPropelConnection());

        $gateway = $this->getService(TheliaChannelGateway::class);
        $channels = $gateway->getEnabledChannelsForAgent($definition->getId());

        self::assertCount(1, $channels);
        self::assertSame('mail', $channels[0]['connectorCode']);
        self::assertSame(['to' => 'merchant@example.com'], $channels[0]['settings'], 'the agent must see the centrally-configured settings, not an empty per-agent row');

        $tool = $this->getService(SendToChannelTool::class);
        $context = new ToolContext(isAdmin: true, adminId: 1, conversationId: null, agentDefinitionId: $definition->getId(), capabilities: ['channels.send']);

        $result = $tool->execute(['message' => 'Rapport de test MYO-300'], $context);

        self::assertSame('sent', $result['results'][0]['status'] ?? null, (string) json_encode($result));
    }

    private function csrfToken(): string
    {
        $this->client->request('GET', '/admin/module/CommerceAgents');
        $crawler = $this->client->getCrawler();

        return (string) $crawler->filter('.ca-channel-form input[name="_token"]')->first()->attr('value');
    }

    private function rawStoredValue(string $variableName): ?string
    {
        $statement = Propel::getConnection('TheliaMain')->prepare(
            'SELECT i18n.value FROM module_config mc
             INNER JOIN module_config_i18n i18n ON i18n.id = mc.id
             WHERE mc.name = :name ORDER BY mc.id DESC LIMIT 1',
        );
        $statement->execute(['name' => $variableName]);

        $value = $statement->fetchColumn();

        return $value === false ? null : (string) $value;
    }
}

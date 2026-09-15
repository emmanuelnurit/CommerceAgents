<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Controller\Admin;

use CommerceAgents\Controller\Admin\MerchantChatController;
use CommerceAgents\Model\AgentDefinition;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;

/**
 * MYO-284 M4: MerchantChatController::chat() is a JSON POST from fetch(), the
 * only admin route of the module that validated no CSRF token at all --
 * every other admin controller in CommerceAgents does. A classic hidden
 * `_token` form field does not apply to a JSON fetch() call, so the token
 * travels as a header instead (see MerchantChatController::CSRF_HEADER).
 */
final class MerchantChatControllerTest extends WebIntegrationTestCase
{
    private AdminSessionInjector $injector;

    protected function setUp(): void
    {
        parent::setUp();

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

    public function testChatWithoutATokenIsRejected(): void
    {
        $this->client->request(
            'POST',
            '/admin/merchant-agent/chat',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['message' => 'Bonjour']),
        );

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testChatWithAnInvalidTokenIsRejected(): void
    {
        $this->client->request(
            'POST',
            '/admin/merchant-agent/chat',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => 'not-a-real-token'],
            json_encode(['message' => 'Bonjour']),
        );

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testChatWithAValidTokenPassesTheCsrfGate(): void
    {
        $token = $this->csrfToken();

        $this->client->request(
            'POST',
            '/admin/merchant-agent/chat',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token],
            json_encode(['message' => 'Bonjour']),
        );

        // Not configured with an LLM API key in the test environment, so the
        // request fails downstream of the CSRF check -- proving it is not
        // the CSRF gate rejecting it this time (that would be a 403).
        self::assertNotSame(403, $this->client->getResponse()->getStatusCode());
    }

    /**
     * MYO-325: the per-agent chat route shares the same CSRF gate as the
     * global merchant chat (dedicated to this controller, not the form
     * `_token` used by the rest of the module's admin routes).
     */
    public function testAgentChatWithoutATokenIsRejected(): void
    {
        $agent = $this->createAgentDefinition();

        $this->client->request(
            'POST',
            \sprintf('/admin/module/CommerceAgents/agents/%d/chat', $agent->getId()),
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['message' => 'Bonjour']),
        );

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testAgentChatForAnUnknownAgentReturns404(): void
    {
        $token = $this->csrfToken();

        $this->client->request(
            'POST',
            '/admin/module/CommerceAgents/agents/999999/chat',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token],
            json_encode(['message' => 'Bonjour']),
        );

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testAgentChatWithAValidTokenPassesTheCsrfGate(): void
    {
        $agent = $this->createAgentDefinition();
        $token = $this->csrfToken();

        $this->client->request(
            'POST',
            \sprintf('/admin/module/CommerceAgents/agents/%d/chat', $agent->getId()),
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token],
            json_encode(['message' => 'Bonjour']),
        );

        // Same reasoning as testChatWithAValidTokenPassesTheCsrfGate(): no LLM
        // API key in the test environment, so this fails downstream of the
        // CSRF check and the agent lookup -- neither would be a 403 or 404.
        self::assertNotSame(403, $this->client->getResponse()->getStatusCode());
        self::assertNotSame(404, $this->client->getResponse()->getStatusCode());
    }

    private function createAgentDefinition(): AgentDefinition
    {
        $definition = new AgentDefinition();
        $definition
            ->setCode('agent-'.uniqid())
            ->setTitle('Test agent')
            ->setRolePrompt('Watch stock levels.')
            ->setEnabled(1)
            ->save($this->getPropelConnection());

        return $definition;
    }

    private function csrfToken(): string
    {
        $this->client->request('GET', '/admin/merchant-agent');
        $crawler = $this->client->getCrawler();

        return (string) $crawler->filter('#merchant-chat')->attr('data-csrf-token');
    }
}

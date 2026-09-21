<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Controller\Admin;

use CommerceAgents\Model\AgentConversation;
use CommerceAgents\Model\AgentStagedChange;
use CommerceAgents\StagedChange\StagedChangeData;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;

/**
 * MYO-484 AC3: the two JSON endpoints backing the notification center
 * topbar -- access control (module VIEW right), the CSRF header contract
 * (same mechanism as MerchantChatController, MYO-284 M4), and the JSON
 * shape the sister ticket (front) will consume.
 */
final class NotificationCenterControllerTest extends WebIntegrationTestCase
{
    private AdminSessionInjector $injector;
    private FixtureFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->injector = new AdminSessionInjector();
        $this->getService(EventDispatcherInterface::class)->addSubscriber($this->injector);

        $this->factory = new FixtureFactory($this->getPropelConnection());
    }

    protected function tearDown(): void
    {
        $this->injector->clear();
        parent::tearDown();
    }

    private function loginAsAdmin(): void
    {
        $admin = $this->factory->admin();
        $admin->eraseCredentials();
        $this->injector->setAdmin($admin);
    }

    private function stagedChange(): AgentStagedChange
    {
        $conversation = (new AgentConversation())->setType('merchant');
        $conversation->save();

        $change = (new AgentStagedChange())
            ->setConversationId($conversation->getId())
            ->setTargetType('pse_stock')
            ->setTargetId(random_int(1, 999999))
            ->setPayloadBefore(json_encode([], \JSON_THROW_ON_ERROR))
            ->setPayloadAfter(json_encode([], \JSON_THROW_ON_ERROR))
            ->setStatus(StagedChangeData::STATUS_PENDING);
        $change->save();

        return $change;
    }

    public function testSummaryWithoutAnAdminSessionRedirectsToLogin(): void
    {
        $this->client->request('GET', '/admin/merchant-agent/notifications');

        self::assertSame(302, $this->client->getResponse()->getStatusCode());
    }

    public function testAckWithoutAnAdminSessionRedirectsToLogin(): void
    {
        $this->client->request('POST', '/admin/merchant-agent/notifications/ack', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['sourceType' => 'staged_change', 'sourceId' => 1]));

        self::assertSame(302, $this->client->getResponse()->getStatusCode());
    }

    public function testSummaryReturnsCountsAndACsrfToken(): void
    {
        $this->loginAsAdmin();
        $this->stagedChange();

        $this->client->request('GET', '/admin/merchant-agent/notifications');

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);

        self::assertSame(1, $data['counts']['stagedChange']);
        self::assertSame(1, $data['counts']['brief']);
        self::assertNotEmpty($data['csrfToken']);
    }

    public function testAckWithoutATokenIsRejected(): void
    {
        $this->loginAsAdmin();
        $change = $this->stagedChange();

        $this->client->request(
            'POST',
            '/admin/merchant-agent/notifications/ack',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['sourceType' => 'staged_change', 'sourceId' => $change->getId()]),
        );

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testAckWithAValidTokenMarksTheItemAsReadAndIsIdempotent(): void
    {
        $this->loginAsAdmin();
        $change = $this->stagedChange();

        $this->client->request('GET', '/admin/merchant-agent/notifications');
        $token = json_decode((string) $this->client->getResponse()->getContent(), true)['csrfToken'];

        $ackOnce = function () use ($token, $change) {
            $this->client->request(
                'POST',
                '/admin/merchant-agent/notifications/ack',
                [],
                [],
                ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token],
                json_encode(['sourceType' => 'staged_change', 'sourceId' => $change->getId()]),
            );

            return json_decode((string) $this->client->getResponse()->getContent(), true);
        };

        self::assertSame(['success' => true], $ackOnce());
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        // Idempotent replay (MYO-484 AC1/AC2): no error, no duplicate.
        self::assertSame(['success' => true], $ackOnce());

        $this->client->request('GET', '/admin/merchant-agent/notifications');
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame(0, $data['counts']['stagedChange']);
    }

    public function testAckWithAnInvalidSourceTypeIsRejected(): void
    {
        $this->loginAsAdmin();

        $this->client->request('GET', '/admin/merchant-agent/notifications');
        $token = json_decode((string) $this->client->getResponse()->getContent(), true)['csrfToken'];

        $this->client->request(
            'POST',
            '/admin/merchant-agent/notifications/ack',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token],
            json_encode(['sourceType' => 'not_a_real_type', 'sourceId' => 1]),
        );

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
    }
}

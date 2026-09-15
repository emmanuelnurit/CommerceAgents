<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Controller\Front;

use Thelia\Test\WebIntegrationTestCase;

/**
 * MYO-284 M3: the 4 front JSON routes of ChatController are public and
 * unauthenticated. They used to rely only on `cookie_samesite: lax` + CORS
 * (infra config outside the module's guarantee) against cross-site request
 * forgery. A simple cross-site <form> POST cannot set a custom header, so
 * requiring one on every request makes the protection travel with the
 * module. This test proves the header is enforced on all 4 routes.
 */
final class ChatControllerCsrfTest extends WebIntegrationTestCase
{
    public function testChatIsRejectedWithoutTheHeader(): void
    {
        $this->client->request('POST', '/agent/chat', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['message' => 'Bonjour']));

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testChatWithTheHeaderPassesTheGate(): void
    {
        $this->client->request(
            'POST',
            '/agent/chat',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'],
            json_encode(['message' => 'Bonjour']),
        );

        // No LLM API key configured in the test environment, so the request
        // fails downstream of the header check -- proving it is not the
        // header gate rejecting it this time (that would be a 403).
        self::assertNotSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testProactiveCheckIsRejectedWithoutTheHeader(): void
    {
        $this->client->request('POST', '/agent/chat/proactive-check', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['signal_type' => 'cart_idle']));

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testProactiveCheckWithTheHeaderPassesTheGate(): void
    {
        $this->client->request(
            'POST',
            '/agent/chat/proactive-check',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'],
            json_encode(['signal_type' => 'cart_idle']),
        );

        self::assertNotSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testProactiveApplyCouponIsRejectedWithoutTheHeader(): void
    {
        $this->client->request('POST', '/agent/chat/proactive-apply-coupon', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['code' => 'WELCOME10']));

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testProactiveApplyCouponWithTheHeaderPassesTheGate(): void
    {
        $this->client->request(
            'POST',
            '/agent/chat/proactive-apply-coupon',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'],
            json_encode(['code' => 'NO-SUCH-CODE']),
        );

        self::assertNotSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testProactiveDismissIsRejectedWithoutTheHeader(): void
    {
        $this->client->request('POST', '/agent/chat/proactive-dismiss');

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testProactiveDismissWithTheHeaderPassesTheGate(): void
    {
        $this->client->request('POST', '/agent/chat/proactive-dismiss', [], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);

        self::assertSame(204, $this->client->getResponse()->getStatusCode());
    }
}

<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Service;

use CommerceAgents\Model\AgentConversationQuery;
use CommerceAgents\Service\ConversationService;
use Thelia\Test\IntegrationTestCase;

/**
 * MYO-276: a session id can outlive the identity that created it (login,
 * logout, a shared workstation, session fixation). getOrCreate() must never
 * hand a new visitor/admin the conversation history -- and its embedded
 * PII (orders, profile) -- of whoever previously held that session id.
 */
class ConversationServiceTest extends IntegrationTestCase
{
    private ConversationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new ConversationService();
    }

    public function testSameSessionAndCustomerReusesTheSameConversation(): void
    {
        $sessionRef = 'session-'.uniqid('', true);

        $first = $this->service->getOrCreate('shopping', $sessionRef, 42, 'fr_FR');
        $second = $this->service->getOrCreate('shopping', $sessionRef, 42, 'fr_FR');

        self::assertSame($first->getId(), $second->getId());
    }

    public function testDifferentCustomerOnSameSessionGetsAFreshConversation(): void
    {
        $sessionRef = 'session-'.uniqid('', true);

        $customerA = $this->service->getOrCreate('shopping', $sessionRef, 101, 'fr_FR');
        $this->service->appendMessage($customerA->getId(), 'user', 'Quelle est ma dernière commande ?');

        // Same session id reused by a different customer (shared workstation,
        // session fixation, or a stale cookie): must not resurrect customer
        // A's conversation/history for customer B.
        $customerB = $this->service->getOrCreate('shopping', $sessionRef, 202, 'fr_FR');

        self::assertNotSame($customerA->getId(), $customerB->getId());
        self::assertSame([], $this->service->getHistory($customerB->getId()));
    }

    public function testAnonymousThenLoggedInVisitorOnSameSessionGetsAFreshConversation(): void
    {
        $sessionRef = 'session-'.uniqid('', true);

        $anonymous = $this->service->getOrCreate('shopping', $sessionRef, null, 'fr_FR');
        $loggedIn = $this->service->getOrCreate('shopping', $sessionRef, 303, 'fr_FR');

        self::assertNotSame($anonymous->getId(), $loggedIn->getId());
    }

    public function testDifferentAdminOnSameSessionGetsAFreshConversation(): void
    {
        $sessionRef = 'session-'.uniqid('', true);

        $adminA = $this->service->getOrCreate('merchant', $sessionRef, null, 'fr_FR', 11);
        $adminB = $this->service->getOrCreate('merchant', $sessionRef, null, 'fr_FR', 22);

        self::assertNotSame($adminA->getId(), $adminB->getId());
    }

    public function testCountUserMessagesTodayIgnoresOtherRolesAndOtherConversations(): void
    {
        $sessionRef = 'session-'.uniqid('', true);
        $conversation = $this->service->getOrCreate('shopping', $sessionRef, 404, 'fr_FR');
        $otherConversation = $this->service->getOrCreate('shopping', 'session-'.uniqid('', true), 405, 'fr_FR');

        $this->service->appendMessage($conversation->getId(), 'user', 'Bonjour');
        $this->service->appendMessage($conversation->getId(), 'assistant', 'Bonjour, comment puis-je vous aider ?');
        $this->service->appendMessage($conversation->getId(), 'user', 'Avez-vous ce produit en stock ?');
        $this->service->appendMessage($otherConversation->getId(), 'user', 'Message d\'une autre conversation');

        self::assertSame(2, $this->service->countUserMessagesToday($conversation->getId()));
    }

    protected function tearDown(): void
    {
        AgentConversationQuery::create()->filterBySessionRef('session-%', \Propel\Runtime\ActiveQuery\Criteria::LIKE)->delete();

        parent::tearDown();
    }
}

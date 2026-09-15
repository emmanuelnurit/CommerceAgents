<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Service\SpecialtyPane;

use CommerceAgents\Agent\Tool\AgentOutboundMessageLoggerInterface;
use CommerceAgents\Model\AgentDefinition;
use CommerceAgents\Model\AgentOutboundMessage;
use CommerceAgents\Model\AgentRun;
use CommerceAgents\Service\Run\AgentRunQueue;
use CommerceAgents\Service\SpecialtyPane\CartAbandonedResultsPane;
use Thelia\Test\IntegrationTestCase;

/**
 * MYO-340: the real "Messages envoyés" pane for cart_abandoned_relaunch --
 * no longer _specialty_stub.html.twig, built from agent_outbound_message
 * rows like GenericResultsPane, but also exposing bodyExcerpt and error.
 */
final class CartAbandonedResultsPaneTest extends IntegrationTestCase
{
    private function definition(): AgentDefinition
    {
        $definition = (new AgentDefinition())
            ->setCode('cart-abandoned-test-'.uniqid('', true))
            ->setTitle('Cart abandoned test agent');
        $definition->save();

        return $definition;
    }

    private function agentRun(int $agentDefinitionId): AgentRun
    {
        $run = (new AgentRun())
            ->setAgentDefinitionId($agentDefinitionId)
            ->setStatus(AgentRunQueue::STATUS_DONE);
        $run->save();

        return $run;
    }

    public function testNoLongerReturnsTheStubTemplate(): void
    {
        $pane = new CartAbandonedResultsPane();

        $this->assertStringNotContainsString('_specialty_stub', $pane->getTemplate());
    }

    public function testViewDataIncludesRecipientBodyExcerptAndStatus(): void
    {
        $definition = $this->definition();
        $run = $this->agentRun($definition->getId());
        (new AgentOutboundMessage())
            ->setAgentRunId($run->getId())
            ->setAgentDefinitionId($definition->getId())
            ->setChannel('mail')
            ->setRecipient('jean@example.com')
            ->setStatus(AgentOutboundMessageLoggerInterface::STATUS_STAGED)
            ->setBodyExcerpt('Bonjour Jean, votre panier vous attend…')
            ->setSentAt(new \DateTime())
            ->save();

        $data = (new CartAbandonedResultsPane())->getViewData($definition);

        $this->assertCount(1, $data['messages']);
        $message = $data['messages'][0];
        $this->assertSame('jean@example.com', $message['recipient']);
        $this->assertSame('staged', $message['status']);
        $this->assertSame('Bonjour Jean, votre panier vous attend…', $message['bodyExcerpt']);
        $this->assertNull($message['error']);
    }

    public function testViewDataIncludesTheErrorReasonOnFailure(): void
    {
        $definition = $this->definition();
        $run = $this->agentRun($definition->getId());
        (new AgentOutboundMessage())
            ->setAgentRunId($run->getId())
            ->setAgentDefinitionId($definition->getId())
            ->setChannel('mail')
            ->setRecipient('jean@example.com')
            ->setStatus(AgentOutboundMessageLoggerInterface::STATUS_FAILED)
            ->setError('Le canal e-mail nécessite un expéditeur')
            ->setSentAt(new \DateTime())
            ->save();

        $data = (new CartAbandonedResultsPane())->getViewData($definition);

        $this->assertSame('failed', $data['messages'][0]['status']);
        $this->assertSame('Le canal e-mail nécessite un expéditeur', $data['messages'][0]['error']);
    }

    public function testEmptyStateWhenNoMessageWasEverSent(): void
    {
        $data = (new CartAbandonedResultsPane())->getViewData($this->definition());

        $this->assertSame([], $data['messages']);
    }

    public function testSupportsOnlyMatchesTheExactPresetCode(): void
    {
        $pane = new CartAbandonedResultsPane();

        $this->assertTrue($pane->supports('cart_abandoned_relaunch', []));
        $this->assertFalse($pane->supports('welcome_new_customer', ['catalog.read', 'customer.read']));
        $this->assertFalse($pane->supports(null, ['catalog.read', 'customer.read']));
    }
}

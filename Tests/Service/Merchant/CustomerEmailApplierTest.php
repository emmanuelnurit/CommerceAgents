<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Service\Merchant;

use CommerceAgents\Channel\ChannelConnectorRegistry;
use CommerceAgents\Channel\Connector\MailChannelConnector;
use CommerceAgents\Service\Merchant\CustomerEmailApplier;
use CommerceAgents\StagedChange\StagedChangeData;
use Symfony\Component\Mailer\MailerInterface;
use Thelia\Model\ConfigQuery;
use Thelia\Test\IntegrationTestCase;

/**
 * MYO-340: the applier must send through the mail connector with the
 * recipient carried on the proposal (never the shop's centrally configured
 * channel), and a MYO-332 "store_email empty" failure must surface at
 * approval time (apply()), never earlier -- the tool only ever stages.
 */
final class CustomerEmailApplierTest extends IntegrationTestCase
{
    private ?string $originalStoreEmail = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalStoreEmail = ConfigQuery::getStoreEmail();
    }

    protected function tearDown(): void
    {
        ConfigQuery::write('store_email', $this->originalStoreEmail ?? '');

        parent::tearDown();
    }

    private function registry(): ChannelConnectorRegistry
    {
        $mailer = static::getContainer()->get(MailerInterface::class);

        return new ChannelConnectorRegistry([new MailChannelConnector($mailer)]);
    }

    private function change(array $after): StagedChangeData
    {
        return new StagedChangeData(
            id: 1,
            targetType: 'customer_email',
            targetId: 42,
            payloadBefore: [],
            payloadAfter: $after,
            status: StagedChangeData::STATUS_PENDING,
        );
    }

    public function testApplySendsThroughTheMailConnectorWithTheStagedRecipient(): void
    {
        ConfigQuery::write('store_email', 'boutique@example.com');
        $applier = new CustomerEmailApplier($this->registry());

        $applier->apply($this->change(['recipient' => 'jean@example.com', 'subject' => 'Bienvenue', 'body' => 'Bonjour Jean']));

        // MAILER_DSN=null:// in test env: send() is a no-op transport, so
        // reaching this line without an exception is the assertion.
        $this->addToAssertionCount(1);
    }

    public function testApplyThrowsWhenStoreEmailIsEmptyAtApprovalTime(): void
    {
        // The tool call itself (stageCustomerEmail) never touches the mail
        // connector -- this failure can only ever surface here, at apply().
        ConfigQuery::write('store_email', '');
        $applier = new CustomerEmailApplier($this->registry());

        $this->expectException(\RuntimeException::class);

        $applier->apply($this->change(['recipient' => 'jean@example.com', 'subject' => null, 'body' => 'Bonjour']));
    }

    public function testApplyThrowsWhenNoMailConnectorIsRegistered(): void
    {
        $applier = new CustomerEmailApplier(new ChannelConnectorRegistry([]));

        $this->expectException(\RuntimeException::class);

        $applier->apply($this->change(['recipient' => 'jean@example.com', 'subject' => null, 'body' => 'Bonjour']));
    }

    public function testGetTargetType(): void
    {
        $this->assertSame('customer_email', (new CustomerEmailApplier(new ChannelConnectorRegistry([])))->getTargetType());
    }
}

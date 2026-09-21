<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Service;

use CommerceAgents\Agent\Proactive\ProactiveSignal;
use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Service\CartCouponScenarioResolver;
use CommerceAgents\Service\NewsletterOptinScenarioResolver;
use CommerceAgents\Tests\Tool\Shopping\FakeCouponGateway;
use CommerceAgents\Tests\Tool\Shopping\FakeCustomerGateway;
use CommerceAgents\Tool\Shopping\Gateway\NewsletterGatewayInterface;
use PHPUnit\Framework\TestCase;
use Thelia\Core\Translation\Translator;

final class FakeNewsletterGateway implements NewsletterGatewayInterface
{
    public function __construct(private readonly bool $subscribed = false)
    {
    }

    public function isSubscribed(string $email): bool
    {
        return $this->subscribed;
    }
}

class NewsletterOptinScenarioResolverTest extends TestCase
{
    private const WELCOME_COUPON = ['code' => 'BIENVENUE10', 'title' => 'Remise de bienvenue', 'shortDescription' => 'Sur votre 1ère commande', 'discountLabel' => '-10%'];
    private const OTHER_COUPON = ['code' => 'SUMMER10', 'title' => 'Soldes été', 'shortDescription' => '', 'discountLabel' => '-10%'];

    private function translator(): Translator
    {
        $translator = $this->createMock(Translator::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $id, array $parameters = []): string => strtr($id, $parameters),
        );

        return $translator;
    }

    private function resolver(
        array $applicableCoupons = [],
        ?array $customerProfile = null,
        bool $alreadySubscribed = false,
    ): NewsletterOptinScenarioResolver {
        return new NewsletterOptinScenarioResolver(
            new FakeCouponGateway(applicable: $applicableCoupons),
            new FakeCustomerGateway($customerProfile),
            new FakeNewsletterGateway($alreadySubscribed),
            $this->translator(),
        );
    }

    public function testIgnoresOtherSignals(): void
    {
        $resolver = $this->resolver([self::WELCOME_COUPON]);

        $this->assertNull($resolver->resolve(new ProactiveSignal('first_visit'), new ToolContext()));
    }

    public function testNoMessageWithoutAWelcomeFlavouredCoupon(): void
    {
        $resolver = $this->resolver([self::OTHER_COUPON]);

        $this->assertNull($resolver->resolve(new ProactiveSignal(CartCouponScenarioResolver::SIGNAL_ADD_TO_CART), new ToolContext()));
    }

    public function testOptinOfferForAnonymousVisitor(): void
    {
        $resolver = $this->resolver([self::WELCOME_COUPON]);

        $message = $resolver->resolve(new ProactiveSignal(CartCouponScenarioResolver::SIGNAL_ADD_TO_CART), new ToolContext(customerId: null));

        $this->assertNotNull($message);
        $this->assertTrue($message->newsletterOptin);
        $this->assertNull($message->couponCode, 'the code must never leave the resolver before opt-in');
        $this->assertStringContainsString('-10%', $message->message);
    }

    public function testNoOfferForLoggedInCustomerAlreadySubscribed(): void
    {
        $resolver = $this->resolver(
            applicableCoupons: [self::WELCOME_COUPON],
            customerProfile: ['email' => 'jean@example.com'],
            alreadySubscribed: true,
        );

        $this->assertNull($resolver->resolve(new ProactiveSignal(CartCouponScenarioResolver::SIGNAL_ADD_TO_CART), new ToolContext(customerId: 42)));
    }

    public function testOfferForLoggedInCustomerNotSubscribedYet(): void
    {
        $resolver = $this->resolver(
            applicableCoupons: [self::WELCOME_COUPON],
            customerProfile: ['email' => 'jean@example.com'],
            alreadySubscribed: false,
        );

        $message = $resolver->resolve(new ProactiveSignal(CartCouponScenarioResolver::SIGNAL_ADD_TO_CART), new ToolContext(customerId: 42));

        $this->assertNotNull($message);
        $this->assertTrue($message->newsletterOptin);
    }
}

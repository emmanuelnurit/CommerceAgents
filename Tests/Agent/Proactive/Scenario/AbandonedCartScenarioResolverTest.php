<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Agent\Proactive\Scenario;

use CommerceAgents\Agent\Proactive\ProactiveSignal;
use CommerceAgents\Agent\Proactive\Scenario\AbandonedCartScenarioResolver;
use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Service\Locale\AssistantLocaleResolver;
use CommerceAgents\Tests\Service\Locale\FakeSiteDefaultLocaleProvider;
use CommerceAgents\Tool\Shopping\Gateway\CartGatewayInterface;
use CommerceAgents\Tool\Shopping\Gateway\PolicyGatewayInterface;
use PHPUnit\Framework\TestCase;

final class FakeAbandonedCartGateway implements CartGatewayInterface
{
    public function __construct(private readonly array $cart)
    {
    }

    public function addToCart(int $productId, ?int $productSaleElementsId, int $quantity, ToolContext $ctx): array
    {
        return $this->cart;
    }

    public function getCart(ToolContext $ctx): array
    {
        return $this->cart;
    }
}

final class FakeAbandonedCartPolicyGateway implements PolicyGatewayInterface
{
    public function __construct(private readonly array $policies = [])
    {
    }

    public function getPolicies(string $locale): array
    {
        return $this->policies;
    }
}

final class AbandonedCartScenarioResolverTest extends TestCase
{
    private function resolver(CartGatewayInterface $cart, PolicyGatewayInterface $policy, string $siteDefaultLocale = 'fr_FR'): AbandonedCartScenarioResolver
    {
        return new AbandonedCartScenarioResolver($cart, $policy, new AssistantLocaleResolver(new FakeSiteDefaultLocaleProvider($siteDefaultLocale)));
    }

    public function testIgnoresOtherSignalTypes(): void
    {
        $resolver = $this->resolver(
            new FakeAbandonedCartGateway(['itemCount' => 2]),
            new FakeAbandonedCartPolicyGateway(),
        );

        $result = $resolver->resolve(new ProactiveSignal('hesitation'), new ToolContext());

        $this->assertNull($result);
    }

    public function testEmptyCartMeansNoMessage(): void
    {
        $resolver = $this->resolver(
            new FakeAbandonedCartGateway(['itemCount' => 0]),
            new FakeAbandonedCartPolicyGateway(),
        );

        $result = $resolver->resolve(new ProactiveSignal('cart_abandoned_session'), new ToolContext());

        $this->assertNull($result, 'a cart that is empty by the time this resolves has nothing to be reassured about');
    }

    public function testNonEmptyCartMentionsTheRealItemCount(): void
    {
        $resolver = $this->resolver(
            new FakeAbandonedCartGateway(['itemCount' => 3]),
            new FakeAbandonedCartPolicyGateway(),
        );

        $message = $resolver->resolve(new ProactiveSignal('cart_abandoned_session'), new ToolContext(locale: 'fr_FR'));

        $this->assertStringContainsString('3', $message?->message);
    }

    public function testShippingReturnsLineOnlyWhenPoliciesAreConfigured(): void
    {
        $withPolicies = $this->resolver(
            new FakeAbandonedCartGateway(['itemCount' => 1]),
            new FakeAbandonedCartPolicyGateway([['title' => 'Retours', 'text' => '...']]),
        );
        $withoutPolicies = $this->resolver(
            new FakeAbandonedCartGateway(['itemCount' => 1]),
            new FakeAbandonedCartPolicyGateway(),
        );

        $withMessage = $withPolicies->resolve(new ProactiveSignal('cart_abandoned_session'), new ToolContext(locale: 'fr_FR'));
        $withoutMessage = $withoutPolicies->resolve(new ProactiveSignal('cart_abandoned_session'), new ToolContext(locale: 'fr_FR'));

        $this->assertStringContainsString('livraison', $withMessage?->message);
        $this->assertStringNotContainsString('livraison', $withoutMessage?->message);
    }

    public function testMessageIsLocalisedByLocalePrefix(): void
    {
        $resolver = $this->resolver(
            new FakeAbandonedCartGateway(['itemCount' => 1]),
            new FakeAbandonedCartPolicyGateway(),
        );

        $message = $resolver->resolve(new ProactiveSignal('cart_abandoned_session'), new ToolContext(locale: 'es_ES'));

        $this->assertStringContainsString('carrito', $message?->message);
    }

    public function testUnsupportedVisitorLocaleFallsBackToTheSiteDefaultLanguage(): void
    {
        $resolver = $this->resolver(
            new FakeAbandonedCartGateway(['itemCount' => 1]),
            new FakeAbandonedCartPolicyGateway(),
            'es_ES',
        );

        $message = $resolver->resolve(new ProactiveSignal('cart_abandoned_session'), new ToolContext(locale: 'de_DE'));

        $this->assertStringContainsString('carrito', $message?->message);
    }

    public function testNeverAttachesACouponCode(): void
    {
        // Scenario 4's promo-code half needs MYO-247's eligibility tool: this
        // resolver only ever ships the reassurance text.
        $resolver = $this->resolver(
            new FakeAbandonedCartGateway(['itemCount' => 1]),
            new FakeAbandonedCartPolicyGateway([['title' => 'Retours', 'text' => '...']]),
        );

        $message = $resolver->resolve(new ProactiveSignal('cart_abandoned_session'), new ToolContext(locale: 'fr_FR'));

        $this->assertStringNotContainsString('code', strtolower($message?->message ?? ''));
    }
}

<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Agent\Proactive\Scenario;

use CommerceAgents\Agent\Proactive\ProactiveSignal;
use CommerceAgents\Agent\Proactive\Scenario\HesitationScenarioResolver;
use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Tool\Shopping\Gateway\CatalogGatewayInterface;
use CommerceAgents\Tool\Shopping\Gateway\PolicyGatewayInterface;
use PHPUnit\Framework\TestCase;

final class FakeHesitationCatalogGateway implements CatalogGatewayInterface
{
    public function __construct(private readonly ?array $product = null)
    {
    }

    public function searchProducts(?string $query, ?int $categoryId, ?int $featureAvId, ?float $minPrice, ?float $maxPrice, bool $promoOnly, int $limit, ToolContext $ctx): array
    {
        return ['products' => [], 'matchedCategory' => null];
    }

    public function searchVariants(?string $query, int $attributeAvId, ?int $categoryId, ?float $minPrice, ?float $maxPrice, bool $promoOnly, int $limit, ToolContext $ctx): array
    {
        return [];
    }

    public function getProductDetails(int $productId, ToolContext $ctx): ?array
    {
        return $this->product;
    }

    public function getDefaultCategoryId(int $productId): ?int
    {
        return null;
    }
}

final class FakeHesitationPolicyGateway implements PolicyGatewayInterface
{
    public function __construct(private readonly array $policies = [])
    {
    }

    public function getPolicies(string $locale): array
    {
        return $this->policies;
    }
}

final class HesitationScenarioResolverTest extends TestCase
{
    public function testIgnoresOtherSignalTypes(): void
    {
        $resolver = new HesitationScenarioResolver(new FakeHesitationCatalogGateway(), new FakeHesitationPolicyGateway());

        $result = $resolver->resolve(new ProactiveSignal('cart_abandoned_session'), new ToolContext());

        $this->assertNull($result);
    }

    public function testRealStockIsSummedAcrossVariants(): void
    {
        $product = ['id' => 7, 'pses' => [['stock' => 3.0], ['stock' => 2.0]]];
        $resolver = new HesitationScenarioResolver(new FakeHesitationCatalogGateway($product), new FakeHesitationPolicyGateway());

        $message = $resolver->resolve(new ProactiveSignal('hesitation', ['product_id' => 7]), new ToolContext(locale: 'fr_FR'));

        $this->assertStringContainsString('5', $message?->message);
    }

    public function testNoStockLineWhenTheProductIsNotFound(): void
    {
        $resolver = new HesitationScenarioResolver(new FakeHesitationCatalogGateway(null), new FakeHesitationPolicyGateway());

        $message = $resolver->resolve(new ProactiveSignal('hesitation', ['product_id' => 999]), new ToolContext(locale: 'fr_FR'));

        $this->assertNotNull($message);
        $this->assertStringNotContainsString('Il reste', $message->message);
    }

    public function testNoProductIdMeansNoStockClaim(): void
    {
        $resolver = new HesitationScenarioResolver(new FakeHesitationCatalogGateway(['id' => 7, 'pses' => [['stock' => 9]]]), new FakeHesitationPolicyGateway());

        $message = $resolver->resolve(new ProactiveSignal('hesitation'), new ToolContext(locale: 'fr_FR'));

        $this->assertStringNotContainsString('9', $message?->message);
    }

    public function testShippingReturnsLineOnlyWhenPoliciesAreConfigured(): void
    {
        $withPolicies = new HesitationScenarioResolver(
            new FakeHesitationCatalogGateway(),
            new FakeHesitationPolicyGateway([['title' => 'Livraison', 'text' => '...']]),
        );
        $withoutPolicies = new HesitationScenarioResolver(new FakeHesitationCatalogGateway(), new FakeHesitationPolicyGateway());

        $withMessage = $withPolicies->resolve(new ProactiveSignal('hesitation'), new ToolContext(locale: 'fr_FR'));
        $withoutMessage = $withoutPolicies->resolve(new ProactiveSignal('hesitation'), new ToolContext(locale: 'fr_FR'));

        $this->assertStringContainsString('livraison', $withMessage?->message);
        $this->assertStringNotContainsString('livraison', $withoutMessage?->message);
    }

    public function testMessageIsLocalisedByLocalePrefix(): void
    {
        $resolver = new HesitationScenarioResolver(
            new FakeHesitationCatalogGateway(['id' => 7, 'pses' => [['stock' => 4]]]),
            new FakeHesitationPolicyGateway(),
        );

        $en = $resolver->resolve(new ProactiveSignal('hesitation', ['product_id' => 7]), new ToolContext(locale: 'en_US'));
        $unknown = $resolver->resolve(new ProactiveSignal('hesitation', ['product_id' => 7]), new ToolContext(locale: 'de_DE'));

        $this->assertStringContainsString('left in stock', $en?->message);
        // Locales outside the module's 4 shipped catalogues fall back to French.
        $this->assertStringContainsString('en stock', $unknown?->message);
    }

    public function testNonIntegerProductIdIsIgnoredNotCrashed(): void
    {
        $resolver = new HesitationScenarioResolver(new FakeHesitationCatalogGateway(), new FakeHesitationPolicyGateway());

        $message = $resolver->resolve(new ProactiveSignal('hesitation', ['product_id' => ['not' => 'a scalar']]), new ToolContext());

        $this->assertNotNull($message);
    }
}

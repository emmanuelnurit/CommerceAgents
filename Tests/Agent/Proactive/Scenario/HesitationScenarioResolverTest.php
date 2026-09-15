<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Agent\Proactive\Scenario;

use CommerceAgents\Agent\Proactive\ProactiveSignal;
use CommerceAgents\Agent\Proactive\Scenario\HesitationScenarioResolver;
use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Service\Locale\AssistantLocaleResolver;
use CommerceAgents\Tests\Service\Locale\FakeSiteDefaultLocaleProvider;
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
    private function resolver(CatalogGatewayInterface $catalog, PolicyGatewayInterface $policy, string $siteDefaultLocale = 'fr_FR'): HesitationScenarioResolver
    {
        return new HesitationScenarioResolver($catalog, $policy, new AssistantLocaleResolver(new FakeSiteDefaultLocaleProvider($siteDefaultLocale)));
    }

    public function testIgnoresOtherSignalTypes(): void
    {
        $resolver = $this->resolver(new FakeHesitationCatalogGateway(), new FakeHesitationPolicyGateway());

        $result = $resolver->resolve(new ProactiveSignal('cart_abandoned_session'), new ToolContext());

        $this->assertNull($result);
    }

    public function testRealStockIsSummedAcrossVariants(): void
    {
        $product = ['id' => 7, 'pses' => [['stock' => 3.0], ['stock' => 2.0]]];
        $resolver = $this->resolver(new FakeHesitationCatalogGateway($product), new FakeHesitationPolicyGateway());

        $message = $resolver->resolve(new ProactiveSignal('hesitation', ['product_id' => 7]), new ToolContext(locale: 'fr_FR'));

        $this->assertStringContainsString('5', $message?->message);
    }

    public function testNoStockLineWhenTheProductIsNotFound(): void
    {
        $resolver = $this->resolver(new FakeHesitationCatalogGateway(null), new FakeHesitationPolicyGateway());

        $message = $resolver->resolve(new ProactiveSignal('hesitation', ['product_id' => 999]), new ToolContext(locale: 'fr_FR'));

        $this->assertNotNull($message);
        $this->assertStringNotContainsString('Il reste', $message->message);
    }

    public function testNoProductIdMeansNoStockClaim(): void
    {
        $resolver = $this->resolver(new FakeHesitationCatalogGateway(['id' => 7, 'pses' => [['stock' => 9]]]), new FakeHesitationPolicyGateway());

        $message = $resolver->resolve(new ProactiveSignal('hesitation'), new ToolContext(locale: 'fr_FR'));

        $this->assertStringNotContainsString('9', $message?->message);
    }

    public function testShippingReturnsLineOnlyWhenPoliciesAreConfigured(): void
    {
        $withPolicies = $this->resolver(
            new FakeHesitationCatalogGateway(),
            new FakeHesitationPolicyGateway([['title' => 'Livraison', 'text' => '...']]),
        );
        $withoutPolicies = $this->resolver(new FakeHesitationCatalogGateway(), new FakeHesitationPolicyGateway());

        $withMessage = $withPolicies->resolve(new ProactiveSignal('hesitation'), new ToolContext(locale: 'fr_FR'));
        $withoutMessage = $withoutPolicies->resolve(new ProactiveSignal('hesitation'), new ToolContext(locale: 'fr_FR'));

        $this->assertStringContainsString('livraison', $withMessage?->message);
        $this->assertStringNotContainsString('livraison', $withoutMessage?->message);
    }

    public function testMessageIsLocalisedByLocalePrefix(): void
    {
        $resolver = $this->resolver(
            new FakeHesitationCatalogGateway(['id' => 7, 'pses' => [['stock' => 4]]]),
            new FakeHesitationPolicyGateway(),
        );

        $en = $resolver->resolve(new ProactiveSignal('hesitation', ['product_id' => 7]), new ToolContext(locale: 'en_US'));

        $this->assertStringContainsString('left in stock', $en?->message);
    }

    public function testUnsupportedVisitorLocaleFallsBackToTheSiteDefaultLanguage(): void
    {
        // The store's default language is French, so a visitor whose browsing
        // locale has no shipped templates (German) still gets French, not a
        // language picked at random.
        $resolver = $this->resolver(
            new FakeHesitationCatalogGateway(['id' => 7, 'pses' => [['stock' => 4]]]),
            new FakeHesitationPolicyGateway(),
            'fr_FR',
        );

        $message = $resolver->resolve(new ProactiveSignal('hesitation', ['product_id' => 7]), new ToolContext(locale: 'de_DE'));

        $this->assertStringContainsString('en stock', $message?->message);
    }

    public function testUnsupportedVisitorAndSiteDefaultLocaleFallsBackToEnglish(): void
    {
        // Neither the visitor's browsing locale nor the store's own default
        // language (German, here) has templates: English is used rather than
        // silently assuming French.
        $resolver = $this->resolver(
            new FakeHesitationCatalogGateway(['id' => 7, 'pses' => [['stock' => 4]]]),
            new FakeHesitationPolicyGateway(),
            'de_DE',
        );

        $message = $resolver->resolve(new ProactiveSignal('hesitation', ['product_id' => 7]), new ToolContext(locale: 'de_DE'));

        $this->assertStringContainsString('left in stock', $message?->message);
    }

    public function testNonIntegerProductIdIsIgnoredNotCrashed(): void
    {
        $resolver = $this->resolver(new FakeHesitationCatalogGateway(), new FakeHesitationPolicyGateway());

        $message = $resolver->resolve(new ProactiveSignal('hesitation', ['product_id' => ['not' => 'a scalar']]), new ToolContext());

        $this->assertNotNull($message);
    }
}

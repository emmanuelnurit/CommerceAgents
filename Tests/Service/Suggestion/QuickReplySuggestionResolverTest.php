<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Service\Suggestion;

use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Service\Shopping\AccountSummaryProvider;
use CommerceAgents\Service\Suggestion\QuickReplySuggestionResolver;
use CommerceAgents\Tool\Shopping\Gateway\CartGatewayInterface;
use CommerceAgents\Tool\Shopping\Gateway\CatalogGatewayInterface;
use CommerceAgents\Tool\Shopping\Gateway\CategoryGatewayInterface;
use CommerceAgents\Tool\Shopping\Gateway\CustomerGatewayInterface;
use CommerceAgents\Tool\Shopping\Gateway\OrderGatewayInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Thelia\Core\Translation\Translator;

class FakeSuggestionCategoryGateway implements CategoryGatewayInterface
{
    public function __construct(private readonly array $siblings = [])
    {
    }

    public function getCategories(string $locale, int $limit): array
    {
        return [];
    }

    public function findByName(string $name, string $locale): ?array
    {
        return null;
    }

    public function findById(int $id, string $locale): ?array
    {
        return null;
    }

    public function getSiblings(int $categoryId, string $locale, int $limit): array
    {
        return \array_slice($this->siblings, 0, $limit);
    }
}

class FakeSuggestionCatalogGateway implements CatalogGatewayInterface
{
    public function __construct(private readonly ?int $defaultCategoryId = null)
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
        return null;
    }

    public function getDefaultCategoryId(int $productId): ?int
    {
        return $this->defaultCategoryId;
    }
}

class FakeSuggestionCartGateway implements CartGatewayInterface
{
    public function __construct(private readonly int $itemCount = 0)
    {
    }

    public function addToCart(int $productId, ?int $productSaleElementsId, int $quantity, ToolContext $ctx): array
    {
        return ['items' => [], 'totalTaxedAmount' => 0, 'currency' => 'EUR', 'itemCount' => $this->itemCount];
    }

    public function getCart(ToolContext $ctx): array
    {
        return ['items' => [], 'totalTaxedAmount' => 0, 'currency' => 'EUR', 'itemCount' => $this->itemCount];
    }
}

class FakeSuggestionOrderGateway implements OrderGatewayInterface
{
    public function getOrders(int $customerId, int $limit, ToolContext $ctx): array
    {
        return [];
    }
}

class FakeSuggestionCustomerGateway implements CustomerGatewayInterface
{
    public function getProfile(int $customerId, string $locale): ?array
    {
        return ['firstName' => 'Alex'];
    }
}

class QuickReplySuggestionResolverTest extends TestCase
{
    private function urlGenerator(): UrlGeneratorInterface
    {
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturnCallback(static fn (string $name): string => 'https://shop.example/'.$name);

        return $urlGenerator;
    }

    private function translator(): Translator
    {
        $translator = $this->createMock(Translator::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $id, array $parameters = []): string => strtr($id, $parameters),
        );

        return $translator;
    }

    private function resolver(
        array $siblings = [],
        ?int $defaultCategoryId = null,
        int $cartItemCount = 0,
    ): QuickReplySuggestionResolver {
        return new QuickReplySuggestionResolver(
            new FakeSuggestionCategoryGateway($siblings),
            new FakeSuggestionCatalogGateway($defaultCategoryId),
            new FakeSuggestionCartGateway($cartItemCount),
            new AccountSummaryProvider(new FakeSuggestionOrderGateway(), new FakeSuggestionCustomerGateway(), $this->urlGenerator()),
            $this->urlGenerator(),
            $this->translator(),
        );
    }

    private function ctx(?int $customerId = null): ToolContext
    {
        return new ToolContext(customerId: $customerId, locale: 'fr_FR');
    }

    public function testCatalogIntentSuggestsTheSiblingCategoryFromAMatchedCategory(): void
    {
        $resolver = $this->resolver(siblings: [
            ['id' => 9, 'title' => 'Tabourets', 'url' => 'https://shop.example/tabourets.html', 'productCount' => 4],
        ]);
        $toolCalls = [
            ['name' => 'search_products', 'result' => [
                'products' => [['id' => 1, 'title' => 'Chaise Stacy']],
                'matched_category' => ['id' => 3, 'title' => 'Chaises', 'url' => '/chaises.html', 'productCount' => 10],
            ]],
        ];

        $result = $resolver->resolve($this->ctx(), $toolCalls, 'Je cherche des chaises');

        $this->assertFalse($result['isDefaultIntent']);
        $this->assertCount(1, $result['suggestions']);
        $suggestion = $result['suggestions'][0]->toArray();
        $this->assertSame('See also Tabourets', $suggestion['label']);
        $this->assertSame(['type' => 'navigate', 'url' => 'https://shop.example/tabourets.html'], $suggestion['action']);
    }

    public function testCatalogIntentFallsBackToTheFirstProductsOwnCategoryWhenNothingMatched(): void
    {
        $resolver = $this->resolver(
            siblings: [['id' => 9, 'title' => 'Tabourets', 'url' => '/tabourets.html', 'productCount' => 4]],
            defaultCategoryId: 3,
        );
        $toolCalls = [
            ['name' => 'search_products', 'result' => ['products' => [['id' => 1, 'title' => 'Stacy']], 'matched_category' => null]],
        ];

        $result = $resolver->resolve($this->ctx(), $toolCalls, 'stacy');

        $this->assertCount(1, $result['suggestions']);
    }

    public function testCatalogIntentOmitsTheSuggestionWhenNoRealSiblingExists(): void
    {
        // Zero suggestion fantôme (MYO-263): a product with no resolvable sibling
        // category yields no suggestion at all, never a guessed one.
        $resolver = $this->resolver(siblings: [], defaultCategoryId: null);
        $toolCalls = [
            ['name' => 'search_products', 'result' => [
                'products' => [['id' => 1, 'title' => 'Chaise Stacy']],
                'matched_category' => ['id' => 3, 'title' => 'Chaises', 'url' => '/chaises.html', 'productCount' => 10],
            ]],
        ];

        $result = $resolver->resolve($this->ctx(), $toolCalls, 'chaises');

        $this->assertSame([], $result['suggestions']);
    }

    public function testCatalogIntentSurvivesAnOpenPageCallChainedAfterTheSearch(): void
    {
        // Real e2e recette flow (MYO-290): "I am looking for chairs" makes the
        // agent search, then immediately open_page the visitor straight to the
        // matched category. open_page must not shadow the search's intent.
        $resolver = $this->resolver(siblings: [
            ['id' => 9, 'title' => 'Stools', 'url' => 'https://shop.example/stools.html', 'productCount' => 4],
        ]);
        $toolCalls = [
            ['name' => 'get_categories', 'result' => ['categories' => []]],
            ['name' => 'search_products', 'result' => [
                'products' => [['id' => 1, 'title' => 'Chair']],
                'matched_category' => ['id' => 3, 'title' => 'Chairs', 'url' => '/chairs.html', 'productCount' => 10],
            ]],
            ['name' => 'open_page', 'result' => ['navigation' => ['url' => 'https://shop.example/chairs.html']]],
        ];

        $result = $resolver->resolve($this->ctx(), $toolCalls, 'I am looking for chairs');

        $this->assertFalse($result['isDefaultIntent']);
        $this->assertCount(1, $result['suggestions']);
        $this->assertSame('See also Stools', $result['suggestions'][0]->toArray()['label']);
    }

    public function testCatalogIntentIgnoresAnOptionOnlyVariantSearch(): void
    {
        $resolver = $this->resolver(siblings: [['id' => 9, 'title' => 'Tabourets', 'url' => '/t.html', 'productCount' => 4]]);
        $toolCalls = [
            ['name' => 'search_products', 'result' => ['variants' => [['id' => 1, 'label' => 'Orange']], 'matched_option' => ['id' => 5, 'title' => 'Orange']]],
        ];

        $result = $resolver->resolve($this->ctx(), $toolCalls, 'les chaises orange');

        $this->assertSame([], $result['suggestions']);
    }

    public function testAccountIntentForAnonymousVisitorOffersLoginAndRegister(): void
    {
        $resolver = $this->resolver();
        $toolCalls = [['name' => 'get_orders', 'result' => ['orders' => []]]];

        $result = $resolver->resolve($this->ctx(null), $toolCalls, 'où sont mes commandes');

        $labels = array_map(static fn ($s) => $s->toArray()['label'], $result['suggestions']);
        $this->assertSame(['Log in', 'Create my account'], $labels);
    }

    public function testAccountIntentForConnectedCustomerOffersAddressOrdersAndAccount(): void
    {
        $resolver = $this->resolver();

        $result = $resolver->resolve($this->ctx(42), [], 'je veux modifier mon adresse');

        $labels = array_map(static fn ($s) => $s->toArray()['label'], $result['suggestions']);
        $this->assertSame(['Edit my address', 'View all my orders', 'Go to my account'], $labels);
        $this->assertCount(3, $result['suggestions']);
    }

    public function testCartIntentHidesCompleteOrderWhenTheCartIsEmpty(): void
    {
        $resolver = $this->resolver(cartItemCount: 0);

        $result = $resolver->resolve($this->ctx(), [], 'quelles sont vos conditions de livraison ?');

        $labels = array_map(static fn ($s) => $s->toArray()['label'], $result['suggestions']);
        $this->assertSame(['Your cart', 'What are your shipping conditions?'], $labels);
    }

    public function testCartIntentShowsCompleteOrderWhenTheCartHasItems(): void
    {
        $resolver = $this->resolver(cartItemCount: 2);
        $toolCalls = [['name' => 'add_to_cart', 'result' => ['cart' => ['itemCount' => 2]]]];

        $result = $resolver->resolve($this->ctx(), $toolCalls, 'ajoute au panier');

        $labels = array_map(static fn ($s) => $s->toArray()['label'], $result['suggestions']);
        $this->assertSame(['Your cart', 'Complete my order', 'What are your shipping conditions?'], $labels);
    }

    public function testSupportIntentOffersOnlyTheReturnPolicyWithoutARealContactPage(): void
    {
        $resolver = $this->resolver();

        $result = $resolver->resolve($this->ctx(), [], 'je veux faire un retour, remboursement possible ?');

        $this->assertCount(1, $result['suggestions']);
        $this->assertSame('Return policy', $result['suggestions'][0]->toArray()['label']);
    }

    public function testSupportIntentAddsContactPageOnlyWhenTheTurnActuallyResolvedOne(): void
    {
        $resolver = $this->resolver();
        $toolCalls = [['name' => 'get_site_pages', 'result' => ['pages' => [
            ['title' => 'Nous contacter', 'url' => '/contact.html', 'type' => 'static'],
        ]]]];

        $result = $resolver->resolve($this->ctx(), $toolCalls, 'remboursement possible ?');

        $labels = array_map(static fn ($s) => $s->toArray()['label'], $result['suggestions']);
        $this->assertSame(['Return policy', 'Contact customer service'], $labels);
    }

    public function testDefaultIntentReusesTheHeroPromptsAndCapsAtThreeWhenConnected(): void
    {
        $resolver = $this->resolver();

        $result = $resolver->resolve($this->ctx(42), [], 'bonjour');

        $this->assertTrue($result['isDefaultIntent']);
        $this->assertCount(3, $result['suggestions']);
        $labels = array_map(static fn ($s) => $s->toArray()['label'], $result['suggestions']);
        $this->assertSame(['I am looking for a product…', 'What is in my cart?', 'What are your shipping conditions?'], $labels);
    }

    public function testDefaultIntentForAnonymousVisitor(): void
    {
        $resolver = $this->resolver();

        $result = $resolver->resolve($this->ctx(null), [], 'salut');

        $this->assertTrue($result['isDefaultIntent']);
        $this->assertCount(3, $result['suggestions']);
    }
}

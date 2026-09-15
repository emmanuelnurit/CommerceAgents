<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Service;

use CommerceAgents\Service\SystemPromptFactory;
use CommerceAgents\Tool\Admin\Gateway\AdminPagesGatewayInterface;
use CommerceAgents\Tool\Shopping\Gateway\CategoryGatewayInterface;
use CommerceAgents\Tool\Shopping\Gateway\FeatureGatewayInterface;
use CommerceAgents\Tool\Shopping\Gateway\OptionGatewayInterface;
use CommerceAgents\Tool\Shopping\Gateway\SitePagesGatewayInterface;
use PHPUnit\Framework\TestCase;

class FakePromptAdminPagesGateway implements AdminPagesGatewayInterface
{
    /** @param array[] $pages */
    public function __construct(private readonly array $pages = [
        ['title' => 'Orders', 'url' => 'https://shop.example/admin/orders', 'key' => 'orders'],
        ['title' => 'Sales and promotions', 'url' => 'https://shop.example/admin/sales', 'key' => 'sales'],
    ])
    {
    }

    public function getPages(?string $query, string $locale): array
    {
        return $this->pages;
    }
}

class FakePromptSitePagesGateway implements SitePagesGatewayInterface
{
    /** @param array[] $pages */
    public function __construct(private readonly array $pages = [
        ['title' => 'Home', 'url' => 'https://shop.example/', 'type' => 'static'],
        ['title' => 'Gardening', 'url' => 'https://shop.example/gardening.html', 'type' => 'category'],
    ])
    {
    }

    public function getPages(?string $query, string $locale): array
    {
        return $this->pages;
    }
}

class FakePromptCategoryGateway implements CategoryGatewayInterface
{
    /** @param array[] $categories */
    public function __construct(private readonly array $categories = [
        ['id' => 3, 'title' => 'Chairs', 'url' => 'https://shop.example/chairs.html', 'productCount' => 14],
        ['id' => 5, 'title' => 'Armchairs', 'url' => 'https://shop.example/armchairs.html', 'productCount' => 10],
    ])
    {
    }

    public function getCategories(string $locale, int $limit): array
    {
        return \array_slice($this->categories, 0, $limit);
    }

    public function findById(int $id, string $locale): ?array
    {
        foreach ($this->categories as $category) {
            if ($category['id'] === $id) {
                return $category;
            }
        }

        return null;
    }

    public function findByName(string $name, string $locale): ?array
    {
        return $this->categories[0] ?? null;
    }
}

class FakePromptOptionGateway implements OptionGatewayInterface
{
    /** @param array[] $values */
    public function __construct(private readonly array $values = [
        ['id' => 3, 'title' => 'Orange', 'attribute' => 'Couleur', 'variantCount' => 11],
        ['id' => 1, 'title' => 'Bleu', 'attribute' => 'Couleur', 'variantCount' => 18],
    ])
    {
    }

    public function getValues(string $locale, int $limit): array
    {
        return \array_slice($this->values, 0, $limit);
    }

    public function findValueByName(string $term, string $locale): ?array
    {
        return $this->values[0] ?? null;
    }
}

class FakePromptFeatureGateway implements FeatureGatewayInterface
{
    /** @param array[] $values */
    public function __construct(private readonly array $values = [
        ['id' => 1, 'title' => 'Tissu', 'feature' => 'Matière', 'productCount' => 17],
        ['id' => 2, 'title' => 'Bois', 'feature' => 'Matière', 'productCount' => 12],
    ])
    {
    }

    public function getValues(string $locale, int $limit): array
    {
        return \array_slice($this->values, 0, $limit);
    }

    public function findValueByName(string $term, string $locale): ?array
    {
        return $this->values[0] ?? null;
    }
}

class SystemPromptFactoryTest extends TestCase
{
    private SystemPromptFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new SystemPromptFactory(new FakePromptAdminPagesGateway(), new FakePromptSitePagesGateway(), new FakePromptCategoryGateway(), new FakePromptOptionGateway(), new FakePromptFeatureGateway());
    }

    public function testShoppingPromptEnforcesSelectedLanguage(): void
    {
        $prompt = $this->factory->shopping('Alex', 'fr_FR');

        $this->assertStringContainsString('Alex', $prompt);
        $this->assertStringContainsString('French', $prompt);
        $this->assertStringContainsString('even if the customer writes in another language', $prompt);
        $this->assertStringNotContainsString('fr_FR', $prompt);
    }

    public function testShoppingPromptEnglishLocale(): void
    {
        $prompt = $this->factory->shopping('Alex', 'en_US');

        $this->assertStringContainsString('English', $prompt);
    }

    public function testMerchantPromptEnforcesSelectedLanguage(): void
    {
        $prompt = $this->factory->merchant('fr_FR');

        $this->assertStringContainsString('French', $prompt);
        $this->assertStringContainsString('Never invent figures', $prompt);
    }

    public function testMerchantPromptExplainsStagedProposals(): void
    {
        $prompt = $this->factory->merchant('fr_FR');

        $this->assertStringContainsString('NEVER applied directly', $prompt);
        $this->assertStringContainsString('approval console', $prompt);
        $this->assertStringNotContainsString('read-only', $prompt);
    }

    public function testShoppingPromptRequiresLinks(): void
    {
        $prompt = $this->factory->shopping('Alex', 'fr_FR');

        $this->assertStringContainsString('include its link', $prompt);
        $this->assertStringContainsString('get_site_pages', $prompt);
    }

    public function testShoppingPromptPrefersNavigationOverLinks(): void
    {
        $prompt = $this->factory->shopping('Alex', 'fr_FR');

        $this->assertStringContainsString('open_page', $prompt);
        $this->assertStringContainsString('take them there', $prompt);
    }

    public function testShoppingPromptGuidesToolUsage(): void
    {
        $prompt = $this->factory->shopping('Alex', 'fr_FR');

        $this->assertStringContainsString('search_products', $prompt);
        $this->assertStringContainsString('repeat a tool call', $prompt);
        $this->assertStringContainsString('never describe a product from memory', $prompt);
        $this->assertStringContainsString('product name or category', $prompt);
    }

    public function testMerchantPromptRequiresLinks(): void
    {
        $prompt = $this->factory->merchant('fr_FR');

        $this->assertStringContainsString('include its link', $prompt);
    }

    public function testMerchantPromptExplainsBackOfficeNavigation(): void
    {
        $prompt = $this->factory->merchant('fr_FR');

        $this->assertStringContainsString('open_admin_page', $prompt);
        $this->assertStringContainsString('get_admin_pages', $prompt);
    }

    public function testUnknownLocaleFallsBackToRawCode(): void
    {
        $prompt = $this->factory->shopping('Alex', 'xx_XX');

        $this->assertStringContainsString('xx_XX', $prompt);
    }

    public function testMerchantPromptListsTheRealBackOfficeUrls(): void
    {
        $prompt = $this->factory->merchant('fr_FR');

        $this->assertStringContainsString('- Sales and promotions: https://shop.example/admin/sales', $prompt);
        $this->assertStringContainsString('- Orders: https://shop.example/admin/orders', $prompt);
    }

    public function testMerchantPromptForbidsInventingABackOfficeUrl(): void
    {
        $prompt = $this->factory->merchant('fr_FR');

        $this->assertStringContainsString('Never write a back-office link that is not in it', $prompt);
        $this->assertStringContainsString('never build a URL by guessing a path', $prompt);
    }

    public function testMerchantPromptStaysReadableWithoutAnyPage(): void
    {
        $factory = new SystemPromptFactory(new FakePromptAdminPagesGateway([]), new FakePromptSitePagesGateway(), new FakePromptCategoryGateway(), new FakePromptOptionGateway(), new FakePromptFeatureGateway());

        $prompt = $factory->merchant('fr_FR');

        $this->assertStringNotContainsString('Back-office screens:', $prompt);
        $this->assertStringContainsString('Always answer in French', $prompt);
    }

    public function testShoppingPromptDoesNotCarryTheBackOfficeUrls(): void
    {
        $prompt = $this->factory->shopping('Alex', 'fr_FR');

        $this->assertStringNotContainsString('/admin/', $prompt);
    }

    public function testShoppingPromptListsTheRealStorePages(): void
    {
        $prompt = $this->factory->shopping('Alex', 'fr_FR');

        $this->assertStringContainsString('Store pages:', $prompt);
        $this->assertStringContainsString('- Gardening: https://shop.example/gardening.html', $prompt);
    }

    public function testShoppingPromptForbidsInventingAUrl(): void
    {
        $prompt = $this->factory->shopping('Alex', 'fr_FR');

        $this->assertStringContainsString('Never build a URL by guessing a path', $prompt);
        $this->assertStringContainsString('character for character', $prompt);
    }

    public function testMerchantPromptDoesNotCarryTheStorePages(): void
    {
        $prompt = $this->factory->merchant('fr_FR');

        $this->assertStringNotContainsString('Store pages:', $prompt);
    }

    public function testShoppingPromptListsTheCatalogCategories(): void
    {
        $prompt = $this->factory->shopping('Alex', 'fr_FR');

        $this->assertStringContainsString('Product categories:', $prompt);
        $this->assertStringContainsString('- Chairs (category_id 3, 14 products)', $prompt);
    }

    public function testShoppingPromptExplainsThatTypeWordsAreCategories(): void
    {
        $prompt = $this->factory->shopping('Alex', 'fr_FR');

        $this->assertStringContainsString('names a category, never a title', $prompt);
        $this->assertStringContainsString('get_categories', $prompt);
    }

    public function testShoppingPromptForbidsInventingADiscount(): void
    {
        $prompt = $this->factory->shopping('Alex', 'fr_FR');

        $this->assertStringContainsString('promo=true', $prompt);
        $this->assertStringContainsString('when promo_price is absent, never', $prompt);
    }

    public function testShoppingPromptLeavesTheProductListingToTheCards(): void
    {
        $prompt = $this->factory->shopping('Alex', 'fr_FR');

        $this->assertStringContainsString('never re-list the products', $prompt);
        $this->assertStringContainsString('no Markdown image ever', $prompt);
        $this->assertStringContainsString('a bullet list that repeats the cards is not', $prompt);
        $this->assertStringContainsString('get_product_details: its variants are displayed as cards', $prompt);
        $this->assertStringContainsString('Close on one sentence', $prompt);
    }

    public function testShoppingPromptStaysReadableWithoutAnyCategory(): void
    {
        $factory = new SystemPromptFactory(new FakePromptAdminPagesGateway(), new FakePromptSitePagesGateway(), new FakePromptCategoryGateway([]), new FakePromptOptionGateway(), new FakePromptFeatureGateway());

        $prompt = $factory->shopping('Alex', 'fr_FR');

        $this->assertStringNotContainsString('Product categories:', $prompt);
        $this->assertStringContainsString('Always answer in French', $prompt);
    }

    public function testMerchantPromptDoesNotCarryTheCatalogCategories(): void
    {
        $prompt = $this->factory->merchant('fr_FR');

        $this->assertStringNotContainsString('Product categories:', $prompt);
    }

    public function testShoppingPromptPinsTheLanguageBeforeAnythingElse(): void
    {
        $prompt = $this->factory->shopping('Alex', 'fr_FR');

        $this->assertStringContainsString('You write every single reply in French', $prompt);
        $this->assertStringContainsString('not when the visitor writes to you in another', $prompt);
        // Stated up front and repeated at the end: the tail alone was not enough.
        $this->assertSame(2, substr_count($prompt, 'French (français)'));
    }

    public function testMerchantPromptPinsTheLanguageBeforeAnythingElse(): void
    {
        $prompt = $this->factory->merchant('fr_FR');

        $this->assertStringContainsString('You write every single reply in French', $prompt);
        $this->assertStringContainsString('administrator profile', $prompt);
    }

    public function testLanguageIsNamedInEnglishAndInItself(): void
    {
        $this->assertStringContainsString('Italian (italiano)', $this->factory->shopping('Alex', 'it_IT'));
        $this->assertStringContainsString('Spanish (español)', $this->factory->shopping('Alex', 'es_ES'));
    }

    public function testEnglishIsNotNamedTwiceOverItself(): void
    {
        $prompt = $this->factory->shopping('Alex', 'en_US');

        $this->assertStringContainsString('every single reply in English', $prompt);
        $this->assertStringNotContainsString('English (English)', $prompt);
    }

    public function testShoppingPromptListsTheOptionVocabulary(): void
    {
        $prompt = $this->factory->shopping('Alex', 'fr_FR');

        $this->assertStringContainsString('Option values:', $prompt);
        $this->assertStringContainsString('- Couleur: Orange (11 variants)', $prompt);
        $this->assertStringContainsString('option="orange"', $prompt);
    }

    public function testShoppingPromptStaysReadableWithoutAnyOption(): void
    {
        $factory = new SystemPromptFactory(
            new FakePromptAdminPagesGateway(),
            new FakePromptSitePagesGateway(),
            new FakePromptCategoryGateway(),
            new FakePromptOptionGateway([]),
            new FakePromptFeatureGateway(),
        );

        $prompt = $factory->shopping('Alex', 'fr_FR');

        $this->assertStringNotContainsString('Option values:', $prompt);
        $this->assertStringContainsString('Always answer in French', $prompt);
    }

    public function testMerchantPromptDoesNotCarryTheOptionVocabulary(): void
    {
        $this->assertStringNotContainsString('Option values:', $this->factory->merchant('fr_FR'));
    }

    public function testShoppingPromptListsTheFeatureVocabulary(): void
    {
        $prompt = $this->factory->shopping('Alex', 'fr_FR');

        $this->assertStringContainsString('Feature values:', $prompt);
        $this->assertStringContainsString('- Matière: Tissu (17 products)', $prompt);
        $this->assertStringContainsString('feature="tissu"', $prompt);
    }

    public function testShoppingPromptForbidsAnsweringAMaterialWithACategory(): void
    {
        $prompt = $this->factory->shopping('Alex', 'fr_FR');

        $this->assertStringContainsString('a sofa is not made of fabric because it is a sofa', $prompt);
    }

    public function testShoppingPromptStaysReadableWithoutAnyFeature(): void
    {
        $factory = new SystemPromptFactory(
            new FakePromptAdminPagesGateway(),
            new FakePromptSitePagesGateway(),
            new FakePromptCategoryGateway(),
            new FakePromptOptionGateway(),
            new FakePromptFeatureGateway([]),
        );

        $prompt = $factory->shopping('Alex', 'fr_FR');

        $this->assertStringNotContainsString('Feature values:', $prompt);
        $this->assertStringContainsString('Always answer in French', $prompt);
    }

    public function testMerchantPromptDoesNotCarryTheFeatureVocabulary(): void
    {
        $this->assertStringNotContainsString('Feature values:', $this->factory->merchant('fr_FR'));
    }
}

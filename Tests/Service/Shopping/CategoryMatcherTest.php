<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Service\Shopping;

use CommerceAgents\Service\Shopping\CategoryMatcher;
use PHPUnit\Framework\TestCase;

class CategoryMatcherTest extends TestCase
{
    private const DEMO_STORE = [
        ['id' => 3, 'title' => 'Chairs', 'url' => '/chairs.html', 'productCount' => 14],
        ['id' => 5, 'title' => 'Armchairs', 'url' => '/armchairs.html', 'productCount' => 10],
        ['id' => 6, 'title' => 'Sofas', 'url' => '/sofas.html', 'productCount' => 5],
        ['id' => 4, 'title' => 'Stools', 'url' => '/stools.html', 'productCount' => 3],
    ];

    public function testSingularAndPluralLandOnTheSameCategory(): void
    {
        $this->assertSame(3, CategoryMatcher::best('chair', self::DEMO_STORE)['id']);
        $this->assertSame(3, CategoryMatcher::best('chairs', self::DEMO_STORE)['id']);
    }

    public function testAMultiWordQueryStillMatches(): void
    {
        $this->assertSame(6, CategoryMatcher::best('a comfortable sofa for my living room', self::DEMO_STORE)['id']);
    }

    public function testAnUnrelatedTermMatchesNothing(): void
    {
        $this->assertNull(CategoryMatcher::best('lawnmower', self::DEMO_STORE));
    }

    public function testAnEmptyTermMatchesNothing(): void
    {
        $this->assertNull(CategoryMatcher::best('   ', self::DEMO_STORE));
    }

    public function testShortWordsAreIgnoredSoTheyDoNotMatchEverything(): void
    {
        $this->assertNull(CategoryMatcher::best('a of', self::DEMO_STORE));
    }

    public function testAccentsAndCaseAreIgnored(): void
    {
        $categories = [['id' => 5, 'title' => 'Fauteuils', 'url' => '/fauteuils.html', 'productCount' => 10]];

        $this->assertSame(5, CategoryMatcher::best('FAUTEUIL', $categories)['id']);
    }

    public function testATieGoesToTheBiggerCategory(): void
    {
        $categories = [
            ['id' => 9, 'title' => 'Chairs', 'url' => '/outlet.html', 'productCount' => 1],
            ['id' => 3, 'title' => 'Chairs', 'url' => '/chairs.html', 'productCount' => 14],
        ];

        $this->assertSame(3, CategoryMatcher::best('chair', $categories)['id']);
    }

    public function testAnEmptyCatalogMatchesNothing(): void
    {
        $this->assertNull(CategoryMatcher::best('chair', []));
    }
}

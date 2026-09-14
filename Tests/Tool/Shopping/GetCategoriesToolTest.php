<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Tool\Shopping;

use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Tool\Shopping\Gateway\CategoryGatewayInterface;
use CommerceAgents\Tool\Shopping\GetCategoriesTool;
use PHPUnit\Framework\TestCase;

class FakeCategoryGateway implements CategoryGatewayInterface
{
    public array $lastCall = [];

    public function __construct(private readonly array $categories = [
        ['id' => 3, 'title' => 'Chairs', 'url' => 'https://shop.example/chairs.html', 'productCount' => 14],
        ['id' => 5, 'title' => 'Armchairs', 'url' => 'https://shop.example/armchairs.html', 'productCount' => 10],
    ]) {
    }

    public function getCategories(string $locale, int $limit): array
    {
        $this->lastCall = ['locale' => $locale, 'limit' => $limit];

        return \array_slice($this->categories, 0, $limit);
    }

    public function findByName(string $name, string $locale): ?array
    {
        return null;
    }
}

class GetCategoriesToolTest extends TestCase
{
    public function testListsCategoriesInTheVisitorLocale(): void
    {
        $gateway = new FakeCategoryGateway();

        $result = (new GetCategoriesTool($gateway))->execute([], new ToolContext(locale: 'fr_FR'));

        $this->assertSame('fr_FR', $gateway->lastCall['locale']);
        $this->assertSame(2, $result['count']);
        $this->assertSame('Chairs', $result['categories'][0]['title']);
        $this->assertSame(14, $result['categories'][0]['productCount']);
    }

    public function testAllowedForFrontDeniedForAdmin(): void
    {
        $tool = new GetCategoriesTool(new FakeCategoryGateway());

        $this->assertTrue($tool->isAllowed(new ToolContext(isAdmin: false)));
        $this->assertFalse($tool->isAllowed(new ToolContext(isAdmin: true)));
    }

    public function testSchemaTakesNoArgument(): void
    {
        $schema = (new GetCategoriesTool(new FakeCategoryGateway()))->getInputSchema();

        $this->assertSame([], $schema['required']);
        $this->assertInstanceOf(\stdClass::class, $schema['properties']);
    }
}

<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Service;

use CommerceAgents\Service\SystemPromptFactory;
use PHPUnit\Framework\TestCase;

class SystemPromptFactoryTest extends TestCase
{
    private SystemPromptFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new SystemPromptFactory();
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

    public function testMerchantPromptRequiresLinks(): void
    {
        $prompt = $this->factory->merchant('fr_FR');

        $this->assertStringContainsString('include its link', $prompt);
    }

    public function testUnknownLocaleFallsBackToRawCode(): void
    {
        $prompt = $this->factory->shopping('Alex', 'xx_XX');

        $this->assertStringContainsString('xx_XX', $prompt);
    }
}

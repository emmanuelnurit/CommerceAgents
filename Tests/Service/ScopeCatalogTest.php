<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Service;

use CommerceAgents\Service\ScopeCatalog;
use PHPUnit\Framework\TestCase;

class ScopeCatalogTest extends TestCase
{
    public function testAppendCategoryScopeAddsAFixedSentence(): void
    {
        $result = ScopeCatalog::appendCategoryScope('Tu réponds aux avis clients.', ['Chaises', 'Bureaux']);

        $this->assertSame(
            'Tu réponds aux avis clients. Concentre-toi uniquement sur les produits des catégories : Chaises, Bureaux.',
            $result,
        );
    }

    public function testAppendCategoryScopeWithNoCategoriesLeavesThePromptUntouched(): void
    {
        $this->assertSame('Tu réponds aux avis clients.', ScopeCatalog::appendCategoryScope('Tu réponds aux avis clients.', []));
    }

    public function testAppendCustomerScopeAllAddsNoSentence(): void
    {
        $this->assertSame('Tu réponds aux avis clients.', ScopeCatalog::appendCustomerScope('Tu réponds aux avis clients.', ScopeCatalog::CUSTOMER_SCOPE_ALL));
    }

    public function testAppendCustomerScopeReturningAddsItsFixedSentence(): void
    {
        $result = ScopeCatalog::appendCustomerScope('Tu réponds aux avis clients.', ScopeCatalog::CUSTOMER_SCOPE_RETURNING);

        $this->assertSame(
            "Tu réponds aux avis clients. Ne t'adresse qu'aux clients ayant déjà passé au moins une commande.",
            $result,
        );
    }

    public function testAppendCustomerScopeUnknownCodeLeavesThePromptUntouched(): void
    {
        $this->assertSame('Tu réponds aux avis clients.', ScopeCatalog::appendCustomerScope('Tu réponds aux avis clients.', 'does-not-exist'));
    }

    public function testBothScopesCanBeComposedTogether(): void
    {
        $rolePrompt = ScopeCatalog::appendCategoryScope('Tu réponds aux avis clients.', ['Chaises']);
        $rolePrompt = ScopeCatalog::appendCustomerScope($rolePrompt, ScopeCatalog::CUSTOMER_SCOPE_EXCLUDE_RESELLERS);

        $this->assertSame(
            "Tu réponds aux avis clients. Concentre-toi uniquement sur les produits des catégories : Chaises. N'inclus jamais les comptes revendeurs dans ton périmètre.",
            $rolePrompt,
        );
    }

    public function testCustomerScopesListsAllKnownCodes(): void
    {
        $this->assertSame(
            [ScopeCatalog::CUSTOMER_SCOPE_ALL, ScopeCatalog::CUSTOMER_SCOPE_RETURNING, ScopeCatalog::CUSTOMER_SCOPE_EXCLUDE_RESELLERS],
            ScopeCatalog::customerScopes(),
        );
    }
}

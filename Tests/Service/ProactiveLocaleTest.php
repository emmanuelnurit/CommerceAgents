<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Service;

use CommerceAgents\Service\ProactiveLocale;
use PHPUnit\Framework\TestCase;

final class ProactiveLocaleTest extends TestCase
{
    public function testSupportedVisitorLocaleWins(): void
    {
        $this->assertSame('en', ProactiveLocale::group('en_US', 'fr_FR'));
    }

    public function testUnsupportedVisitorLocaleFallsBackToTheSiteDefaultGroup(): void
    {
        // A store whose default language is Spanish must not see its
        // German-speaking visitor pulled towards French messages.
        $this->assertSame('es', ProactiveLocale::group('de_DE', 'es_ES'));
    }

    public function testNeitherLocaleSupportedFallsBackToEnglish(): void
    {
        $this->assertSame('en', ProactiveLocale::group('de_DE', 'de_DE'));
    }
}

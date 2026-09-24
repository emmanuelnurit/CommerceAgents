<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Service;

use CommerceAgents\Service\NewsletterOptinService;
use PHPUnit\Framework\Attributes\DataProvider;
use Thelia\Mailer\MailerFactory;
use Thelia\Model\ConfigQuery;
use Thelia\Test\IntegrationTestCase;

/**
 * MYO-524: NewsletterOptinServiceTest mocks MailerFactory entirely, so it
 * never exercises the actual template rendering -- exactly how the MYO-475
 * regression (Config/update/0.4.4.sql seeded Smarty syntax, but this
 * install's default parser is Twig, cf. ParserResolver/TwigEngine priority
 * 10 vs TheliaSmarty priority 0) shipped without a failing test. This uses
 * the real container -- the real ParserResolver picking the real default
 * parser, exactly as MailerFactory::createEmailMessage() does in
 * production -- to catch any tag the active parser cannot interpret.
 */
final class NewsletterOptinCouponEmailRenderingTest extends IntegrationTestCase
{
    private ?string $originalStoreName = null;
    private ?string $originalUrlSite = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalStoreName = ConfigQuery::getStoreName();
        $this->originalUrlSite = ConfigQuery::getConfiguredShopUrl();

        ConfigQuery::write('store_name', 'Acme Boutique');
        ConfigQuery::write('url_site', 'https://acme.example.com');
    }

    protected function tearDown(): void
    {
        ConfigQuery::write('store_name', $this->originalStoreName ?? '');
        ConfigQuery::write('url_site', $this->originalUrlSite ?? '');

        parent::tearDown();
    }

    /** @return iterable<string, array{string}> */
    public static function localeProvider(): iterable
    {
        yield 'fr_FR' => ['fr_FR'];
        yield 'en_US' => ['en_US'];
    }

    #[DataProvider('localeProvider')]
    public function testCouponEmailRendersWithNoLeftoverTemplateTags(string $locale): void
    {
        $mailer = static::getContainer()->get(MailerFactory::class);

        $email = $mailer->createEmailMessage(
            NewsletterOptinService::MESSAGE_CODE,
            ['boutique@example.com' => 'Acme Boutique'],
            ['jean@example.com' => 'jean@example.com'],
            [
                'coupon_code' => 'WELCOME10',
                'discount_label' => '-10%',
                'condition_label' => '',
            ],
            $locale,
        );

        $subject = (string) $email->getSubject();
        $text = (string) $email->getTextBody();
        $html = (string) $email->getHtmlBody();

        foreach ([$subject, $text, $html] as $rendered) {
            $this->assertStringNotContainsString('{{', $rendered, 'the parser must not leave a raw Twig tag in the sent e-mail');
            $this->assertStringNotContainsString('{config', $rendered, 'the parser must not leave a raw Smarty {config} tag in the sent e-mail');
            $this->assertStringNotContainsString('{$', $rendered, 'the parser must not leave a raw Smarty variable in the sent e-mail');
        }

        $this->assertStringContainsString('Acme Boutique', $subject, 'store_name must be substituted into the subject');
        $this->assertStringContainsString('WELCOME10', $text, 'coupon_code must be substituted into the text body');
        $this->assertStringContainsString('-10%', $text, 'discount_label must be substituted into the text body');
        $this->assertStringContainsString('WELCOME10', $html, 'coupon_code must be substituted into the html body');
        $this->assertStringContainsString('https://acme.example.com', $html, 'url_site must be substituted into the html body');
    }
}

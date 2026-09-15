<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Controller\Admin;

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\TwigFilter;

/**
 * MYO-284 B5: mcp.html.twig used `|raw` on `protocolVersions|join(...)`. The
 * audit found no exploitable path today (the values only ever come from the
 * `ServerInfo::SUPPORTED_PROTOCOL_VERSIONS` PHP constant, never a remote MCP
 * server or user input) but flagged it as a defensive-depth gap: if that
 * assumption ever breaks, `|raw` would turn it into stored/reflected XSS on
 * an admin page. This test proves the fixed markup auto-escapes its input
 * instead of relying on that assumption.
 */
final class McpDocTemplateTest extends TestCase
{
    private const TEMPLATE_PATH = __DIR__.'/../../../templates/backOffice/default-twig/merchant-chat/mcp.html.twig';

    public function testProtocolVersionsLineNoLongerUsesRawFilter(): void
    {
        $source = file_get_contents(self::TEMPLATE_PATH);
        self::assertIsString($source);
        self::assertMatchesRegularExpression('/Protocol versions.*<\/li>/', $source, 'The protocol versions line must still exist for the next assertion to be meaningful');

        self::assertStringNotContainsString('|raw', $source, 'mcp.html.twig must not use the |raw filter (MYO-284 B5)');
    }

    public function testProtocolVersionsAreEscapedWhenRendered(): void
    {
        $source = file_get_contents(self::TEMPLATE_PATH);
        self::assertIsString($source);

        if (!preg_match('/<li>\{\{ \'Protocol versions\'.*?<\/li>/', $source, $matches)) {
            self::fail('Could not locate the protocol versions <li> in mcp.html.twig');
        }

        $twig = new Environment(new ArrayLoader(['snippet' => $matches[0]]));
        $twig->addFilter(new TwigFilter('trans', static fn (string $value): string => $value));

        // A value that could never legitimately come from ServerInfo's
        // constant, standing in for "the assumption behind B5 broke".
        $rendered = $twig->render('snippet', ['protocolVersions' => ['2024-11-05', '<script>alert(1)</script>']]);

        self::assertStringContainsString('<code>2024-11-05</code>', $rendered);
        self::assertStringNotContainsString('<script>alert(1)</script>', $rendered, 'a hostile protocol version must be escaped, not injected as-is');
        self::assertStringContainsString('&lt;script&gt;', $rendered);
    }
}

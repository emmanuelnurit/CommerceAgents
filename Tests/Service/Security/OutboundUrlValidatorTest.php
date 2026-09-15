<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Service\Security;

use CommerceAgents\Service\Security\OutboundUrlValidator;
use PHPUnit\Framework\TestCase;

/**
 * MYO-276: every admin-configurable outbound URL (LLM base_url, webhook
 * target) must be https and resolve only to a public address, or a
 * misconfigured/malicious value turns the server into an SSRF proxy against
 * the internal network (169.254.169.254 cloud metadata included).
 */
class OutboundUrlValidatorTest extends TestCase
{
    private OutboundUrlValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new OutboundUrlValidator();
    }

    public function testRejectsNonHttpsSchemes(): void
    {
        self::assertFalse($this->validator->isAllowed('http://93.184.216.34/'));
        self::assertFalse($this->validator->isAllowed('file:///etc/passwd'));
        self::assertFalse($this->validator->isAllowed('gopher://93.184.216.34/'));
    }

    public function testRejectsMalformedUrls(): void
    {
        self::assertFalse($this->validator->isAllowed('not-a-url'));
        self::assertFalse($this->validator->isAllowed(''));
    }

    public function testRejectsLoopbackLiteralIps(): void
    {
        self::assertFalse($this->validator->isAllowed('https://127.0.0.1/'));
        self::assertFalse($this->validator->isAllowed('https://[::1]/'));
    }

    public function testRejectsCloudMetadataAddress(): void
    {
        self::assertFalse($this->validator->isAllowed('https://169.254.169.254/latest/meta-data/'));
    }

    public function testRejectsPrivateRfc1918Ranges(): void
    {
        self::assertFalse($this->validator->isAllowed('https://10.0.0.5/'));
        self::assertFalse($this->validator->isAllowed('https://172.16.0.5/'));
        self::assertFalse($this->validator->isAllowed('https://192.168.1.5/'));
    }

    public function testAllowsAPublicLiteralIpOverHttps(): void
    {
        self::assertTrue($this->validator->isAllowed('https://93.184.216.34/'));
    }
}

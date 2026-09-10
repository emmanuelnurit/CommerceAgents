<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Mcp\Protocol;

use CommerceAgents\Mcp\Protocol\JsonRpc;
use CommerceAgents\Mcp\Protocol\JsonRpcException;
use PHPUnit\Framework\TestCase;

class JsonRpcTest extends TestCase
{
    public function testParseReturnsDecodedObject(): void
    {
        $message = JsonRpc::parse('{"jsonrpc":"2.0","id":1,"method":"ping"}');

        $this->assertSame(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'], $message);
    }

    public function testParseRejectsInvalidJson(): void
    {
        $this->expectException(JsonRpcException::class);
        $this->expectExceptionCode(JsonRpc::PARSE_ERROR);

        JsonRpc::parse('{not json');
    }

    public function testParseRejectsNonObjectPayload(): void
    {
        $this->expectException(JsonRpcException::class);
        $this->expectExceptionCode(JsonRpc::INVALID_REQUEST);

        JsonRpc::parse('"just a string"');
    }

    public function testEncodeKeepsSlashesAndUnicode(): void
    {
        $this->assertSame('{"url":"/admin/é"}', JsonRpc::encode(['url' => '/admin/é']));
    }

    public function testResponseAndErrorBuilders(): void
    {
        $this->assertSame(
            ['jsonrpc' => '2.0', 'id' => 7, 'result' => ['ok' => true]],
            JsonRpc::response(7, ['ok' => true]),
        );

        $this->assertSame(
            ['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => JsonRpc::METHOD_NOT_FOUND, 'message' => 'Unknown method "x"']],
            JsonRpc::error(null, JsonRpc::METHOD_NOT_FOUND, 'Unknown method "x"'),
        );
    }
}

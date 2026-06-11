<?php

declare(strict_types=1);

namespace Botbye\Tests\Protection;

use Botbye\Protection\BotbyeConfig;
use PHPUnit\Framework\TestCase;

final class BotbyeConfigTest extends TestCase
{
    public function testConfigWithServerKey(): void
    {
        $config = new BotbyeConfig(serverKey: 'test-key-123');

        $this->assertSame('test-key-123', $config->serverKey);
        $this->assertSame('https://verify.botbye.com', $config->botbyeEndpoint);
        $this->assertSame('application/json', $config->contentType);
    }

    public function testConfigWithCustomEndpoint(): void
    {
        $config = new BotbyeConfig(
            serverKey: 'test-key',
            botbyeEndpoint: 'https://custom.endpoint.com'
        );

        $this->assertSame('https://custom.endpoint.com', $config->botbyeEndpoint);
    }

    public function testConfigThrowsExceptionWhenServerKeyIsEmpty(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('[BotBye] server key is not specified');

        new BotbyeConfig(serverKey: '');
    }
}

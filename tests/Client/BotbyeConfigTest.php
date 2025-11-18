<?php

declare(strict_types=1);

namespace Botbye\Tests\Client;

use Botbye\Client\BotbyeConfig;
use PHPUnit\Framework\TestCase;

final class BotbyeConfigTest extends TestCase
{
    public function testConfigWithServerKey(): void
    {
        $config = new BotbyeConfig(serverKey: 'test-key-123');

        $this->assertSame('test-key-123', $config->serverKey);
        $this->assertSame('https://verify.botbye.com', $config->botbyeEndpoint);
        $this->assertSame('application/json', $config->contentType);
        $this->assertSame(2.0, $config->readTimeout);
        $this->assertSame(5.0, $config->callTimeout);
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

    public function testModuleConstants(): void
    {
        $this->assertSame('PHP', BotbyeConfig::MODULE_NAME);
        $this->assertSame('1.0.0', BotbyeConfig::MODULE_VERSION);
    }
}

<?php

declare(strict_types=1);

namespace Botbye\Tests\Phishing;

use Botbye\Phishing\BotbyePhishingConfig;
use PHPUnit\Framework\TestCase;

final class BotbyePhishingConfigTest extends TestCase
{
    public function testConfigNeedsOnlyClientKeyAndNoServerKey(): void
    {
        $config = new BotbyePhishingConfig(
            endpoint: 'https://verify.botbye.com',
            clientKey: 'public-client-key',
        );

        $this->assertSame('https://verify.botbye.com', $config->endpoint);
        $this->assertSame('public-client-key', $config->clientKey);
    }

    public function testConfigNormalizesEndpointTrailingSlash(): void
    {
        $config = new BotbyePhishingConfig(
            endpoint: 'https://verify.botbye.com/',
            clientKey: 'public-client-key',
        );

        $this->assertSame('https://verify.botbye.com', $config->endpoint);
    }

    public function testConfigThrowsWhenClientKeyIsEmpty(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('[BotBye] phishing clientKey is not specified');

        new BotbyePhishingConfig(endpoint: 'https://verify.botbye.com', clientKey: '');
    }
}

<?php

declare(strict_types=1);

namespace Botbye\Tests\Model;

use Botbye\Model\ConnectionDetails;
use PHPUnit\Framework\TestCase;

final class ConnectionDetailsTest extends TestCase
{
    public function testConnectionDetailsSerialization(): void
    {
        $details = new ConnectionDetails(
            remoteAddr: '192.168.1.1',
            requestMethod: 'POST',
            requestUri: '/api/login'
        );

        $serialized = $details->jsonSerialize();

        $this->assertSame('192.168.1.1', $serialized['remote_addr']);
        $this->assertSame('POST', $serialized['request_method']);
        $this->assertSame('/api/login', $serialized['request_uri']);
    }

    public function testConnectionDetailsFromArray(): void
    {
        $details = ConnectionDetails::fromArray([
            'remote_addr' => '10.0.0.1',
            'request_method' => 'GET',
            'request_uri' => '/home',
        ]);

        $this->assertSame('10.0.0.1', $details->remoteAddr);
        $this->assertSame('GET', $details->requestMethod);
        $this->assertSame('/home', $details->requestUri);
    }
}

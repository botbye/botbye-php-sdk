<?php

declare(strict_types=1);

namespace Botbye\Tests\Model;

use Botbye\Model\Headers;
use PHPUnit\Framework\TestCase;

final class HeadersTest extends TestCase
{
    public function testHeadersSerialization(): void
    {
        $headers = new Headers([
            'User-Agent' => ['Mozilla/5.0', 'Chrome'],
            'Accept' => ['application/json'],
        ]);

        $serialized = $headers->jsonSerialize();

        $this->assertSame('Mozilla/5.0, Chrome', $serialized['User-Agent']);
        $this->assertSame('application/json', $serialized['Accept']);
    }

    public function testHeadersFromArray(): void
    {
        $headers = Headers::fromArray([
            'User-Agent' => 'Mozilla/5.0',
            'Accept' => ['application/json', 'text/html'],
        ]);

        $serialized = $headers->jsonSerialize();

        $this->assertSame('Mozilla/5.0', $serialized['User-Agent']);
        $this->assertSame('application/json, text/html', $serialized['Accept']);
    }
}

<?php

declare(strict_types=1);

namespace Botbye\Tests\Model;

use Botbye\Model\BotbyeChallengeResult;
use Botbye\Model\BotbyeError;
use Botbye\Model\BotbyeValidatorResponse;
use PHPUnit\Framework\TestCase;

final class BotbyeValidatorResponseTest extends TestCase
{
    public function testValidatorResponseFromArray(): void
    {
        $data = [
            'result' => ['isAllowed' => false],
            'reqId' => '12345678-1234-1234-1234-123456789012',
            'error' => ['message' => 'Test error'],
        ];

        $response = BotbyeValidatorResponse::fromArray($data);

        $this->assertFalse($response->result->isAllowed);
        $this->assertSame('12345678-1234-1234-1234-123456789012', $response->reqId);
        $this->assertSame('Test error', $response->error->message);
    }

    public function testValidatorResponseWithDefaults(): void
    {
        $response = new BotbyeValidatorResponse();

        $this->assertNull($response->result);
        $this->assertSame('00000000-0000-0000-0000-000000000000', $response->reqId);
        $this->assertNull($response->error);
    }

    public function testValidatorResponseSerialization(): void
    {
        $response = new BotbyeValidatorResponse(
            result: new BotbyeChallengeResult(isAllowed: true),
            reqId: 'test-req-id',
            error: null
        );

        $serialized = $response->jsonSerialize();

        $this->assertTrue($serialized['result']->isAllowed);
        $this->assertSame('test-req-id', $serialized['reqId']);
        $this->assertNull($serialized['error']);
    }
}

<?php

declare(strict_types=1);

namespace Botbye\Tests\Model;

use Botbye\Protection\Model\BotbyeEvaluateResponse;
use Botbye\Protection\Model\Decision;
use PHPUnit\Framework\TestCase;

final class BotbyeEvaluateResponseTest extends TestCase
{
    public function testFromArrayWithAllFields(): void
    {
        $data = [
            'request_id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            'decision' => 'BLOCK',
            'risk_score' => 0.95,
            'signals' => ['bot_detected'],
            'scores' => ['bot' => 0.95, 'ato' => 0.3],
            'config' => ['bypass_bot_validation' => false],
            'error' => null,
        ];

        $response = BotbyeEvaluateResponse::fromArray($data);

        $this->assertSame('aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', $response->requestId);
        $this->assertSame(Decision::BLOCK, $response->decision);
        $this->assertSame(0.95, $response->riskScore);
        $this->assertSame(['bot_detected'], $response->signals);
        $this->assertSame(['bot' => 0.95, 'ato' => 0.3], $response->scores);
        $this->assertFalse($response->config->bypassBotValidation);
        $this->assertNull($response->error);
        $this->assertTrue($response->isBlocked());
    }

    public function testFromArrayWithDefaults(): void
    {
        $response = BotbyeEvaluateResponse::fromArray([]);

        $this->assertNull($response->requestId);
        $this->assertSame(Decision::ALLOW, $response->decision);
        $this->assertNull($response->riskScore);
        $this->assertNull($response->signals);
        $this->assertNull($response->scores);
        $this->assertFalse($response->isBlocked());
    }

    public function testBypassFactory(): void
    {
        $response = BotbyeEvaluateResponse::bypass('[BotBye] network error');

        $this->assertSame(Decision::ALLOW, $response->decision);
        $this->assertTrue($response->config->bypassBotValidation);
        $this->assertSame('[BotBye] network error', $response->error?->message);
        $this->assertNull($response->requestId);
        $this->assertNull($response->riskScore);
        $this->assertNull($response->signals);
        $this->assertNull($response->scores);
        $this->assertFalse($response->isBlocked());
    }

    public function testBypassJsonContainsOnlyMeaningfulFields(): void
    {
        $response = BotbyeEvaluateResponse::bypass('[BotBye] connection error');
        $json = $response->jsonSerialize();

        $this->assertArrayHasKey('decision', $json);
        $this->assertArrayHasKey('config', $json);
        $this->assertArrayHasKey('error', $json);
        $this->assertArrayNotHasKey('request_id', $json);
        $this->assertArrayNotHasKey('risk_score', $json);
        $this->assertArrayNotHasKey('signals', $json);
        $this->assertArrayNotHasKey('scores', $json);
    }

    public function testNormalResponseJsonContainsAllFields(): void
    {
        $data = [
            'request_id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            'decision' => 'ALLOW',
            'risk_score' => 0.0,
            'signals' => [],
            'scores' => [],
            'config' => ['bypass_bot_validation' => false],
        ];

        $response = BotbyeEvaluateResponse::fromArray($data);
        $json = $response->jsonSerialize();

        $this->assertArrayHasKey('request_id', $json);
        $this->assertArrayHasKey('decision', $json);
        $this->assertArrayHasKey('risk_score', $json);
        $this->assertArrayHasKey('signals', $json);
        $this->assertArrayHasKey('scores', $json);
        $this->assertArrayHasKey('config', $json);
    }

    public function testIsBlockedOnlyForBlockDecision(): void
    {
        $this->assertFalse(BotbyeEvaluateResponse::fromArray(['decision' => 'ALLOW'])->isBlocked());
        $this->assertFalse(BotbyeEvaluateResponse::fromArray(['decision' => 'CHALLENGE'])->isBlocked());
        $this->assertTrue(BotbyeEvaluateResponse::fromArray(['decision' => 'BLOCK'])->isBlocked());
    }

    public function testUnknownDecisionFallsBackToAllow(): void
    {
        $response = BotbyeEvaluateResponse::fromArray(['decision' => 'UNKNOWN_FUTURE_VALUE']);
        $this->assertSame(Decision::ALLOW, $response->decision);
    }
}

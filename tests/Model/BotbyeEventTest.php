<?php

declare(strict_types=1);

namespace Botbye\Tests\Model;

use Botbye\Model\BotbyeFullEvent;
use Botbye\Model\BotbyeRiskScoringEvent;
use Botbye\Model\BotbyeUserInfo;
use Botbye\Model\BotbyeValidationEvent;
use Botbye\Model\EventStatus;
use PHPUnit\Framework\TestCase;

final class BotbyeEventTest extends TestCase
{
    private array $headers = ['User-Agent' => 'Mozilla/5.0', 'Accept' => 'application/json'];

    // --- BotbyeValidationEvent ---

    public function testValidationEventType(): void
    {
        $event = new BotbyeValidationEvent(
            ip: '1.2.3.4',
            token: 'device-token',
            headers: $this->headers,
        );

        $this->assertSame('validate', $event->getType());
        $this->assertSame('device-token', $event->getUrlToken());
    }

    public function testValidationEventSerialization(): void
    {
        $event = new BotbyeValidationEvent(
            ip: '1.2.3.4',
            token: 'device-token',
            headers: $this->headers,
            requestMethod: 'POST',
            requestUri: '/api/login',
        );

        $data = $event->jsonSerialize();

        $this->assertSame('validate', $data['type']);
        $this->assertSame('1.2.3.4', $data['request']['ip']);
        $this->assertSame('device-token', $data['request']['token']);
        $this->assertSame('POST', $data['request']['request_method']);
        $this->assertSame('/api/login', $data['request']['request_uri']);
        $this->assertFalse($data['config']['bypass_bot_validation']);
        $this->assertArrayNotHasKey('event', $data);
        $this->assertArrayNotHasKey('user', $data);
        $this->assertArrayNotHasKey('botbye_result', $data);
    }

    public function testValidationEventOmitsNullOptionalFields(): void
    {
        $event = new BotbyeValidationEvent(ip: '1.2.3.4', token: 'tok', headers: []);
        $data = $event->jsonSerialize();

        $this->assertArrayNotHasKey('request_method', $data['request']);
        $this->assertArrayNotHasKey('request_uri', $data['request']);
    }

    // --- BotbyeRiskScoringEvent ---

    public function testRiskScoringEventType(): void
    {
        $event = $this->makeRiskEvent();

        $this->assertSame('risk', $event->getType());
        $this->assertNull($event->getUrlToken());
    }

    public function testRiskScoringEventWithBotbyeResultDoesNotBypass(): void
    {
        $event = $this->makeRiskEvent(botbyeResult: 'base64result');
        $data = $event->jsonSerialize();

        $this->assertFalse($data['config']['bypass_bot_validation']);
        $this->assertSame('base64result', $data['botbye_result']);
    }

    public function testRiskScoringEventWithoutBotbyeResultSetsBypass(): void
    {
        $event = $this->makeRiskEvent(botbyeResult: null);
        $data = $event->jsonSerialize();

        $this->assertTrue($data['config']['bypass_bot_validation']);
        $this->assertArrayNotHasKey('botbye_result', $data);
    }

    public function testRiskScoringEventWithBlankBotbyeResultSetsBypass(): void
    {
        $event = $this->makeRiskEvent(botbyeResult: '');

        $this->assertTrue($event->bypassBotValidation);
        $this->assertArrayNotHasKey('botbye_result', $event->jsonSerialize());
    }

    public function testRiskScoringEventSerialization(): void
    {
        $event = $this->makeRiskEvent(botbyeResult: 'b64');
        $data = $event->jsonSerialize();

        $this->assertSame('risk', $data['type']);
        $this->assertSame('1.2.3.4', $data['request']['ip']);
        $this->assertSame('LOGIN', $data['event']['type']);
        $this->assertSame('SUCCESSFUL', $data['event']['status']);
        $this->assertArrayNotHasKey('token', $data['request']);
    }

    // --- BotbyeFullEvent ---

    public function testFullEventType(): void
    {
        $event = $this->makeFullEvent();

        $this->assertSame('full', $event->getType());
        $this->assertSame('device-token', $event->getUrlToken());
    }

    public function testFullEventSerialization(): void
    {
        $event = $this->makeFullEvent();
        $data = $event->jsonSerialize();

        $this->assertSame('full', $data['type']);
        $this->assertSame('device-token', $data['request']['token']);
        $this->assertFalse($data['config']['bypass_bot_validation']);
        $this->assertNotNull($data['event']);
        $this->assertNotNull($data['user']);
        $this->assertArrayNotHasKey('botbye_result', $data);
    }

    // --- helpers ---

    private function makeRiskEvent(?string $botbyeResult = 'b64result'): BotbyeRiskScoringEvent
    {
        return new BotbyeRiskScoringEvent(
            ip: '1.2.3.4',
            headers: $this->headers,
            user: new BotbyeUserInfo(accountId: 'user-123'),
            eventType: 'LOGIN',
            eventStatus: EventStatus::SUCCESSFUL,
            botbyeResult: $botbyeResult,
        );
    }

    private function makeFullEvent(): BotbyeFullEvent
    {
        return new BotbyeFullEvent(
            ip: '1.2.3.4',
            token: 'device-token',
            headers: $this->headers,
            user: new BotbyeUserInfo(accountId: 'user-123'),
            eventType: 'REGISTRATION',
            eventStatus: EventStatus::SUCCESSFUL,
        );
    }
}

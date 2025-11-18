<?php

declare(strict_types=1);

namespace Botbye\Tests\Model;

use Botbye\Model\BotbyeAtoResponse;
use Botbye\Model\BotbyeAtoResult;
use Botbye\Model\Decision;
use PHPUnit\Framework\TestCase;

final class BotbyeAtoResponseTest extends TestCase
{
    public function testAtoResponseFromArray(): void
    {
        $data = [
            'result' => [
                'decision' => 'BLOCK',
                'reason' => 'Suspicious activity detected',
            ],
            'error' => null,
        ];

        $response = BotbyeAtoResponse::fromArray($data);

        $this->assertSame(Decision::BLOCK, $response->result->decision);
        $this->assertSame('Suspicious activity detected', $response->result->reason);
        $this->assertNull($response->error);
    }

    public function testAtoResponseWithDefaults(): void
    {
        $response = new BotbyeAtoResponse();

        $this->assertNull($response->result);
        $this->assertNull($response->error);
    }

    public function testAtoResultDecisions(): void
    {
        $decisions = [
            Decision::ALLOW,
            Decision::BLOCK,
            Decision::MFA,
            Decision::CHALLENGE,
            Decision::IN_PROGRESS,
        ];

        foreach ($decisions as $decision) {
            $result = new BotbyeAtoResult(decision: $decision);
            $this->assertSame($decision, $result->decision);
        }
    }
}

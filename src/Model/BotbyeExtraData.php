<?php

declare(strict_types=1);

namespace Botbye\Model;

use JsonSerializable;

final readonly class BotbyeExtraData implements JsonSerializable
{
    public function __construct(
        public ?string $ip = null,
        public ?string $asn = null,
        public ?string $country = null,
        public ?string $browser = null,
        public ?string $browserVersion = null,
        public ?string $deviceName = null,
        public ?string $deviceType = null,
        public ?string $deviceCodeName = null,
        public ?string $platform = null,
        public ?string $platformVersion = null,
        public ?string $realIp = null,
        public ?string $realCountry = null,
    ) {
    }

    public function jsonSerialize(): array
    {
        return array_filter([
            'ip' => $this->ip,
            'asn' => $this->asn,
            'country' => $this->country,
            'browser' => $this->browser,
            'browserVersion' => $this->browserVersion,
            'deviceName' => $this->deviceName,
            'deviceType' => $this->deviceType,
            'deviceCodeName' => $this->deviceCodeName,
            'platform' => $this->platform,
            'platformVersion' => $this->platformVersion,
            'realIp' => $this->realIp,
            'realCountry' => $this->realCountry,
        ], fn($value) => $value !== null);
    }

    public static function fromArray(array $data): self
    {
        return new self(
            ip: $data['ip'] ?? null,
            asn: $data['asn'] ?? null,
            country: $data['country'] ?? null,
            browser: $data['browser'] ?? null,
            browserVersion: $data['browserVersion'] ?? null,
            deviceName: $data['deviceName'] ?? null,
            deviceType: $data['deviceType'] ?? null,
            deviceCodeName: $data['deviceCodeName'] ?? null,
            platform: $data['platform'] ?? null,
            platformVersion: $data['platformVersion'] ?? null,
            realIp: $data['realIp'] ?? null,
            realCountry: $data['realCountry'] ?? null,
        );
    }
}

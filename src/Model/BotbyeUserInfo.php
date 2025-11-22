<?php

declare(strict_types=1);

namespace Botbye\Model;

use JsonSerializable;

final class BotbyeUserInfo implements JsonSerializable
{
    public function __construct(
        public readonly string $accountId,
        public readonly ?string $username = null,
        public readonly ?string $email = null,
        public readonly ?string $phone = null,
    ) {
    }

    public function jsonSerialize(): array
    {
        return array_filter([
            'account_id' => $this->accountId,
            'username' => $this->username,
            'email' => $this->email,
            'phone' => $this->phone,
        ], fn($value) => $value !== null);
    }

    public static function fromArray(array $data): self
    {
        return new self(
            accountId: $data['account_id'] ?? '',
            username: $data['username'] ?? null,
            email: $data['email'] ?? null,
            phone: $data['phone'] ?? null,
        );
    }
}

<?php

declare(strict_types=1);

namespace Pritset\Model;

final readonly class WebhookJob
{
    public function __construct(public string $id)
    {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $id = $data['id'] ?? null;
        if (!is_string($id)) {
            throw new \UnexpectedValueException('Malformed webhook job.');
        }

        return new self($id);
    }
}

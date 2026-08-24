<?php

declare(strict_types=1);

namespace Pritset\Model;

final readonly class Template
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $tags = null,
        public ?string $templateObject = null,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            id: self::string($data, 'id'),
            name: self::string($data, 'name'),
            tags: self::nullableString($data, 'tags'),
            templateObject: self::nullableString($data, 'templateObject'),
        );
    }

    /** @param array<string, mixed> $data */
    private static function string(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value)) {
            throw new \UnexpectedValueException(sprintf('Expected "%s" to be a string.', $key));
        }

        return $value;
    }

    /** @param array<string, mixed> $data */
    private static function nullableString(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;
        if ($value !== null && !is_string($value)) {
            throw new \UnexpectedValueException(sprintf('Expected "%s" to be a string or null.', $key));
        }

        return $value;
    }
}

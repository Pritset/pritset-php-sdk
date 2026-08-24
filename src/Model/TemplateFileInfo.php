<?php

declare(strict_types=1);

namespace Pritset\Model;

use DateTimeImmutable;

final readonly class TemplateFileInfo
{
    public function __construct(
        public string $contentType,
        public DateTimeImmutable $lastModified,
        public string $objectName,
        public int $size,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $contentType = $data['contentType'] ?? null;
        $lastModified = $data['lastModified'] ?? null;
        $objectName = $data['objectName'] ?? null;
        $size = $data['size'] ?? null;

        if (!is_string($contentType) || !is_string($lastModified) || !is_string($objectName) || !is_int($size)) {
            throw new \UnexpectedValueException('Malformed template file metadata.');
        }

        return new self($contentType, new DateTimeImmutable($lastModified), $objectName, $size);
    }
}

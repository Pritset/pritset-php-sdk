<?php

declare(strict_types=1);

namespace Pritset\Value;

use Psr\Http\Message\StreamInterface;

final readonly class Upload
{
    /** @param resource|string|StreamInterface $contents */
    private function __construct(
        private mixed $contents,
        public string $filename,
        public ?string $contentType = null,
    ) {
        if ($filename === '') {
            throw new \InvalidArgumentException('Upload filename must not be empty.');
        }
    }

    public static function fromPath(string $path, ?string $filename = null, ?string $contentType = null): self
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new \InvalidArgumentException(sprintf('Upload path is not a readable file: %s', $path));
        }

        $stream = fopen($path, 'rb');
        if ($stream === false) {
            throw new \RuntimeException('Could not open upload file.');
        }

        return new self($stream, $filename ?? basename($path), $contentType);
    }

    public static function fromString(string $contents, string $filename, ?string $contentType = null): self
    {
        return new self($contents, $filename, $contentType);
    }

    /** @param resource|StreamInterface $stream */
    public static function fromStream(mixed $stream, string $filename, ?string $contentType = null): self
    {
        if (!is_resource($stream) && !$stream instanceof StreamInterface) {
            throw new \InvalidArgumentException('Upload stream must be a resource or PSR-7 stream.');
        }

        return new self($stream, $filename, $contentType);
    }

    /** @return array{name: string, contents: resource|string|StreamInterface, filename: string, headers?: array{Content-Type: string}} */
    public function multipart(string $fieldName): array
    {
        $part = [
            'name' => $fieldName,
            'contents' => $this->contents,
            'filename' => $this->filename,
        ];
        if ($this->contentType !== null) {
            $part['headers'] = ['Content-Type' => $this->contentType];
        }

        return $part;
    }
}

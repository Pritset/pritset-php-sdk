<?php

declare(strict_types=1);

namespace Pritset\Value;

use Psr\Http\Message\StreamInterface;

final readonly class BinaryResponse
{
    public function __construct(
        public StreamInterface $stream,
        public ?string $contentType,
        public ?int $contentLength,
        public ?string $trace,
    ) {
    }

    public function getContents(): string
    {
        return $this->stream->getContents();
    }

    public function saveToFile(string $path): void
    {
        $destination = fopen($path, 'wb');
        if ($destination === false) {
            throw new \RuntimeException(sprintf('Could not open destination for writing: %s', $path));
        }

        try {
            while (!$this->stream->eof()) {
                $chunk = $this->stream->read(8192);
                if ($chunk === '') {
                    break;
                }
                if (fwrite($destination, $chunk) === false) {
                    throw new \RuntimeException(sprintf('Could not write destination: %s', $path));
                }
            }
        } finally {
            fclose($destination);
        }
    }
}

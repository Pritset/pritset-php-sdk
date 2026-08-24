<?php

declare(strict_types=1);

namespace Pritset\Exception;

use RuntimeException;

final class PritsetApiException extends RuntimeException
{
    /** @param array<string, list<string>> $fieldErrors */
    public function __construct(
        string $message,
        public readonly int $statusCode,
        public readonly array $fieldErrors = [],
        public readonly ?string $traceId = null,
        public readonly ?string $retryAfter = null,
        public readonly ?string $responseBody = null,
    ) {
        parent::__construct($message, $statusCode);
    }
}

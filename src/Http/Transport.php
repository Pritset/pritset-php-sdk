<?php

declare(strict_types=1);

namespace Pritset\Http;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Pritset\Exception\PritsetApiException;
use Pritset\Exception\PritsetTransportException;
use Pritset\Value\BinaryResponse;
use Psr\Http\Message\ResponseInterface;

final class Transport
{
    private const MAX_ERROR_BODY_BYTES = 65536;

    private readonly string $baseUrl;

    public function __construct(
        private readonly string $accessToken,
        private readonly string $secret,
        string $baseUrl = 'https://api.pritset.com',
        private readonly float $timeout = 30.0,
        private readonly ClientInterface $httpClient = new Client(),
    ) {
        if ($accessToken === '' || $secret === '') {
            throw new \InvalidArgumentException('Access token and secret are required.');
        }
        if ($timeout <= 0) {
            throw new \InvalidArgumentException('Timeout must be greater than zero.');
        }

        $this->baseUrl = self::validateBaseUrl($baseUrl);
    }

    /** @return array{baseUrl: string, timeout: float, credentials: string} */
    public function __debugInfo(): array
    {
        return [
            'baseUrl' => $this->baseUrl,
            'timeout' => $this->timeout,
            'credentials' => '[REDACTED]',
        ];
    }

    /** @param array<string, mixed> $options
     *  @return array<string, mixed>
     */
    public function requestJson(string $method, string $path, array $options = []): array
    {
        $response = $this->request($method, $path, $options);
        $body = $response->getBody()->getContents();

        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new PritsetTransportException('Pritset returned an invalid JSON response.');
        }

        if (!is_array($decoded)) {
            throw new PritsetTransportException('Pritset returned an unexpected JSON response.');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /** @param array<string, mixed> $options */
    public function requestBoolean(string $method, string $path, array $options = []): bool
    {
        $response = $this->request($method, $path, $options);
        $body = $response->getBody()->getContents();

        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new PritsetTransportException('Pritset returned an invalid JSON response.');
        }

        if (!is_bool($decoded)) {
            throw new PritsetTransportException('Pritset returned an unexpected validation response.');
        }

        return $decoded;
    }

    /** @param array<string, mixed> $options */
    public function requestBinary(string $method, string $path, array $options = []): BinaryResponse
    {
        $headers = is_array($options['headers'] ?? null) ? $options['headers'] : [];
        $options['headers'] = ['Accept' => '*/*'] + $headers;
        $response = $this->request($method, $path, ['stream' => true] + $options);
        $length = $response->getHeaderLine('Content-Length');

        return new BinaryResponse(
            stream: $response->getBody(),
            contentType: self::nullableHeader($response, 'Content-Type'),
            contentLength: ctype_digit($length) ? (int) $length : null,
            trace: self::nullableHeader($response, 'X-Trace'),
        );
    }

    /** @param array<string, mixed> $options */
    public function requestEmpty(string $method, string $path, array $options = []): void
    {
        $this->request($method, $path, $options);
    }

    /** @param array<string, mixed> $options */
    private function request(string $method, string $path, array $options): ResponseInterface
    {
        $options['headers'] = array_merge(
            [
                'Accept' => 'application/json',
                'Authorization' => $this->accessToken,
                'X-Secret' => $this->secret,
                'User-Agent' => 'pritset-php/0.1.0',
            ],
            is_array($options['headers'] ?? null) ? $options['headers'] : [],
        );
        $options['timeout'] = $this->timeout;
        $options['connect_timeout'] = min($this->timeout, 10.0);
        $options['http_errors'] = false;
        $options['allow_redirects'] = false;

        try {
            $response = $this->httpClient->request($method, $this->url($path), $options);
        } catch (GuzzleException) {
            throw new PritsetTransportException('The request to Pritset failed before a response was received.');
        } catch (\Throwable) {
            throw new PritsetTransportException('The HTTP transport failed before a response was received.');
        }

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw $this->apiException($response);
        }

        return $response;
    }

    private function url(string $path): string
    {
        return $this->baseUrl . '/' . ltrim($path, '/');
    }

    private static function validateBaseUrl(string $baseUrl): string
    {
        $parts = parse_url($baseUrl);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new \InvalidArgumentException('Base URL must be an absolute HTTP(S) URL.');
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new \InvalidArgumentException('Base URL must not contain credentials, a query, or a fragment.');
        }

        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        $isLoopback = in_array($host, ['localhost', '127.0.0.1', '::1'], true);
        if ($scheme !== 'https' && !($scheme === 'http' && $isLoopback)) {
            throw new \InvalidArgumentException('Base URL must use HTTPS; HTTP is permitted only for loopback development.');
        }

        return rtrim($baseUrl, '/');
    }

    private function apiException(ResponseInterface $response): PritsetApiException
    {
        $body = self::readLimited($response);
        $message = sprintf('Pritset API request failed with status %d.', $response->getStatusCode());
        $fieldErrors = [];
        $traceId = self::nullableHeader($response, 'X-Trace');

        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $decoded = null;
        }

        if (is_array($decoded)) {
            if (isset($decoded['title']) && is_string($decoded['title'])) {
                $message = $decoded['title'];
            }
            if (isset($decoded['traceId']) && is_string($decoded['traceId'])) {
                $traceId = $decoded['traceId'];
            }

            $source = isset($decoded['errors']) && is_array($decoded['errors']) ? $decoded['errors'] : $decoded;
            foreach ($source as $field => $errors) {
                if (!is_string($field) || in_array($field, ['type', 'title', 'status', 'traceId'], true)) {
                    continue;
                }
                if (is_string($errors)) {
                    $fieldErrors[$field] = [$errors];
                } elseif (is_array($errors)) {
                    $messages = array_values(array_filter($errors, 'is_string'));
                    if ($messages !== []) {
                        $fieldErrors[$field] = $messages;
                    }
                }
            }
        } elseif (trim($body) !== '') {
            $message = trim($body);
        }

        return new PritsetApiException(
            message: $message,
            statusCode: $response->getStatusCode(),
            fieldErrors: $fieldErrors,
            traceId: $traceId,
            retryAfter: self::nullableHeader($response, 'Retry-After'),
            responseBody: $body,
        );
    }

    private static function readLimited(ResponseInterface $response): string
    {
        $stream = $response->getBody();
        $body = '';
        while (!$stream->eof() && strlen($body) < self::MAX_ERROR_BODY_BYTES) {
            $body .= $stream->read(min(8192, self::MAX_ERROR_BODY_BYTES - strlen($body)));
        }

        return $body;
    }

    private static function nullableHeader(ResponseInterface $response, string $name): ?string
    {
        $value = $response->getHeaderLine($name);
        return $value === '' ? null : $value;
    }
}

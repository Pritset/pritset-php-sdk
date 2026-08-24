<?php

declare(strict_types=1);

namespace Pritset\Resource;

use Pritset\Http\JsonData;
use Pritset\Http\Transport;
use Pritset\Model\WebhookJob;
use Pritset\Value\BinaryResponse;

final readonly class DocumentsClient
{
    public function __construct(private Transport $transport)
    {
    }

    /** @param array<mixed>|string|\JsonSerializable $data */
    public function generate(string $templateId, array|string|\JsonSerializable $data): BinaryResponse
    {
        return $this->transport->requestBinary(
            'POST',
            '/api/template/process/direct/' . self::id($templateId),
            ['multipart' => [self::textPart('data', JsonData::encode($data))]],
        );
    }

    /** @param array<mixed>|string|\JsonSerializable $data */
    public function generateWebhook(
        string $templateId,
        array|string|\JsonSerializable $data,
        string $webhookUrl,
    ): WebhookJob {
        self::validateWebhookUrl($webhookUrl);

        return WebhookJob::fromArray($this->transport->requestJson(
            'POST',
            '/api/template/process/webhook/' . self::id($templateId),
            ['multipart' => [
                self::textPart('data', JsonData::encode($data)),
                self::textPart('url', $webhookUrl),
            ]],
        ));
    }

    /** @return array{name: string, contents: string} */
    private static function textPart(string $name, string $contents): array
    {
        return ['name' => $name, 'contents' => $contents];
    }

    private static function id(string $id): string
    {
        if (trim($id) === '') {
            throw new \InvalidArgumentException('Template ID must not be empty.');
        }

        return rawurlencode($id);
    }

    private static function validateWebhookUrl(string $url): void
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new \InvalidArgumentException('Webhook URL must be an absolute HTTP(S) URL.');
        }
        if (!in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            throw new \InvalidArgumentException('Webhook URL must use HTTP or HTTPS.');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new \InvalidArgumentException('Webhook URL must not contain credentials.');
        }
    }
}

<?php

declare(strict_types=1);

namespace Pritset\Resource;

use Pritset\Http\JsonData;
use Pritset\Http\Transport;
use Pritset\Model\ListTemplatesOptions;
use Pritset\Model\Template;
use Pritset\Model\TemplateDetails;
use Pritset\Model\TemplatePage;
use Pritset\Value\BinaryResponse;
use Pritset\Value\Upload;

final readonly class TemplatesClient
{
    public function __construct(private Transport $transport)
    {
    }

    public function list(?ListTemplatesOptions $options = null): TemplatePage
    {
        $data = $this->transport->requestJson('GET', '/api/template', [
            'query' => ($options ?? new ListTemplatesOptions())->toQuery(),
        ]);

        return TemplatePage::fromArray($data);
    }

    public function get(string $id): TemplateDetails
    {
        return TemplateDetails::fromArray(
            $this->transport->requestJson('GET', '/api/template/' . self::id($id)),
        );
    }

    public function create(string $name, Upload $template, ?string $tags = null): Template
    {
        self::required($name, 'Template name');
        $multipart = [self::textPart('name', $name), $template->multipart('template')];
        if ($tags !== null) {
            $multipart[] = self::textPart('tags', $tags);
        }

        return Template::fromArray(
            $this->transport->requestJson('POST', '/api/template', ['multipart' => $multipart]),
        );
    }

    public function update(
        string $id,
        string $name,
        ?string $tags = null,
        ?Upload $template = null,
    ): Template {
        self::required($name, 'Template name');
        $multipart = [self::textPart('name', $name)];
        if ($tags !== null) {
            $multipart[] = self::textPart('tags', $tags);
        }
        if ($template !== null) {
            $multipart[] = $template->multipart('template');
        }

        return Template::fromArray(
            $this->transport->requestJson('PUT', '/api/template/' . self::id($id), ['multipart' => $multipart]),
        );
    }

    public function delete(string $id): void
    {
        $this->transport->requestEmpty('DELETE', '/api/template/' . self::id($id));
    }

    public function download(string $id): BinaryResponse
    {
        return $this->transport->requestBinary('GET', '/api/template/download/' . self::id($id));
    }

    /** @param array<mixed>|string|\JsonSerializable $data */
    public function validate(Upload $template, array|string|\JsonSerializable $data): bool
    {
        return $this->transport->requestBoolean('POST', '/api/template/process/validate', [
            'multipart' => [
                $template->multipart('file'),
                self::textPart('data', JsonData::encode($data)),
            ],
        ]);
    }

    /** @return array{name: string, contents: string} */
    private static function textPart(string $name, string $contents): array
    {
        return ['name' => $name, 'contents' => $contents];
    }

    private static function id(string $id): string
    {
        self::required($id, 'Template ID');
        return rawurlencode($id);
    }

    private static function required(string $value, string $label): void
    {
        if (trim($value) === '') {
            throw new \InvalidArgumentException($label . ' must not be empty.');
        }
    }
}

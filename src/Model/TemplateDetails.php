<?php

declare(strict_types=1);

namespace Pritset\Model;

final readonly class TemplateDetails
{
    public function __construct(
        public Template $template,
        public TemplateFileInfo $fileInfo,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $template = $data['template'] ?? null;
        $fileInfo = $data['fileInfo'] ?? null;
        if (!is_array($template) || !is_array($fileInfo)) {
            throw new \UnexpectedValueException('Malformed template details.');
        }

        /** @var array<string, mixed> $template */
        /** @var array<string, mixed> $fileInfo */
        return new self(Template::fromArray($template), TemplateFileInfo::fromArray($fileInfo));
    }
}

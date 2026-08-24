<?php

declare(strict_types=1);

namespace Pritset\Model;

final readonly class TemplatePage
{
    /** @param list<Template> $data */
    public function __construct(
        public array $data,
        public int $total,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $items = $data['data'] ?? null;
        $total = $data['total'] ?? null;
        if (!is_array($items) || !is_int($total)) {
            throw new \UnexpectedValueException('Malformed template page.');
        }

        $templates = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                throw new \UnexpectedValueException('Malformed template page item.');
            }
            /** @var array<string, mixed> $item */
            $templates[] = Template::fromArray($item);
        }

        return new self($templates, $total);
    }
}

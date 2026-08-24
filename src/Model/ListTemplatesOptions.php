<?php

declare(strict_types=1);

namespace Pritset\Model;

final readonly class ListTemplatesOptions
{
    public function __construct(
        public ?string $query = null,
        public int $page = 1,
        public int $pageSize = 100,
        public ?string $sortBy = null,
        public ?int $sortDirection = null,
    ) {
        if ($page < 1 || $pageSize < 1) {
            throw new \InvalidArgumentException('Page and page size must be positive integers.');
        }
        if ($sortDirection !== null && !in_array($sortDirection, [0, 1], true)) {
            throw new \InvalidArgumentException('Sort direction must be 0 (ascending) or 1 (descending).');
        }
    }

    /** @return array<string, int|string> */
    public function toQuery(): array
    {
        $query = ['p' => $this->page, 's' => $this->pageSize];
        if ($this->query !== null) {
            $query['q'] = $this->query;
        }
        if ($this->sortBy !== null) {
            $query['sorts[0].sortBy'] = $this->sortBy;
        }
        if ($this->sortDirection !== null) {
            $query['sorts[0].sortDirection'] = $this->sortDirection;
        }

        return $query;
    }
}

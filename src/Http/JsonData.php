<?php

declare(strict_types=1);

namespace Pritset\Http;

final class JsonData
{
    /** @param array<mixed>|string|\JsonSerializable $data */
    public static function encode(array|string|\JsonSerializable $data): string
    {
        if (is_string($data)) {
            json_decode($data, true, 512, JSON_THROW_ON_ERROR);
            return $data;
        }

        return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}

<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$lockPath = $root . '/contract/contract.lock.json';
$openApiPath = $root . '/contract/openapi.yaml';

$lockContents = file_get_contents($lockPath);
if ($lockContents === false) {
    fwrite(STDERR, "Could not read contract lock.\n");
    exit(1);
}

try {
    $lock = json_decode($lockContents, true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException $exception) {
    fwrite(STDERR, "Invalid contract lock JSON: {$exception->getMessage()}\n");
    exit(1);
}

if (!is_array($lock) || !is_string($lock['openapiSha256'] ?? null) || !is_string($lock['contractVersion'] ?? null)) {
    fwrite(STDERR, "Contract lock is missing required fields.\n");
    exit(1);
}

$actualHash = hash_file('sha256', $openApiPath);
if ($actualHash === false || !hash_equals($lock['openapiSha256'], $actualHash)) {
    fwrite(STDERR, "Vendored OpenAPI hash does not match contract lock.\n");
    exit(1);
}

$openApi = file_get_contents($openApiPath);
if ($openApi === false || preg_match('/^\s{2}version:\s+([^\s]+)$/m', $openApi, $match) !== 1) {
    fwrite(STDERR, "Could not read contract version from OpenAPI.\n");
    exit(1);
}
if (!hash_equals($lock['contractVersion'], $match[1])) {
    fwrite(STDERR, "OpenAPI version does not match contract lock.\n");
    exit(1);
}

$fixtures = [
    'documents/webhook-job.json',
    'errors/field-errors.json',
    'errors/plain-text.txt',
    'errors/validation-problem.json',
    'templates/get.json',
    'templates/list.json',
];
foreach ($fixtures as $fixture) {
    if (!is_file($root . '/contract/fixtures/' . $fixture)) {
        fwrite(STDERR, "Missing contract fixture: {$fixture}\n");
        exit(1);
    }
}

fwrite(STDOUT, "Contract 1.0.0 verified ({$actualHash}).\n");

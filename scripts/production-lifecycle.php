<?php

declare(strict_types=1);

use Pritset\Exception\PritsetApiException;
use Pritset\Exception\PritsetTransportException;
use Pritset\Model\ListTemplatesOptions;
use Pritset\PritsetClient;
use Pritset\Value\Upload;

require dirname(__DIR__) . '/vendor/autoload.php';

$baseUrl = requiredEnvironment('PRITSET_BASE_URL');
$accessToken = requiredEnvironment('PRITSET_ACCESS_TOKEN');
$secret = requiredEnvironment('PRITSET_SECRET');
$webhookUrl = requiredEnvironment('PRITSET_WEBHOOK_URL');
$webhookSettleSeconds = boundedIntegerEnvironment('PRITSET_WEBHOOK_SETTLE_SECONDS', 10, 0, 60);
$templatePath = trim((string) getenv('PRITSET_TEMPLATE_PATH'));
if ($templatePath === '') {
    $templatePath = dirname(__DIR__) . '/tests/fixtures/staging-template.docx';
}

$target = parse_url($baseUrl);
if ($target === false || !isset($target['scheme'], $target['host'])) {
    throw new RuntimeException('PRITSET_BASE_URL must be an absolute URL.');
}
if (isset($target['user']) || isset($target['pass'])) {
    throw new RuntimeException('PRITSET_BASE_URL cannot contain embedded credentials.');
}
$isLoopback = in_array(strtolower((string) $target['host']), ['localhost', '127.0.0.1', '::1'], true);
if (strtolower((string) $target['scheme']) !== 'https' && !$isLoopback) {
    throw new RuntimeException('PRITSET_BASE_URL must use HTTPS unless it targets an exact loopback host.');
}

$isProduction = strtolower((string) $target['host']) === 'api.pritset.com';
if ($isProduction && $baseUrl !== 'https://api.pritset.com') {
    throw new RuntimeException('Production tests must target exactly https://api.pritset.com.');
}
if ($isProduction && getenv('PRITSET_ALLOW_PRODUCTION') !== 'true') {
    throw new RuntimeException('Refusing api.pritset.com without PRITSET_ALLOW_PRODUCTION=true.');
}
if ($isProduction && getenv('PRITSET_PRODUCTION_TEST_USER_CONFIRMED') !== 'true') {
    throw new RuntimeException(
        'Refusing api.pritset.com until PRITSET_PRODUCTION_TEST_USER_CONFIRMED=true confirms dedicated test-user credentials.',
    );
}

$callback = parse_url($webhookUrl);
if ($callback === false || !isset($callback['scheme'], $callback['host'])) {
    throw new RuntimeException('PRITSET_WEBHOOK_URL must be an absolute HTTP(S) URL.');
}
if (!in_array(strtolower((string) $callback['scheme']), ['http', 'https'], true)) {
    throw new RuntimeException('PRITSET_WEBHOOK_URL must use HTTP or HTTPS.');
}
if (isset($callback['user']) || isset($callback['pass'])) {
    throw new RuntimeException('PRITSET_WEBHOOK_URL cannot contain embedded credentials.');
}
if ($isProduction && strtolower((string) $callback['scheme']) !== 'https') {
    throw new RuntimeException('PRITSET_WEBHOOK_URL must use HTTPS for a production test.');
}

$runPrefix = trim((string) getenv('PRITSET_TEST_RUN_PREFIX'));
if ($runPrefix === '') {
    $runPrefix = 'pritset-sdk-production-test';
}
if (preg_match('/^[a-z0-9][a-z0-9-]{0,47}$/', $runPrefix) !== 1) {
    throw new RuntimeException('PRITSET_TEST_RUN_PREFIX must contain 1-48 lowercase letters, digits, or dashes.');
}
validateDocxFixture($templatePath);

$client = new PritsetClient(
    accessToken: $accessToken,
    secret: $secret,
    baseUrl: $baseUrl,
    timeout: 120.0,
);

$runId = sprintf('%d-%s', (int) floor(microtime(true) * 1000), bin2hex(random_bytes(4)));
$originalName = sprintf('%s-%s', $runPrefix, $runId);
$updatedName = $originalName . '-updated';
$data = [
    'title' => 'Pritset SDK production test-user validation',
    'description' => sprintf('Lifecycle run %s', $runId),
    'advantages' => [
        ['title' => 'Contract', 'description' => 'All public template operations completed.'],
        ['title' => 'Cleanup', 'description' => 'The temporary template is deleted after validation.'],
    ],
];

$templateId = null;
$deleted = false;
$lifecycleFailed = false;
$creationAttempted = false;

try {
    step('Validating template');
    ensure(
        $client->templates()->validate(Upload::fromPath($templatePath), $data),
        'Template validation returned false.',
    );
    passed('validate template');

    $creationAttempted = true;
    step('Creating template');
    $created = $client->templates()->create(
        name: $originalName,
        template: Upload::fromPath($templatePath),
        tags: sprintf('%s,php', $runPrefix),
    );
    ensure($created->id !== '', 'Create response did not include a template ID.');
    $templateId = $created->id;
    ensure($created->name === $originalName, 'Create response returned an unexpected template name.');
    passed('create template');

    step('Filter templates');
    $page = $client->templates()->list(new ListTemplatesOptions(query: $originalName, page: 1, pageSize: 100));
    $listed = false;
    foreach ($page->data as $template) {
        if ($template->id === $templateId) {
            $listed = true;
            break;
        }
    }
    ensure($listed, 'Created template was not returned by list.');
    passed('list templates');

    step('Template details');
    $details = $client->templates()->get($templateId);
    ensure($details->template->id === $templateId, 'Template details returned an unexpected ID.');
    ensure($details->fileInfo->size > 0, 'Template details reported an empty file.');
    passed('get template details');

    step('Template update');
    $updated = $client->templates()->update(
        id: $templateId,
        name: $updatedName,
        tags: sprintf('%s,php,updated', $runPrefix),
    );
    ensure($updated->id === $templateId, 'Update response returned an unexpected ID.');
    ensure($updated->name === $updatedName, 'Update response returned an unexpected name.');
    passed('update template');

    step('Template download');
    $download = $client->templates()->download($templateId);
    try {
        $docx = $download->getContents();
    } finally {
        $download->stream->close();
    }
    ensure(strlen($docx) > 4, 'Downloaded template was empty.');
    ensure(str_starts_with($docx, 'PK'), 'Downloaded template was not a DOCX ZIP archive.');
    passed('download template');

    step('Generate direct PDF');
    $document = $client->documents()->generate($templateId, $data);
    try {
        $pdf = $document->getContents();
    } finally {
        $document->stream->close();
    }
    ensure(strlen($pdf) > 5, 'Generated PDF was empty.');
    ensure(str_starts_with($pdf, '%PDF-'), 'Generated document was not a PDF.');
    passed('generate direct PDF');

    step('Generate webhook PDF');
    $job = $client->documents()->generateWebhook($templateId, $data, $webhookUrl);
    ensure($job->id !== '', 'Webhook response did not include a job ID.');
    passed('submit webhook PDF generation');

    if ($webhookSettleSeconds > 0) {
        step(sprintf('Allow webhook job to start (%d seconds)', $webhookSettleSeconds));
        sleep($webhookSettleSeconds);
        passed('webhook job settle delay');
    }

    step('Template deletion');
    $client->templates()->delete($templateId);
    $deleted = true;
    passed('delete template');

    expectNotFound(static fn () => $client->templates()->get($templateId));
    passed('confirm deleted template returns 404');

    fwrite(STDOUT, "PHP SDK production test-user lifecycle passed.\n");
} catch (Throwable $error) {
    $lifecycleFailed = true;
    if ($error instanceof PritsetApiException && $error->statusCode === 401) {
        fwrite(
            STDERR,
            "Production authentication failed (401). Confirm that PRITSET_ACCESS_TOKEN is the raw Pritset token "
            . "without a Bearer prefix and PRITSET_SECRET is the matching secret for the same production test user.\n",
        );
    }
    throw $error;
} finally {
    if ($templateId !== null && !$deleted) {
        try {
            deleteTemporaryTemplate($client, $templateId, 'Cleanup');
        } catch (Throwable $cleanupError) {
            fwrite(STDERR, sprintf("Cleanup failed for temporary template %s.\n", $templateId));
            if (!$lifecycleFailed) {
                throw $cleanupError;
            }
        }
    }

    if ($creationAttempted && $templateId === null) {
        try {
            cleanupByExactNameWithRetries($client, $originalName, $updatedName);
        } catch (Throwable $cleanupError) {
            fwrite(STDERR, sprintf("Fallback cleanup failed for temporary template name %s.\n", $originalName));
            if (!$lifecycleFailed) {
                throw $cleanupError;
            }
        }
    }
}

function requiredEnvironment(string $name): string
{
    $value = trim((string) getenv($name));
    if ($value === '' || $value === 'replace-me') {
        throw new RuntimeException(sprintf('Set %s before running the production lifecycle.', $name));
    }

    return $value;
}

function boundedIntegerEnvironment(string $name, int $default, int $minimum, int $maximum): int
{
    $raw = trim((string) getenv($name));
    if ($raw === '') {
        return $default;
    }
    if (preg_match('/^\d+$/', $raw) !== 1) {
        throw new RuntimeException(sprintf('%s must be a whole number.', $name));
    }

    $value = (int) $raw;
    if ($value < $minimum || $value > $maximum) {
        throw new RuntimeException(sprintf('%s must be between %d and %d.', $name, $minimum, $maximum));
    }

    return $value;
}

function validateDocxFixture(string $path): void
{
    if (!is_file($path) || !is_readable($path)) {
        throw new RuntimeException(sprintf('Production test fixture is not readable: %s', $path));
    }
    if (strtolower((string) pathinfo($path, PATHINFO_EXTENSION)) !== 'docx') {
        throw new RuntimeException('PRITSET_TEMPLATE_PATH must point to a .docx file.');
    }

    $size = filesize($path);
    if ($size === false || $size === 0 || $size > 5_120_000) {
        throw new RuntimeException('Production test fixture must be between 1 byte and 5 MB.');
    }

    $stream = fopen($path, 'rb');
    if ($stream === false) {
        throw new RuntimeException(sprintf('Production test fixture could not be opened: %s', $path));
    }
    try {
        ensure(fread($stream, 2) === 'PK', 'Production test fixture is not a DOCX ZIP archive.');
        $tailLength = min($size, 65_557);
        ensure(fseek($stream, -$tailLength, SEEK_END) === 0, 'Production test fixture could not be inspected.');
        $tail = stream_get_contents($stream);
        ensure($tail !== false && str_contains($tail, "PK\x05\x06"), 'Production test fixture has no ZIP end marker.');
    } finally {
        fclose($stream);
    }
}

function deleteTemporaryTemplate(PritsetClient $client, string $templateId, string $label): void
{
    try {
        $client->templates()->delete($templateId);
        fwrite(STDOUT, sprintf("%s removed temporary template %s.\n", $label, $templateId));
    } catch (PritsetApiException $error) {
        if ($error->statusCode === 404) {
            fwrite(STDOUT, sprintf("%s confirmed temporary template %s was already absent.\n", $label, $templateId));
            return;
        }
        throw $error;
    }
}

function cleanupByExactNameWithRetries(PritsetClient $client, string $originalName, string $updatedName): void
{
    $delays = [1, 2, 4, 8];
    $lastError = null;
    foreach ([0, 1, 2, 3, 4] as $attempt) {
        try {
            $page = $client->templates()->list(new ListTemplatesOptions(query: $originalName, page: 1, pageSize: 100));
            $matched = false;
            foreach ($page->data as $template) {
                if ($template->name !== $originalName && $template->name !== $updatedName) {
                    continue;
                }
                $matched = true;
                deleteTemporaryTemplate($client, $template->id, 'Fallback cleanup');
            }
            if ($matched) {
                return;
            }
            $lastError = null;
        } catch (Throwable $error) {
            if (!isTransientCleanupFailure($error)) {
                throw $error;
            }
            $lastError = $error;
        }

        if ($attempt === 4) {
            if ($lastError !== null) {
                throw $lastError;
            }
            return;
        }

        sleep($delays[$attempt]);
    }
}

function isTransientCleanupFailure(Throwable $error): bool
{
    return $error instanceof PritsetTransportException
        || ($error instanceof PritsetApiException && ($error->statusCode === 429 || $error->statusCode >= 500));
}

function expectNotFound(callable $operation): void
{
    try {
        $operation();
    } catch (PritsetApiException $error) {
        if ($error->statusCode === 404) {
            return;
        }
        throw $error;
    }

    throw new RuntimeException('Deleted template remained accessible.');
}

function ensure(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function step(string $name): void
{
    fwrite(STDOUT, $name . "\n");
}

function passed(string $name): void
{
    fwrite(STDOUT, sprintf("PASS: %s\n", $name));
}

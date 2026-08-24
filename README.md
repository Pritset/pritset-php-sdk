# Pritset PHP SDK

Official PHP client for managing Pritset DOCX templates and generating PDFs.

> **Pre-release:** version `0.1.0` targets Pritset SDK contract `1.0.0`. The package has not yet been published to Packagist.

## Requirements

- PHP 8.3 or newer
- Composer 2
- A Pritset access token and secret

## Installation

After the first release is available on Packagist:

```bash
composer require pritset/pritset-php
```

For local development before publication, add this repository as a Composer path repository.

## Create a client

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use Pritset\PritsetClient;

$pritset = new PritsetClient(
    accessToken: $_ENV['PRITSET_ACCESS_TOKEN'],
    secret: $_ENV['PRITSET_SECRET'],
);
```

Pritset expects the access token directly in the `Authorization` header—do not add a `Bearer` prefix. Credentials are kept private and redacted from normal object debug output. Never commit them or include them in logs.

## Generate a PDF

`generate()` returns a streaming response, so large documents do not need to be buffered in memory.

```php
$pdf = $pritset->documents()->generate('template-id', [
    'invoice' => [
        'number' => 'INV-1042',
        'customer' => 'Ada Lovelace',
    ],
]);

$pdf->saveToFile(__DIR__ . '/invoice.pdf');

echo $pdf->contentType;   // application/pdf
echo $pdf->contentLength; // int|null
echo $pdf->trace;         // processing diagnostics, when supplied
```

The underlying PSR-7 stream is also available as `$pdf->stream`. `getContents()` and `saveToFile()` consume from the stream's current position.

## Templates

### List and inspect

```php
use Pritset\Model\ListTemplatesOptions;

$page = $pritset->templates()->list(new ListTemplatesOptions(
    query: 'invoice',
    page: 1,
    pageSize: 25,
    sortBy: 'name',
    sortDirection: 0, // 0 ascending, 1 descending
));

foreach ($page->data as $template) {
    echo $template->id . ': ' . $template->name . PHP_EOL;
}

$details = $pritset->templates()->get('template-id');
echo $details->fileInfo->objectName;
```

### Create and update

```php
use Pritset\Value\Upload;

$created = $pritset->templates()->create(
    name: 'Monthly invoice',
    template: Upload::fromPath(__DIR__ . '/invoice.docx'),
    tags: 'invoice,monthly',
);

$updated = $pritset->templates()->update(
    id: $created->id,
    name: 'Monthly invoice v2',
    tags: 'invoice,monthly',
    template: Upload::fromPath(__DIR__ . '/invoice-v2.docx'), // optional
);
```

Uploads can come from a path, a string, a PHP resource, or a PSR-7 stream:

```php
Upload::fromString($docxBytes, 'invoice.docx');
Upload::fromStream($stream, 'invoice.docx');
```

### Download and delete

```php
$download = $pritset->templates()->download('template-id');
$download->saveToFile(__DIR__ . '/template.docx');

$pritset->templates()->delete('template-id');
```

### Validate a template

```php
$valid = $pritset->templates()->validate(
    Upload::fromPath(__DIR__ . '/invoice.docx'),
    ['invoice' => ['number' => 'INV-1042']],
);
```

Template validation uses the same token-and-secret authentication as the other public template operations and is supported by contract `1.0.0`.

## Webhook generation

```php
$job = $pritset->documents()->generateWebhook(
    templateId: 'template-id',
    data: ['invoice' => ['number' => 'INV-1042']],
    webhookUrl: 'https://example.com/webhooks/pritset',
);

echo $job->id;
```

Webhook URLs must be absolute HTTP(S) URLs and cannot contain embedded credentials. The SDK sends the URL to Pritset; it does not call the webhook itself.

## Raw JSON

Document data may be an array, `JsonSerializable` object, or already encoded JSON string. Raw strings are validated before a request is sent.

```php
$pdf = $pritset->documents()->generate('template-id', '{"name":"Ada"}');
```

## Errors

```php
use Pritset\Exception\PritsetApiException;
use Pritset\Exception\PritsetTransportException;

try {
    $pritset->documents()->generate('template-id', $data);
} catch (PritsetApiException $error) {
    echo $error->statusCode;
    print_r($error->fieldErrors); // normalized field => list<string>
    echo $error->traceId;
    echo $error->retryAfter;
} catch (PritsetTransportException $error) {
    // No HTTP response was received, or the response was malformed.
}
```

Transport exceptions intentionally do not retain the original Guzzle exception because it can hold credential-bearing request headers.

## Configuration and custom HTTP clients

```php
use GuzzleHttp\Client;

$pritset = new PritsetClient(
    accessToken: $_ENV['PRITSET_ACCESS_TOKEN'],
    secret: $_ENV['PRITSET_SECRET'],
    baseUrl: 'https://api.pritset.com',
    timeout: 60.0,
    httpClient: new Client(),
);
```

The injected client implements Guzzle's `ClientInterface`, which is compatible with PSR-18 request semantics while supporting the multipart and streaming options used by this SDK. Redirects are always disabled to prevent credentials from being forwarded to another origin. HTTPS is required except for explicit `localhost`, `127.0.0.1`, or `::1` development endpoints.

## Compatibility

| SDK | PHP | Contract | HTTP |
| --- | --- | --- | --- |
| 0.1.x | 8.3–8.5 | 1.0.0 | Guzzle 7 / PSR-7 streams |

Pre-1.0 releases may contain API changes between minor versions. Pin an exact minor version in production and review the changelog before upgrading.

## Development

```bash
composer install
composer verify
composer validate --strict
composer audit
```

The contract hash check, PHPUnit suite, and PHPStan level 8 analysis all run in CI on PHP 8.3, 8.4, and 8.5.

## License

MIT © Pritset. See [LICENSE](LICENSE).

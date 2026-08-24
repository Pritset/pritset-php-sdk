<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Pritset\PritsetClient;
use Pritset\Value\Upload;

$client = new PritsetClient(
    accessToken: getenv('PRITSET_ACCESS_TOKEN') ?: throw new RuntimeException('Set PRITSET_ACCESS_TOKEN.'),
    secret: getenv('PRITSET_SECRET') ?: throw new RuntimeException('Set PRITSET_SECRET.'),
);

$path = $argv[1] ?? throw new InvalidArgumentException('Usage: php examples/manage-template.php <template.docx>');
$template = $client->templates()->create(
    name: pathinfo($path, PATHINFO_FILENAME),
    template: Upload::fromPath($path),
    tags: 'php-sdk-example',
);

echo "Created template {$template->id}\n";

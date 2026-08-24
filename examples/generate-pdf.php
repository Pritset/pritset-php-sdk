<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Pritset\PritsetClient;

$client = new PritsetClient(
    accessToken: getenv('PRITSET_ACCESS_TOKEN') ?: throw new RuntimeException('Set PRITSET_ACCESS_TOKEN.'),
    secret: getenv('PRITSET_SECRET') ?: throw new RuntimeException('Set PRITSET_SECRET.'),
);

$templateId = $argv[1] ?? throw new InvalidArgumentException('Usage: php examples/generate-pdf.php <template-id>');
$pdf = $client->documents()->generate($templateId, [
    'invoice' => [
        'number' => 'INV-1042',
        'customer' => 'Ada Lovelace',
    ],
]);
$pdf->saveToFile(__DIR__ . '/invoice.pdf');

echo "Saved examples/invoice.pdf\n";

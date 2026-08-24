<?php

declare(strict_types=1);

namespace Pritset\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pritset\Exception\PritsetApiException;
use Pritset\Exception\PritsetTransportException;
use Pritset\Model\ListTemplatesOptions;
use Pritset\PritsetClient;
use Pritset\Value\Upload;
use Psr\Http\Message\RequestInterface;

final class PritsetClientTest extends TestCase
{
    /** @var list<array{request: RequestInterface, options: array<string, mixed>}> */
    private array $history = [];

    protected function setUp(): void
    {
        $this->history = [];
    }

    public function testListsTemplatesUsingContractFixtureAndAuthenticationHeaders(): void
    {
        $client = $this->client([new Response(200, [], $this->fixture('templates/list.json'))]);
        $page = $client->templates()->list(new ListTemplatesOptions(
            query: 'invoice', page: 2, pageSize: 25, sortBy: 'name', sortDirection: 0,
        ));

        self::assertSame(1, $page->total);
        self::assertSame('Monthly invoice', $page->data[0]->name);
        $request = $this->history[0]['request'];
        self::assertSame('access-token', $request->getHeaderLine('Authorization'));
        self::assertSame('client-secret', $request->getHeaderLine('X-Secret'));
        $rawQuery = urldecode($request->getUri()->getQuery());
        parse_str($request->getUri()->getQuery(), $query);
        self::assertSame('invoice', $query['q']);
        self::assertSame('2', $query['p']);
        self::assertStringContainsString('sorts[0].sortBy=name', $rawQuery);
        self::assertStringContainsString('sorts[0].sortDirection=0', $rawQuery);
        self::assertFalse($this->history[0]['options']['allow_redirects']);
    }

    public function testGetsTypedTemplateDetailsFromContractFixture(): void
    {
        $client = $this->client([new Response(200, [], $this->fixture('templates/get.json'))]);
        $details = $client->templates()->get('a/b');

        self::assertSame('a1b2c3d4e5f6', $details->template->id);
        self::assertSame(24576, $details->fileInfo->size);
        self::assertSame('2026-07-15', $details->fileInfo->lastModified->format('Y-m-d'));
        self::assertSame('/v1/api/template/a%2Fb', $this->history[0]['request']->getUri()->getPath());
    }

    public function testCreatesAndUpdatesTemplatesAsMultipartRequests(): void
    {
        $created = json_encode(['id' => 'new', 'name' => 'Invoice', 'tags' => 'billing'], JSON_THROW_ON_ERROR);
        $updated = json_encode(['id' => 'new', 'name' => 'Invoice v2', 'tags' => null], JSON_THROW_ON_ERROR);
        $client = $this->client([new Response(200, [], $created), new Response(200, [], $updated)]);

        $template = $client->templates()->create(
            'Invoice',
            Upload::fromString('docx-bytes', 'invoice.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'),
            'billing',
        );
        self::assertSame('new', $template->id);
        $body = (string) $this->history[0]['request']->getBody();
        self::assertStringContainsString('name="name"', $body);
        self::assertStringContainsString('Invoice', $body);
        self::assertStringContainsString('filename="invoice.docx"', $body);
        self::assertStringContainsString('docx-bytes', $body);

        $client->templates()->update('new', 'Invoice v2');
        self::assertSame('PUT', $this->history[1]['request']->getMethod());
    }

    public function testValidatesTemplateAndJsonInput(): void
    {
        $client = $this->client([new Response(200, [], 'true')]);
        $valid = $client->templates()->validate(
            Upload::fromString('docx', 'template.docx'),
            ['invoice' => ['number' => 42]],
        );

        self::assertTrue($valid);
        self::assertStringContainsString('{"invoice":{"number":42}}', (string) $this->history[0]['request']->getBody());
    }

    public function testRejectsInvalidRawJsonBeforeSending(): void
    {
        $client = $this->client([]);

        $this->expectException(\JsonException::class);
        $client->documents()->generate('template', '{invalid');
    }

    public function testStreamsGeneratedPdfAndDownloadMetadata(): void
    {
        $client = $this->client([
            new Response(200, ['Content-Type' => 'application/pdf', 'Content-Length' => '9', 'X-Trace' => 'timing'], '%PDF-data'),
            new Response(200, ['Content-Type' => 'application/msword'], 'doc-data'),
        ]);

        $pdf = $client->documents()->generate('template', ['name' => 'Ada']);
        self::assertSame('application/pdf', $pdf->contentType);
        self::assertSame(9, $pdf->contentLength);
        self::assertSame('timing', $pdf->trace);
        self::assertSame('%PDF-data', $pdf->getContents());
        self::assertSame('*/*', $this->history[0]['request']->getHeaderLine('Accept'));

        $download = $client->templates()->download('template');
        self::assertSame('doc-data', $download->getContents());
    }

    public function testAcceptsPathResourceAndPsrStreamUploads(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'pritset-sdk-');
        self::assertNotFalse($path);
        file_put_contents($path, 'path-bytes');

        try {
            $pathPart = Upload::fromPath($path, 'path.docx')->multipart('template');
            self::assertIsResource($pathPart['contents']);
            self::assertSame('path.docx', $pathPart['filename']);

            $resource = fopen('php://temp', 'w+b');
            self::assertIsResource($resource);
            fwrite($resource, 'resource-bytes');
            rewind($resource);
            $resourcePart = Upload::fromStream($resource, 'resource.docx')->multipart('template');
            self::assertSame($resource, $resourcePart['contents']);

            $psrStream = \GuzzleHttp\Psr7\Utils::streamFor('psr-bytes');
            $psrPart = Upload::fromStream($psrStream, 'psr.docx')->multipart('template');
            self::assertSame($psrStream, $psrPart['contents']);
        } finally {
            unlink($path);
        }
    }

    public function testStartsWebhookJobFromContractFixture(): void
    {
        $client = $this->client([new Response(200, [], $this->fixture('documents/webhook-job.json'))]);
        $job = $client->documents()->generateWebhook('template', '{"name":"Ada"}', 'https://example.com/hooks/pritset');

        self::assertSame('57056f7462084dde8902421e9287ea2d', $job->id);
        $body = (string) $this->history[0]['request']->getBody();
        self::assertStringContainsString('https://example.com/hooks/pritset', $body);
        self::assertStringContainsString('{"name":"Ada"}', $body);
    }

    public function testRejectsCredentialBearingWebhookUrl(): void
    {
        $client = $this->client([]);

        $this->expectException(\InvalidArgumentException::class);
        $client->documents()->generateWebhook('template', [], 'https://user:pass@example.com/hook');
    }

    public function testNormalizesValidationProblemFromContractFixture(): void
    {
        $client = $this->client([new Response(400, ['Retry-After' => '10'], $this->fixture('errors/validation-problem.json'))]);

        try {
            $client->templates()->list();
            self::fail('Expected API exception.');
        } catch (PritsetApiException $exception) {
            self::assertSame(400, $exception->statusCode);
            self::assertSame('One or more validation errors occurred.', $exception->getMessage());
            self::assertSame(['The Name field is required.'], $exception->fieldErrors['Name']);
            self::assertSame('00-example-trace-id-00', $exception->traceId);
            self::assertSame('10', $exception->retryAfter);
        }
    }

    public function testNormalizesFieldMapAndPlainTextErrors(): void
    {
        $client = $this->client([
            new Response(400, [], $this->fixture('errors/field-errors.json')),
            new Response(404, [], $this->fixture('errors/plain-text.txt')),
        ]);

        try {
            $client->templates()->list();
        } catch (PritsetApiException $exception) {
            self::assertSame(['Data is required'], $exception->fieldErrors['Data']);
        }

        try {
            $client->templates()->get('missing');
        } catch (PritsetApiException $exception) {
            self::assertSame(trim($this->fixture('errors/plain-text.txt')), $exception->getMessage());
        }
    }

    public function testSanitizesTransportFailuresAndDropsPreviousException(): void
    {
        $request = new Request('GET', 'https://api.pritset.com', ['Authorization' => 'leaked-token']);
        $client = $this->client([new ConnectException('leaked-token client-secret', $request)]);

        try {
            $client->templates()->list();
            self::fail('Expected transport exception.');
        } catch (PritsetTransportException $exception) {
            self::assertStringNotContainsString('leaked-token', $exception->getMessage());
            self::assertStringNotContainsString('client-secret', $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }
    }

    public function testClientDebugOutputDoesNotExposeCredentials(): void
    {
        $client = $this->client([]);
        $dump = print_r($client, true);

        self::assertStringNotContainsString('access-token', $dump);
        self::assertStringNotContainsString('client-secret', $dump);
    }

    #[DataProvider('invalidBaseUrlProvider')]
    public function testRejectsUnsafeBaseUrls(string $baseUrl): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PritsetClient('token', 'secret', $baseUrl, httpClient: new Client(['handler' => new MockHandler()]));
    }

    /** @return iterable<string, array{string}> */
    public static function invalidBaseUrlProvider(): iterable
    {
        yield 'remote HTTP' => ['http://api.pritset.com'];
        yield 'credentials' => ['https://user:pass@api.pritset.com'];
        yield 'query' => ['https://api.pritset.com?token=unsafe'];
        yield 'relative' => ['/api'];
    }

    public function testPermitsHttpOnlyForLoopbackDevelopment(): void
    {
        $client = $this->client([new Response(200, [], $this->fixture('templates/list.json'))], 'http://127.0.0.1:8080');
        self::assertSame(1, $client->templates()->list()->total);
    }

    /** @param list<Response|\Throwable> $responses */
    private function client(array $responses, string $baseUrl = 'https://example.com/v1'): PritsetClient
    {
        $mock = new MockHandler($responses);
        /** @param array<string, mixed> $options */
        $handler = function (RequestInterface $request, array $options) use ($mock): PromiseInterface {
            $this->history[] = ['request' => $request, 'options' => $options];
            return $mock($request, $options);
        };

        return new PritsetClient(
            accessToken: 'access-token',
            secret: 'client-secret',
            baseUrl: $baseUrl,
            httpClient: new Client(['handler' => $handler]),
        );
    }

    private function fixture(string $path): string
    {
        $contents = file_get_contents(dirname(__DIR__) . '/contract/fixtures/' . $path);
        self::assertNotFalse($contents);
        return $contents;
    }
}

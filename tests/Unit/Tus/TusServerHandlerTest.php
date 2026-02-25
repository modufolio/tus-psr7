<?php

declare(strict_types=1);

namespace Modufolio\Tus\Tests\Unit\Tus;

use Modufolio\Tus\TusServer;

class TusServerHandlerTest extends TusTestCase
{
    /**
     * POST: Create new upload with required metadata.
     */
    public function testHandlePostCreatesUpload(): void
    {
        $metadata = $this->makeMetadataHeader(['filename' => 'test.txt']);
        $request = $this->makeRequest('POST', '/tus/upload', [
            'Upload-Length' => '1000',
            'Upload-Metadata' => $metadata,
        ]);

        $response = $this->server->handleRequest($request);

        $this->assertSame(201, $response->getStatusCode());
        $this->assertStringContainsString('/tus/', $response->getHeaderLine('Location'));
    }

    public function testHandlePostRejectsUploadExceedingMaxSize(): void
    {
        $server = new TusServer($this->tmpDir, 500);
        $metadata = $this->makeMetadataHeader(['filename' => 'huge.bin']);
        $request = $this->makeRequest('POST', '/tus/upload', [
            'Upload-Length' => '1000',
            'Upload-Metadata' => $metadata,
        ]);

        $response = $server->handleRequest($request);

        $this->assertSame(413, $response->getStatusCode());
    }

    public function testHandlePostRejectsMissingFilenameMetadata(): void
    {
        $metadata = $this->makeMetadataHeader(['description' => 'no filename here']);
        $request = $this->makeRequest('POST', '/tus/upload', [
            'Upload-Length' => '1000',
            'Upload-Metadata' => $metadata,
        ]);

        $response = $this->server->handleRequest($request);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testHandlePostRejectsInvalidFilenamePathTraversal(): void
    {
        $metadata = $this->makeMetadataHeader(['filename' => '../../../etc/passwd']);
        $request = $this->makeRequest('POST', '/tus/upload', [
            'Upload-Length' => '100',
            'Upload-Metadata' => $metadata,
        ]);

        $response = $this->server->handleRequest($request);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testHandlePostWithDeferredLength(): void
    {
        $metadata = $this->makeMetadataHeader(['filename' => 'deferred.txt']);
        $request = $this->makeRequest('POST', '/tus/upload', [
            'Upload-Defer-Length' => '1',
            'Upload-Metadata' => $metadata,
        ]);

        $response = $this->server->handleRequest($request);

        $this->assertSame(201, $response->getStatusCode());
    }

    public function testHandlePostWithBody(): void
    {
        $body = $this->generateContent(100, 'X');
        $metadata = $this->makeMetadataHeader(['filename' => 'with-body.txt']);
        $request = $this->makeRequest('POST', '/tus/upload', [
            'Upload-Length' => '100',
            'Upload-Metadata' => $metadata,
        ], $body);

        $response = $this->server->handleRequest($request);

        $this->assertSame(201, $response->getStatusCode());
    }

    /**
     * HEAD: Retrieve upload status.
     */
    public function testHandleHeadReturns404ForNonexistent(): void
    {
        $request = $this->makeRequest('HEAD', '/tus/nonexistent.txt');

        $response = $this->server->handleRequest($request);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testHandleHeadReturnsOffsetAfterPost(): void
    {
        $metadata = $this->makeMetadataHeader(['filename' => 'status.txt']);
        $postRequest = $this->makeRequest('POST', '/tus/status.txt', [
            'Upload-Length' => '1000',
            'Upload-Metadata' => $metadata,
        ]);
        $this->server->handleRequest($postRequest);

        $headRequest = $this->makeRequest('HEAD', '/tus/status.txt');
        $response = $this->server->handleRequest($headRequest);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('0', $response->getHeaderLine('Upload-Offset'));
        $this->assertSame('1000', $response->getHeaderLine('Upload-Length'));
    }

    /**
     * PATCH: Append data to upload.
     */
    public function testHandlePatchReturns404ForNonexistent(): void
    {
        $request = $this->makeRequest('PATCH', '/tus/missing.txt', [
            'Upload-Offset' => '0',
        ], 'data');

        $response = $this->server->handleRequest($request);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testHandlePatchAppendsData(): void
    {
        $metadata = $this->makeMetadataHeader(['filename' => 'append.txt']);
        $postRequest = $this->makeRequest('POST', '/tus/append.txt', [
            'Upload-Length' => '100',
            'Upload-Metadata' => $metadata,
        ]);
        $this->server->handleRequest($postRequest);

        $patchRequest = $this->makeRequest('PATCH', '/tus/append.txt', [
            'Upload-Offset' => '0',
        ], $this->generateContent(50, 'A'));
        $response = $this->server->handleRequest($patchRequest);

        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame('50', $response->getHeaderLine('Upload-Offset'));
    }

    public function testHandlePatchRejectsWrongOffset(): void
    {
        $metadata = $this->makeMetadataHeader(['filename' => 'offset.txt']);
        $postRequest = $this->makeRequest('POST', '/tus/offset.txt', [
            'Upload-Length' => '100',
            'Upload-Metadata' => $metadata,
        ]);
        $this->server->handleRequest($postRequest);

        $patchRequest = $this->makeRequest('PATCH', '/tus/offset.txt', [
            'Upload-Offset' => '50',
        ], 'data');
        $response = $this->server->handleRequest($patchRequest);

        $this->assertSame(409, $response->getStatusCode());
    }

    public function testHandlePatchRejectsUploadExceedingDeclaredLength(): void
    {
        $metadata = $this->makeMetadataHeader(['filename' => 'exceed.txt']);
        $postRequest = $this->makeRequest('POST', '/tus/exceed.txt', [
            'Upload-Length' => '50',
            'Upload-Metadata' => $metadata,
        ]);
        $this->server->handleRequest($postRequest);

        $patchRequest = $this->makeRequest('PATCH', '/tus/exceed.txt', [
            'Upload-Offset' => '0',
        ], $this->generateContent(100, 'X'));
        $response = $this->server->handleRequest($patchRequest);

        $this->assertSame(413, $response->getStatusCode());
    }

    /**
     * DELETE: Cancel upload.
     */
    public function testHandleDeleteReturns404ForNonexistent(): void
    {
        $request = $this->makeRequest('DELETE', '/tus/gone.txt');

        $response = $this->server->handleRequest($request);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testHandleDeleteRemovesUpload(): void
    {
        $metadata = $this->makeMetadataHeader(['filename' => 'delete-me.txt']);
        $postRequest = $this->makeRequest('POST', '/tus/delete-me.txt', [
            'Upload-Length' => '100',
            'Upload-Metadata' => $metadata,
        ]);
        $this->server->handleRequest($postRequest);

        $deleteRequest = $this->makeRequest('DELETE', '/tus/delete-me.txt');
        $response = $this->server->handleRequest($deleteRequest);

        $this->assertSame(204, $response->getStatusCode());

        $headRequest = $this->makeRequest('HEAD', '/tus/delete-me.txt');
        $checkResponse = $this->server->handleRequest($headRequest);
        $this->assertSame(404, $checkResponse->getStatusCode());
    }

    /**
     * Resume scenario: POST existing file returns existing location.
     */
    public function testHandlePostResumesExistingUpload(): void
    {
        $filename = 'resume.txt';
        $metadata = $this->makeMetadataHeader(['filename' => $filename]);
        $initialRequest = $this->makeRequest('POST', '/tus/' . $filename, [
            'Upload-Length' => '1000',
            'Upload-Metadata' => $metadata,
        ]);
        $this->server->handleRequest($initialRequest);

        $resumeRequest = $this->makeRequest('POST', '/tus/' . $filename, [
            'Upload-Length' => '1000',
            'Upload-Metadata' => $metadata,
        ]);
        $response = $this->server->handleRequest($resumeRequest);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString($filename, $response->getHeaderLine('Location'));
    }

    /**
     * Multipart: Append in multiple chunks.
     */
    public function testHandlePatchMultipleChunks(): void
    {
        $filename = 'chunks.txt';
        $metadata = $this->makeMetadataHeader(['filename' => $filename]);
        $postRequest = $this->makeRequest('POST', '/tus/' . $filename, [
            'Upload-Length' => '300',
            'Upload-Metadata' => $metadata,
        ]);
        $this->server->handleRequest($postRequest);

        for ($i = 0; $i < 3; ++$i) {
            $patchRequest = $this->makeRequest('PATCH', '/tus/' . $filename, [
                'Upload-Offset' => (string)($i * 100),
            ], $this->generateContent(100, (string)$i));
            $response = $this->server->handleRequest($patchRequest);

            $this->assertSame(204, $response->getStatusCode());
            $this->assertSame((string)(($i + 1) * 100), $response->getHeaderLine('Upload-Offset'));
        }
    }

    /**
     * Metadata: Parse and return in HEAD.
     */
    public function testHandleHeadReturnsMetadata(): void
    {
        $filename = 'metadata.bin';
        $originalMeta = ['filename' => $filename, 'filetype' => 'application/octet-stream'];
        $metadata = $this->makeMetadataHeader($originalMeta);
        $postRequest = $this->makeRequest('POST', '/tus/' . $filename, [
            'Upload-Length' => '100',
            'Upload-Metadata' => $metadata,
        ]);
        $this->server->handleRequest($postRequest);

        $headRequest = $this->makeRequest('HEAD', '/tus/' . $filename);
        $response = $this->server->handleRequest($headRequest);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNotEmpty($response->getHeaderLine('Upload-Metadata'));
    }

    /**
     * Invalid requests.
     */
    public function testHandlePostWithoutMetadata(): void
    {
        $request = $this->makeRequest('POST', '/tus/nometa', [
            'Upload-Length' => '100',
        ]);

        $response = $this->server->handleRequest($request);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testHandlePatchWithEmptyBody(): void
    {
        $filename = 'nobody.txt';
        $metadata = $this->makeMetadataHeader(['filename' => $filename]);
        $postRequest = $this->makeRequest('POST', '/tus/' . $filename, [
            'Upload-Length' => '100',
            'Upload-Metadata' => $metadata,
        ]);
        $this->server->handleRequest($postRequest);

        $patchRequest = $this->makeRequest('PATCH', '/tus/' . $filename, [
            'Upload-Offset' => '0',
        ], '');
        $response = $this->server->handleRequest($patchRequest);

        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame('0', $response->getHeaderLine('Upload-Offset'));
    }

    /**
     * Filename sanitization.
     */
    public function testHandlePostSanitizeFilename(): void
    {
        $metadata = $this->makeMetadataHeader(['filename' => 'test@#$%.txt']);
        $request = $this->makeRequest('POST', '/tus/sanitize', [
            'Upload-Length' => '100',
            'Upload-Metadata' => $metadata,
        ]);

        $response = $this->server->handleRequest($request);

        $this->assertSame(201, $response->getStatusCode());
    }
}

<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthenticateMediaApiToken;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class NbxResumableUploadTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('nbx.public_url', 'https://nbx.test');
        config()->set('nbx.upload_chunk_size_mb', 1);
        config()->set('nbx.upload_session_dir', 'framework/testing/nbx-upload-sessions');
        File::deleteDirectory(storage_path('app/framework/testing/nbx-upload-sessions'));
        $this->withoutMiddleware(AuthenticateMediaApiToken::class);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('app/framework/testing/nbx-upload-sessions'));
        parent::tearDown();
    }

    public function test_session_uses_small_resumable_chunks_and_never_exposes_token_in_url(): void
    {
        $response = $this->postJson('/api/v1/nbx/uploads/init', [
            'filename' => 'creator-film.mkv',
            'size_bytes' => 5,
            'mime_type' => 'video/x-matroska',
            'asset_type' => 'movie',
        ])->assertCreated();

        $data = $response->json('data');
        $this->assertSame('chunked', $data['upload_mode']);
        $this->assertSame(1024 * 1024, $data['chunk_size_bytes']);
        $this->assertSame(1, $data['total_chunks']);
        $this->assertArrayHasKey('X-NBX-Upload-Token', $data['headers']);
        $this->assertStringNotContainsString($data['headers']['X-NBX-Upload-Token'], $data['chunk_url_template']);
    }

    public function test_verified_chunk_can_be_resumed_and_cancel_removes_temporary_bytes(): void
    {
        $initialized = $this->postJson('/api/v1/nbx/uploads/init', [
            'filename' => 'creator-film.mp4',
            'size_bytes' => 5,
            'mime_type' => 'video/mp4',
        ])->assertCreated()->json('data');
        $session = $initialized['session_id'];
        $token = $initialized['headers']['X-NBX-Upload-Token'];
        $bytes = 'video';

        $this->call('PUT', "/api/v1/nbx/uploads/{$session}/chunks/0", [], [], [], [
            'HTTP_X_NBX_UPLOAD_TOKEN' => $token,
            'HTTP_X_CHUNK_SHA256' => hash('sha256', $bytes),
            'HTTP_CONTENT_RANGE' => 'bytes 0-4/5',
            'CONTENT_TYPE' => 'application/octet-stream',
            'CONTENT_LENGTH' => '5',
        ], $bytes)->assertStatus(202);

        $this->withHeader('X-NBX-Upload-Token', $token)
            ->getJson("/api/v1/nbx/uploads/{$session}")
            ->assertOk()
            ->assertJsonPath('data.received_chunks.0', 0)
            ->assertJsonPath('data.uploaded_bytes', 5);

        $path = storage_path("app/framework/testing/nbx-upload-sessions/{$session}/chunk-0.part");
        $this->assertFileExists($path);
        $this->withHeader('X-NBX-Upload-Token', $token)
            ->postJson("/api/v1/nbx/uploads/{$session}/cancel")
            ->assertOk();
        $this->assertFileDoesNotExist($path);
    }

    public function test_bad_chunk_checksum_is_rejected_without_persisting_part(): void
    {
        $initialized = $this->postJson('/api/v1/nbx/uploads/init', [
            'filename' => 'creator-film.mov',
            'size_bytes' => 5,
            'mime_type' => 'video/quicktime',
        ])->assertCreated()->json('data');

        $this->call('PUT', "/api/v1/nbx/uploads/{$initialized['session_id']}/chunks/0", [], [], [], [
            'HTTP_X_NBX_UPLOAD_TOKEN' => $initialized['headers']['X-NBX-Upload-Token'],
            'HTTP_X_CHUNK_SHA256' => str_repeat('0', 64),
            'CONTENT_TYPE' => 'application/octet-stream',
            'CONTENT_LENGTH' => '5',
        ], 'video')->assertUnprocessable();

        $this->assertFileDoesNotExist(storage_path(
            "app/framework/testing/nbx-upload-sessions/{$initialized['session_id']}/chunk-0.part"
        ));
    }
}

<?php

namespace Tests\Feature;

use App\Services\SalesAgendaEvidenceImageService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SalesAgendaEvidenceImageTest extends TestCase
{
    public function test_it_normalizes_actual_images_to_private_webp_without_upscaling(): void
    {
        Storage::fake('agenda_evidence');
        $result = app(SalesAgendaEvidenceImageService::class)->store(UploadedFile::fake()->image('photo.png', 2000, 1000));

        Storage::disk('agenda_evidence')->assertExists($result['storage_path']);
        $this->assertMatchesRegularExpression('#^sales-agenda-evidence/[0-9a-f-]{36}\.webp$#', $result['storage_path']);
        $this->assertSame('image/webp', $result['mime_type']);
        $this->assertSame([1600, 800], [$result['width'], $result['height']]);
        $this->assertSame(64, strlen($result['sha256']));
    }

    public function test_jpeg_png_and_webp_are_accepted_and_small_images_are_not_upscaled(): void
    {
        Storage::fake('agenda_evidence');
        foreach (['jpg', 'png', 'webp'] as $extension) {
            $result = app(SalesAgendaEvidenceImageService::class)->store(UploadedFile::fake()->image("photo.$extension", 320, 180));
            $this->assertSame([320, 180], [$result['width'], $result['height']]);
            $this->assertSame('image/webp', $result['mime_type']);
        }
    }

    public function test_spoofed_image_bytes_are_rejected(): void
    {
        Storage::fake('agenda_evidence');
        $this->expectException(ValidationException::class);
        app(SalesAgendaEvidenceImageService::class)->store(UploadedFile::fake()->createWithContent('spoof.jpg', 'not an image'));
    }

    public function test_it_rejects_fake_and_oversized_uploads(): void
    {
        Storage::fake('agenda_evidence');

        foreach ([UploadedFile::fake()->create('fake.jpg', 10, 'image/jpeg'), UploadedFile::fake()->create('large.png', 10241, 'image/png')] as $file) {
            try {
                app(SalesAgendaEvidenceImageService::class)->store($file);
                $this->fail('Upload seharusnya ditolak.');
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }
    }
}

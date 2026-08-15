<?php

namespace App\Services;

use App\Support\SalesAgendaEvidenceRules;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SalesAgendaEvidenceImageService
{
    public function store(UploadedFile $file): array
    {
        if (! function_exists('imagewebp') || ! function_exists('imagecreatetruecolor')) {
            throw ValidationException::withMessages(['photo' => 'Pemrosesan foto tidak tersedia.']);
        }
        if (! $file->isValid() || $file->getSize() > SalesAgendaEvidenceRules::MAX_BYTES) {
            throw ValidationException::withMessages(['photo' => 'Foto maksimal 10 MB.']);
        }

        $info = @getimagesize($file->getRealPath());
        $mime = $info['mime'] ?? null;
        $width = (int) ($info[0] ?? 0);
        $height = (int) ($info[1] ?? 0);
        if ($width < 1 || $height < 1 || $width * $height > SalesAgendaEvidenceRules::MAX_PIXELS) {
            throw ValidationException::withMessages(['photo' => 'Dimensi foto terlalu besar.']);
        }
        if (! in_array($mime, SalesAgendaEvidenceRules::MIME_TYPES, true)) {
            throw ValidationException::withMessages(['photo' => 'Foto harus berupa JPEG, PNG, atau WebP yang valid.']);
        }
        $source = match ($mime) {
            'image/jpeg' => function_exists('imagecreatefromjpeg') ? @imagecreatefromjpeg($file->getRealPath()) : false,
            'image/png' => function_exists('imagecreatefrompng') ? @imagecreatefrompng($file->getRealPath()) : false,
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($file->getRealPath()) : false,
        };
        if (! $source) {
            throw ValidationException::withMessages(['photo' => 'Foto gagal dibaca.']);
        }

        $target = null;
        try {
            if ($mime === 'image/jpeg') {
                $source = $this->orient($source, $this->jpegOrientation($file->getRealPath()));
            }
            $width = imagesx($source);
            $height = imagesy($source);
            $scale = min(1, SalesAgendaEvidenceRules::MAX_DIMENSION / max($width, $height));
            $targetWidth = max(1, (int) round($width * $scale));
            $targetHeight = max(1, (int) round($height * $scale));
            $target = imagecreatetruecolor($targetWidth, $targetHeight);
            imagealphablending($target, false);
            imagesavealpha($target, true);
            $transparent = imagecolorallocatealpha($target, 0, 0, 0, 127);
            imagefilledrectangle($target, 0, 0, $targetWidth, $targetHeight, $transparent);
            if (! imagecopyresampled($target, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height)) {
                throw new \RuntimeException('Resize gagal.');
            }
            ob_start();
            $encoded = imagewebp($target, null, SalesAgendaEvidenceRules::WEBP_QUALITY);
            $bytes = ob_get_clean();
            if (! $encoded || ! is_string($bytes) || $bytes === '') {
                throw new \RuntimeException('WebP gagal dibuat.');
            }
        } catch (\Throwable) {
            if (ob_get_level() > 0) {
                ob_end_clean();
            }
            throw ValidationException::withMessages(['photo' => 'Foto gagal diproses.']);
        } finally {
            if ($target) {
                imagedestroy($target);
            }
            imagedestroy($source);
        }

        $path = 'sales-agenda-evidence/'.Str::uuid().'.webp';
        if (! Storage::disk('agenda_evidence')->put($path, $bytes)) {
            throw ValidationException::withMessages(['photo' => 'Foto gagal disimpan.']);
        }

        $checksum = hash('sha256', $bytes);

        return ['storage_path' => $path, 'file_path' => $path, 'mime_type' => 'image/webp', 'width' => $targetWidth, 'height' => $targetHeight,
            'size_bytes' => strlen($bytes), 'sha256' => $checksum, 'checksum' => $checksum, 'archive_status' => 'local_only', 'original_name' => $file->getClientOriginalName()];
    }

    private function jpegOrientation(string $path): int
    {
        $data = @file_get_contents($path, false, null, 0, 262144);
        if (! is_string($data) || strlen($data) < 4 || substr($data, 0, 2) !== "\xFF\xD8") {
            return 1;
        }
        $offset = 2;
        while ($offset + 4 <= strlen($data)) {
            if (ord($data[$offset]) !== 0xFF) {
                return 1;
            }
            $marker = ord($data[$offset + 1]);
            $length = unpack('n', substr($data, $offset + 2, 2))[1];
            if ($length < 2 || $offset + 2 + $length > strlen($data)) {
                return 1;
            }
            if ($marker === 0xE1 && substr($data, $offset + 4, 6) === "Exif\0\0") {
                return $this->tiffOrientation(substr($data, $offset + 10, $length - 8));
            }
            if ($marker === 0xDA || $marker === 0xD9) {
                break;
            }
            $offset += 2 + $length;
        }

        return 1;
    }

    private function tiffOrientation(string $tiff): int
    {
        if (strlen($tiff) < 8 || ! in_array(substr($tiff, 0, 2), ['II', 'MM'], true)) {
            return 1;
        }
        $little = substr($tiff, 0, 2) === 'II';
        $u16 = fn (int $at): ?int => $at + 2 <= strlen($tiff) ? unpack($little ? 'v' : 'n', substr($tiff, $at, 2))[1] : null;
        $u32 = fn (int $at): ?int => $at + 4 <= strlen($tiff) ? unpack($little ? 'V' : 'N', substr($tiff, $at, 4))[1] : null;
        if ($u16(2) !== 42 || ($ifd = $u32(4)) === null || $ifd + 2 > strlen($tiff) || ($count = $u16($ifd)) === null || $count > 4096) {
            return 1;
        }
        for ($i = 0; $i < $count; $i++) {
            $entry = $ifd + 2 + ($i * 12);
            if ($entry + 12 > strlen($tiff)) {
                return 1;
            }
            if ($u16($entry) === 0x0112 && $u16($entry + 2) === 3 && $u32($entry + 4) === 1) {
                $value = $u16($entry + 8);

                return in_array($value, range(1, 8), true) ? $value : 1;
            }
        }

        return 1;
    }

    private function orient(\GdImage $image, int $orientation): \GdImage
    {
        if (in_array($orientation, [2, 4, 5, 7], true)) {
            imageflip($image, $orientation === 2 ? IMG_FLIP_HORIZONTAL : ($orientation === 4 ? IMG_FLIP_VERTICAL : IMG_FLIP_HORIZONTAL));
        }
        $angle = match ($orientation) {
            3, 4 => 180, 5, 6 => -90, 7, 8 => 90, default => 0
        };

        return $angle === 0 ? $image : (imagerotate($image, $angle, 0) ?: $image);
    }
}

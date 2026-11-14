<?php

namespace App\Services;

use App\Models\Parcel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Validation binaire, ré-encodage GD (suppression EXIF/payloads) et stockage privé S3 des photos de preuve.
 */
class ParcelPhotoStorageService
{
    private const MAX_DIMENSION_PX = 10000;

    /**
     * Valide, ré-encode côté serveur en JPEG/PNG/WebP et enregistre en privé sur disk s3.
     */
    public function reencoderEtStocker(Parcel $parcel, UploadedFile $photo, string $type): string
    {
        $realPath = $photo->getRealPath();
        $rawBytes = is_string($realPath) ? @file_get_contents($realPath) : false;

        if (! is_string($rawBytes) || $rawBytes === '') {
            $this->rejeterFichier();
        }

        $info = @getimagesizefromstring($rawBytes);
        if ($info === false) {
            $this->rejeterFichier();
        }

        [$width, $height, $imageType] = $info;

        if ($width <= 0 || $height <= 0 || $width > self::MAX_DIMENSION_PX || $height > self::MAX_DIMENSION_PX) {
            $this->rejeterFichier('Dimensions de l\'image invalides.');
        }

        $formatSpec = match ($imageType) {
            IMAGETYPE_JPEG => ['ext' => 'jpg', 'alpha' => false],
            IMAGETYPE_PNG => ['ext' => 'png', 'alpha' => true],
            IMAGETYPE_WEBP => ['ext' => 'webp', 'alpha' => true],
            default => null,
        };

        if ($formatSpec === null) {
            $this->rejeterFichier();
        }

        $source = @imagecreatefromstring($rawBytes);
        if ($source === false) {
            $this->rejeterFichier();
        }

        $canvas = imagecreatetruecolor($width, $height);
        if ($canvas === false) {
            imagedestroy($source);
            $this->rejeterFichier('Impossible de traiter l\'image.');
        }

        try {
            if ($formatSpec['alpha']) {
                imagealphablending($canvas, false);
                imagesavealpha($canvas, true);
                $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
                if ($transparent !== false) {
                    imagefilledrectangle($canvas, 0, 0, $width, $height, $transparent);
                }
            }

            imagecopyresampled($canvas, $source, 0, 0, 0, 0, $width, $height, $width, $height);

            $reencoded = $this->encoderCanvas($canvas, $imageType);
        } finally {
            imagedestroy($source);
            imagedestroy($canvas);
        }

        $storagePath = sprintf(
            'photos/colis/%d/%s/%s.%s',
            $parcel->id,
            $type,
            (string) Str::uuid(),
            $formatSpec['ext'],
        );

        Storage::disk('s3')->put($storagePath, $reencoded, ['visibility' => 'private']);

        return $storagePath;
    }

    private function encoderCanvas(\GdImage $canvas, int $imageType): string
    {
        ob_start();
        $ok = match ($imageType) {
            IMAGETYPE_JPEG => imagejpeg($canvas, null, 85),
            IMAGETYPE_PNG => imagepng($canvas, null, 6),
            IMAGETYPE_WEBP => imagewebp($canvas, null, 85),
            default => false,
        };
        $bytes = ob_get_clean();

        if (! $ok || ! is_string($bytes) || $bytes === '') {
            $this->rejeterFichier('Échec de l\'encodage de l\'image.');
        }

        return $bytes;
    }

    /**
     * @return never
     */
    private function rejeterFichier(string $message = 'Fichier image invalide ou corrompu (JPEG, PNG ou WebP requis).'): void
    {
        throw ValidationException::withMessages([
            'photo' => [$message],
        ]);
    }
}

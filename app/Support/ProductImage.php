<?php

namespace App\Support;

use GdImage;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Turns a photo a vendor gave (an upload, or bytes from ImageFromLink) into what buyers load: WebP at
 * up to 1600 px and a 480 px thumbnail, turned upright from the camera's orientation, with every
 * byte of metadata dropped (a phone photo's EXIF carries where it was taken: often the vendor's
 * home). A buyer on mobile data loads a fraction of the original. GD only, no new dependency.
 */
class ProductImage
{
    public const MAX_SIDE = 1600;

    public const THUMB_SIDE = 480;

    /** Below this on the short side a product photo looks blurred on a phone (Jumia asks for 500). */
    public const MIN_SIDE = 400;

    /**
     * 30 megapixels, over twice a phone's normal photo: opened, each pixel takes 4 bytes of memory, so
     * this is also the guard against an image built to exhaust it.
     */
    private const MAX_PIXELS = 30_000_000;

    private const QUALITY = 82;

    private const TYPES = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP, IMAGETYPE_GIF];

    /**
     * Re-encode and keep both sizes under `$folder` on the public disk.
     *
     * @return array{path: string, thumb_path: string, width: int, height: int, size_bytes: int}
     *
     * @throws ValidationException on `$field`, in words the vendor can act on
     */
    public static function store(string $bytes, string $folder, string $field): array
    {
        $info = @getimagesizefromstring($bytes);
        if ($info === false || ! in_array($info[2], self::TYPES, true)) {
            self::fail($field, 'That file is not a JPG, PNG, WebP or GIF photo.');
        }
        [$width, $height] = $info;
        if ($width * $height > self::MAX_PIXELS) {
            self::fail($field, 'That photo is too large to open. Take it again at a normal size.');
        }

        $image = @imagecreatefromstring($bytes);
        if (! $image instanceof GdImage) {
            self::fail($field, 'That photo could not be opened. It may be damaged; take it again.');
        }

        $image = self::upright($image, $bytes, $info[2]);
        $width = imagesx($image);
        $height = imagesy($image);
        if (min($width, $height) < self::MIN_SIDE) {
            self::fail($field, "That photo is too small ({$width} by {$height}). Use one at least ".self::MIN_SIDE.' pixels on each side.');
        }

        $large = self::fit($image, self::MAX_SIDE);
        $thumb = self::fit($image, self::THUMB_SIDE);
        $name = trim($folder, '/').'/'.Str::ulid()->toBase32();

        $largeBytes = self::webp($large);
        $disk = Storage::disk('public');
        $disk->put("{$name}.webp", $largeBytes);
        $disk->put("{$name}-thumb.webp", self::webp($thumb));

        return [
            'path' => "{$name}.webp",
            'thumb_path' => "{$name}-thumb.webp",
            'width' => imagesx($large),
            'height' => imagesy($large),
            'size_bytes' => strlen($largeBytes),
        ];
    }

    /** A phone writes the photo sideways and says so in EXIF; turn the pixels instead, since EXIF goes. */
    private static function upright(GdImage $image, string $bytes, int $type): GdImage
    {
        if ($type !== IMAGETYPE_JPEG || ! function_exists('exif_read_data')) {
            return $image;
        }

        $exif = @exif_read_data('data://image/jpeg;base64,'.base64_encode($bytes));
        $turned = match ((int) ($exif['Orientation'] ?? 1)) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            default => $image,
        };

        return $turned instanceof GdImage ? $turned : $image;
    }

    /** Scaled down so its longer side is at most `$max`, never up; transparency kept. */
    private static function fit(GdImage $image, int $max): GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $scale = min(1, $max / max($width, $height));
        $toWidth = max(1, (int) round($width * $scale));
        $toHeight = max(1, (int) round($height * $scale));

        $out = imagecreatetruecolor($toWidth, $toHeight);
        imagealphablending($out, false);
        imagesavealpha($out, true);
        imagefill($out, 0, 0, (int) imagecolorallocatealpha($out, 255, 255, 255, 127));
        imagecopyresampled($out, $image, 0, 0, 0, 0, $toWidth, $toHeight, $width, $height);

        return $out;
    }

    private static function webp(GdImage $image): string
    {
        ob_start();
        imagewebp($image, null, self::QUALITY);

        return (string) ob_get_clean();
    }

    private static function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => [$message]]);
    }
}

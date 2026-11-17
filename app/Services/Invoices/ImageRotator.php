<?php

namespace App\Services\Invoices;

use Throwable;

/** Turns an invoice photo upright (GD). Claude says how many degrees clockwise it must be turned. */
class ImageRotator
{
    public const ANGLES = [0, 90, 180, 270];

    /** Larger images are not decoded; they are posted as they are. Also capped by the free memory (maxPixels()). */
    private const MAX_PIXELS = 40_000_000;

    /** GD keeps ~5 bytes per pixel, twice (original + turned), plus encoding: ~12 bytes per pixel to be safe. */
    private const BYTES_PER_PIXEL = 12;

    /** The largest image that fits in the memory still free under memory_limit. */
    public static function maxPixels(): int
    {
        $limit = self::bytes((string) ini_get('memory_limit'));
        if ($limit <= 0) {
            return self::MAX_PIXELS; // no limit
        }
        $free = $limit - memory_get_usage(true) - 16 * 1024 * 1024; // keep 16 MB for the rest of the request

        return max(0, min(self::MAX_PIXELS, intdiv($free, self::BYTES_PER_PIXEL)));
    }

    /** "256M" → 268435456; "-1" → -1. */
    private static function bytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return -1;
        }
        $n = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $n * 1024 ** 3,
            'm' => $n * 1024 ** 2,
            'k' => $n * 1024,
            default => $n,
        };
    }

    /**
     * @return array{body: string, mime: string, rotated: bool}
     */
    public static function upright(string $body, string $mime, int $clockwise): array
    {
        $same = ['body' => $body, 'mime' => $mime, 'rotated' => false];
        if (! in_array($clockwise, [90, 180, 270], true) || ! in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)
            || ! function_exists('imagerotate')) {
            return $same;
        }

        try {
            $size = @getimagesizefromstring($body);
            if (! $size || self::maxPixels() < $size[0] * $size[1]) {
                return $same;
            }
            $image = @imagecreatefromstring($body);
            if (! $image) {
                return $same;
            }
            // GD turns counter-clockwise.
            $turned = imagerotate($image, 360 - $clockwise, (int) imagecolorallocatealpha($image, 0, 0, 0, 127));
            unset($image);
            if (! $turned) {
                return $same;
            }
            imagesavealpha($turned, true);

            $webp = $mime === 'image/webp' && function_exists('imagewebp');
            ob_start();
            $ok = match (true) {
                $mime === 'image/png' => imagepng($turned),
                $webp => imagewebp($turned, null, 92),
                default => imagejpeg($turned, null, 92),
            };
            $out = (string) ob_get_clean();
            $outMime = $mime === 'image/png' ? 'image/png' : ($webp ? 'image/webp' : 'image/jpeg');

            return $ok && $out !== '' ? ['body' => $out, 'mime' => $outMime, 'rotated' => true] : $same;
        } catch (Throwable) {
            return $same;
        }
    }
}

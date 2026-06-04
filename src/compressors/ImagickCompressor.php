<?php

namespace bymayo\squash\compressors;

use bymayo\squash\models\CompressionResult;

/**
 * Zero-dependency fallback driver. Uses the `imagick` extension when present,
 * otherwise GD. Re-encodes JPEG/PNG (and, with Imagick, optimises GIF layers),
 * and minifies SVG in PHP.
 *
 * GD can't safely re-encode animated GIFs (it would flatten them to a single
 * frame), so GIF is only offered when Imagick is available.
 */
class ImagickCompressor extends BaseCompressor
{
    public function handle(): string
    {
        return 'imagick';
    }

    public function displayName(): string
    {
        return 'PHP Extension (Imagick/GD)';
    }

    public function isAvailable(): bool
    {
        return $this->hasImagick() || $this->hasGd();
    }

    public function supports(string $extension): bool
    {
        return match ($extension) {
            'jpg', 'jpeg', 'png' => $this->hasImagick() || $this->hasGd(),
            'gif' => $this->hasImagick(),
            'svg' => true,
            default => false,
        };
    }

    public function compress(string $path, string $extension): CompressionResult
    {
        if ($extension === 'svg') {
            return $this->minifySvg($path)
                ? $this->ok('Minified SVG.')
                : $this->fail('No smaller SVG result.');
        }

        try {
            if ($this->hasImagick()) {
                return $this->compressWithImagick($path, $extension);
            }
            if ($this->hasGd()) {
                return $this->compressWithGd($path, $extension);
            }
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }

        return $this->fail('Neither Imagick nor GD is available.');
    }

    private function compressWithImagick(string $path, string $extension): CompressionResult
    {
        $settings = $this->settings();
        $im = new \Imagick($path);

        if ($extension === 'gif') {
            // Preserve animation: optimise the layer deltas rather than flatten.
            $im = $im->coalesceImages();
            $im->optimizeImageLayers();
            $im->stripImage();
            $im->writeImages($path, true);
            $im->clear();
            return $this->ok('Optimised GIF layers (Imagick).');
        }

        if ($extension === 'png') {
            $im->setImageFormat('png');
            $im->setOption('png:compression-level', '9');
            $im->setOption('png:compression-filter', '5');
        } else {
            $im->setImageFormat('jpeg');
            $im->setImageCompression(\Imagick::COMPRESSION_JPEG);
            $im->setImageCompressionQuality($settings->jpegQualityClamped());
        }

        $im->stripImage();
        $im->writeImage($path);
        $im->clear();

        return $this->ok('Re-encoded with Imagick.');
    }

    private function compressWithGd(string $path, string $extension): CompressionResult
    {
        $settings = $this->settings();

        if ($extension === 'png') {
            $src = @imagecreatefrompng($path);
            if (!$src) {
                return $this->fail('GD could not read the PNG.');
            }
            imagealphablending($src, false);
            imagesavealpha($src, true);
            // 0-9 zlib level; quality ceiling maps onto a higher level.
            $level = (int) round((100 - $settings->pngQualityCeiling()) / 100 * 9);
            $written = imagepng($src, $path, max(0, min(9, $level)));
            imagedestroy($src);
            return $written ? $this->ok('Re-encoded PNG with GD.') : $this->fail('GD failed to write the PNG.');
        }

        // jpg / jpeg
        $src = @imagecreatefromjpeg($path);
        if (!$src) {
            return $this->fail('GD could not read the JPEG.');
        }
        $written = imagejpeg($src, $path, $settings->jpegQualityClamped());
        imagedestroy($src);
        return $written ? $this->ok('Re-encoded JPEG with GD.') : $this->fail('GD failed to write the JPEG.');
    }

    private function hasImagick(): bool
    {
        return extension_loaded('imagick') && class_exists('Imagick');
    }

    private function hasGd(): bool
    {
        return extension_loaded('gd') && function_exists('imagecreatefromjpeg');
    }
}

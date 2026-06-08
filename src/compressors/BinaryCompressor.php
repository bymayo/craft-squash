<?php

namespace bymayo\squash\compressors;

use bymayo\squash\models\CompressionResult;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Shells out to native command-line optimisers:
 *   jpg  -> jpegoptim
 *   png  -> pngquant
 *   gif  -> gifsicle
 *   pdf  -> ghostscript (gs)
 *   svg  -> svgo (falls back to the built-in PHP minifier if svgo is absent)
 *
 * Each tool is auto-detected; a format is only "supported" if its binary is on
 * the PATH and shell execution is enabled.
 */
class BinaryCompressor extends BaseCompressor
{
    /** @var array<string, string|false> resolved binary paths, false = absent */
    private array $binaryCache = [];

    public function handle(): string
    {
        return 'binary';
    }

    public function displayName(): string
    {
        return 'Binaries (e.g. jpegoptim)';
    }

    public function isAvailable(): bool
    {
        if (!$this->shellEnabled()) {
            return false;
        }
        // Available if we can do *something*: any binary, or SVG via fallback.
        return $this->binary('jpegoptim')
            || $this->binary('pngquant')
            || $this->binary('gifsicle')
            || $this->binary('svgo')
            || true; // SVG always works via the PHP minifier fallback
    }

    public function supports(string $extension): bool
    {
        return match ($extension) {
            'jpg', 'jpeg' => $this->shellEnabled() && (bool) $this->binary('jpegoptim'),
            'png' => $this->shellEnabled() && (bool) $this->binary('pngquant'),
            'gif' => $this->shellEnabled() && (bool) $this->binary('gifsicle'),
            'pdf' => $this->shellEnabled() && (bool) $this->binary('gs'),
            'svg' => true, // svgo if present, else PHP minifier
            default => false,
        };
    }

    public function compress(string $path, string $extension): CompressionResult
    {
        try {
            return match ($extension) {
                'jpg', 'jpeg' => $this->runJpegoptim($path),
                'png' => $this->runPngquant($path),
                'gif' => $this->runGifsicle($path),
                'pdf' => $this->runGhostscript($path),
                'svg' => $this->runSvg($path),
                default => $this->fail("Unsupported format: {$extension}"),
            };
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    private function runJpegoptim(string $path): CompressionResult
    {
        $bin = $this->binary('jpegoptim');
        if (!$bin) {
            return $this->fail('jpegoptim not found.');
        }
        $quality = $this->settings()->jpegQualityClamped();
        $process = new Process([$bin, '--strip-all', '--all-progressive', "--max={$quality}", $path]);
        $process->run();
        return $process->isSuccessful()
            ? $this->ok('jpegoptim.')
            : $this->fail('jpegoptim: ' . trim($process->getErrorOutput()));
    }

    private function runPngquant(string $path): CompressionResult
    {
        $bin = $this->binary('pngquant');
        if (!$bin) {
            return $this->fail('pngquant not found.');
        }
        $range = $this->settings()->pngQuality ?: '65-80';
        // Write to a sibling temp file then swap, so a failed pass can't corrupt
        // the working copy. pngquant exits 99 when it can't hit the quality
        // floor; treat that as "no usable result" rather than a hard error.
        $tmp = $path . '.sq.png';
        $process = new Process([$bin, "--quality={$range}", '--force', '--strip', '--output', $tmp, '--', $path]);
        $process->run();
        if (is_file($tmp) && filesize($tmp) > 0 && $process->getExitCode() === 0) {
            rename($tmp, $path);
            return $this->ok('pngquant.');
        }
        @unlink($tmp);
        return $this->fail('pngquant produced no smaller result.');
    }

    private function runGifsicle(string $path): CompressionResult
    {
        $bin = $this->binary('gifsicle');
        if (!$bin) {
            return $this->fail('gifsicle not found.');
        }
        $level = max(1, min(3, $this->settings()->gifOptimizationLevel));
        $process = new Process([$bin, "-O{$level}", '--batch', $path]);
        $process->run();
        return $process->isSuccessful()
            ? $this->ok('gifsicle.')
            : $this->fail('gifsicle: ' . trim($process->getErrorOutput()));
    }

    private function runGhostscript(string $path): CompressionResult
    {
        $bin = $this->binary('gs');
        if (!$bin) {
            return $this->fail('Ghostscript (gs) not found.');
        }
        $preset = $this->settings()->pdfQualityPreset();
        // Re-distill to a sibling temp file then swap, so a failed pass can't
        // corrupt the working copy. The Squasher's skip-if-larger guard discards
        // the result if Ghostscript didn't actually shrink it.
        $tmp = $path . '.sq.pdf';
        $process = new Process([
            $bin,
            '-sDEVICE=pdfwrite',
            '-dCompatibilityLevel=1.5',
            '-dPDFSETTINGS=/' . $preset,
            '-dNOPAUSE',
            '-dQUIET',
            '-dBATCH',
            '-dDetectDuplicateImages=true',
            '-sOutputFile=' . $tmp,
            $path,
        ]);
        $process->run();
        if (is_file($tmp) && filesize($tmp) > 0 && $process->isSuccessful()) {
            rename($tmp, $path);
            return $this->ok('Ghostscript (PDF).');
        }
        @unlink($tmp);
        return $this->fail('Ghostscript: ' . (trim($process->getErrorOutput()) ?: 'no usable result.'));
    }

    private function runSvg(string $path): CompressionResult
    {
        $bin = $this->binary('svgo');
        if ($bin) {
            $process = new Process([$bin, '--input', $path, '--output', $path]);
            $process->run();
            if ($process->isSuccessful()) {
                return $this->ok('svgo.');
            }
        }
        // No svgo (or it failed): fall back to the dependency-free minifier.
        return $this->minifySvg($path)
            ? $this->ok('SVG minified (PHP fallback).')
            : $this->fail('No smaller SVG result.');
    }

    private function shellEnabled(): bool
    {
        if (!function_exists('proc_open')) {
            return false;
        }
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        return !in_array('proc_open', $disabled, true);
    }

    private function binary(string $name): string|false
    {
        if (!array_key_exists($name, $this->binaryCache)) {
            $this->binaryCache[$name] = (new ExecutableFinder())->find($name) ?? false;
        }
        return $this->binaryCache[$name];
    }
}

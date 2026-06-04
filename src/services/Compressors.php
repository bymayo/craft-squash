<?php

namespace bymayo\squash\services;

use bymayo\squash\compressors\ApiCompressor;
use bymayo\squash\compressors\BinaryCompressor;
use bymayo\squash\compressors\CompressorInterface;
use bymayo\squash\compressors\ImagickCompressor;
use bymayo\squash\events\RegisterCompressorsEvent;
use bymayo\squash\Squash;
use Symfony\Component\Process\ExecutableFinder;
use yii\base\Component;

/**
 * Registry of compression drivers. Resolves which driver runs for a given
 * format, with a guaranteed always-available fallback so a misconfigured
 * primary driver never silently does nothing.
 */
class Compressors extends Component
{
    public const EVENT_REGISTER_COMPRESSORS = 'registerCompressors';

    /** @var array<string, CompressorInterface>|null */
    private ?array $compressors = null;

    /**
     * All registered drivers, keyed by handle (built-ins plus any added by
     * other plugins via EVENT_REGISTER_COMPRESSORS).
     *
     * @return array<string, CompressorInterface>
     */
    public function getAllCompressors(): array
    {
        if ($this->compressors === null) {
            $event = new RegisterCompressorsEvent([
                'compressors' => [
                    'imagick' => new ImagickCompressor(),
                    'binary' => new BinaryCompressor(),
                    'api' => new ApiCompressor(),
                ],
            ]);
            $this->trigger(self::EVENT_REGISTER_COMPRESSORS, $event);
            $this->compressors = $event->compressors;
        }
        return $this->compressors;
    }

    public function getCompressorByHandle(string $handle): ?CompressorInterface
    {
        return $this->getAllCompressors()[$handle] ?? null;
    }

    /**
     * Detect which underlying tools are available on this server, for display
     * in the settings UI. Each row: label, what it covers, and whether it's
     * installed.
     *
     * @return array<int, array{name: string, handles: string, type: string, installed: bool}>
     */
    public function getEnvironmentStatus(): array
    {
        $hasImagick = extension_loaded('imagick') && class_exists('Imagick');
        $hasGd = extension_loaded('gd') && function_exists('imagecreatefromjpeg');

        $shellEnabled = function_exists('proc_open')
            && !in_array('proc_open', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true);

        $finder = new ExecutableFinder();
        $binary = static fn (string $name): bool => $shellEnabled && $finder->find($name) !== null;

        return [
            ['name' => 'Imagick', 'handles' => 'JPEG, PNG, GIF, SVG', 'type' => 'PHP extension', 'installed' => $hasImagick],
            ['name' => 'GD', 'handles' => 'JPEG, PNG', 'type' => 'PHP extension', 'installed' => $hasGd],
            ['name' => 'Shell access (proc_open)', 'handles' => 'Required for native binaries', 'type' => 'PHP', 'installed' => $shellEnabled],
            ['name' => 'jpegoptim', 'handles' => 'JPEG', 'type' => 'Binary', 'installed' => $binary('jpegoptim')],
            ['name' => 'pngquant', 'handles' => 'PNG', 'type' => 'Binary', 'installed' => $binary('pngquant')],
            ['name' => 'gifsicle', 'handles' => 'GIF', 'type' => 'Binary', 'installed' => $binary('gifsicle')],
            ['name' => 'svgo', 'handles' => 'SVG', 'type' => 'Binary', 'installed' => $binary('svgo')],
            ['name' => 'gs (Ghostscript)', 'handles' => 'PDF', 'type' => 'Binary', 'installed' => $binary('gs')],
        ];
    }

    /** The driver chosen in settings (may be unavailable / not support a format). */
    public function getActiveCompressor(): ?CompressorInterface
    {
        return $this->getCompressorByHandle(Squash::getInstance()->getSettings()->activeDriver);
    }

    /** The always-available built-in fallback. */
    public function getFallbackCompressor(): CompressorInterface
    {
        return $this->getCompressorByHandle('imagick') ?? new ImagickCompressor();
    }

    /**
     * Pick the driver to run for a given extension: the configured driver when
     * it's available and supports the format, otherwise the Imagick/GD fallback
     * if *it* supports the format. Returns null when nothing can handle it.
     */
    public function resolveFor(string $extension): ?CompressorInterface
    {
        $extension = strtolower($extension);

        $active = $this->getActiveCompressor();
        if ($active && $active->isAvailable() && $active->supports($extension)) {
            return $active;
        }

        $fallback = $this->getFallbackCompressor();
        if ($fallback->isAvailable() && $fallback->supports($extension)) {
            return $fallback;
        }

        // Last resort: the native binaries handle formats the Imagick/GD fallback
        // can't (e.g. PDF via Ghostscript), regardless of the selected driver.
        $binary = $this->getCompressorByHandle('binary');
        if ($binary && $binary->isAvailable() && $binary->supports($extension)) {
            return $binary;
        }

        return null;
    }
}

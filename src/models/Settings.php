<?php

namespace bymayo\squash\models;

use craft\base\Model;

/**
 * Plugin-wide settings, stored in `{{%squash_settings}}` (NOT Project Config)
 * so admins can adjust drivers, quality and thresholds on production without a
 * deploy clobbering them. Per-environment overrides live in `config/squash.php`
 * and win over the DB row. Same approach as bymayo/nudge and bymayo/points.
 */
class Settings extends Model
{
    public const DRIVER_API = 'api';
    public const DRIVER_BINARY = 'binary';
    public const DRIVER_IMAGICK = 'imagick';

    public const API_TINYPNG = 'tinypng';
    public const API_SHORTPIXEL = 'shortpixel';
    public const API_KRAKEN = 'kraken';

    /** All formats Squash knows how to compress. */
    public const SUPPORTED_FORMATS = ['jpg', 'png', 'gif', 'svg', 'pdf'];

    /** Ghostscript PDFSETTINGS presets, smallest → largest output. */
    public const PDF_PRESETS = ['screen', 'ebook', 'printer', 'prepress'];

    /** Display name shown in the CP. */
    public string $pluginName = 'Squash';

    /** Active compression driver: api | binary | imagick. */
    public string $activeDriver = self::DRIVER_IMAGICK;

    /** Which external service when activeDriver is 'api': tinypng | shortpixel | kraken. */
    public string $apiService = self::API_TINYPNG;

    /** API key for the chosen service. */
    public string $apiKey = '';

    /** API secret — only used by services that need one (e.g. Kraken.io). */
    public string $apiSecret = '';

    /**
     * File extensions Squash is allowed to touch. A subset of SUPPORTED_FORMATS.
     * @var string[]
     */
    public array $enabledFormats = ['jpg', 'png', 'gif', 'svg'];

    /** Queue new uploads for compression automatically. */
    public bool $compressOnUpload = false;

    /** Only auto-compress uploads at least this many bytes. 0 = no minimum. */
    public int $autoUploadThreshold = 0;

    /** Bytes above which an asset appears in the report utility's "needs compression" view. */
    public int $reportThreshold = 2097152;

    /** Copy the original to a backup location before overwriting it. */
    public bool $keepBackups = true;

    /**
     * Handle of a Craft filesystem for backups. Empty = store on the asset's
     * own volume filesystem under the backup folder.
     */
    public string $backupFsHandle = '';

    /** Folder name backups are stored in (relative to the backup filesystem). */
    public string $backupFolder = '_squash-backups';

    /** JPEG quality, 1-100. */
    public int $jpegQuality = 82;

    /**
     * PNG quality. For the binary driver (pngquant) a "min-max" range string
     * such as '65-80'; the Imagick/GD driver uses the upper bound.
     */
    public string $pngQuality = '65-80';

    /** gifsicle optimisation level, 1-3. */
    public int $gifOptimizationLevel = 3;

    /** Ghostscript PDF preset (one of PDF_PRESETS). Used by the binary driver. */
    public string $pdfQuality = 'ebook';

    /**
     * Normalise the JPEG quality and clamp to a sane range. Returns 1-100.
     */
    public function jpegQualityClamped(): int
    {
        return max(1, min(100, $this->jpegQuality));
    }

    /** The configured Ghostscript PDF preset, falling back to a safe default. */
    public function pdfQualityPreset(): string
    {
        return in_array($this->pdfQuality, self::PDF_PRESETS, true) ? $this->pdfQuality : 'ebook';
    }

    /**
     * The upper bound of the configured PNG quality (handles both "65-80" and
     * a bare "80"). Used by the Imagick/GD driver. Returns 1-100.
     */
    public function pngQualityCeiling(): int
    {
        $parts = explode('-', $this->pngQuality);
        $ceiling = (int) trim(end($parts));
        return $ceiling > 0 ? max(1, min(100, $ceiling)) : 80;
    }

    /**
     * The backup folder, trimmed of surrounding slashes, falling back to the
     * default if blank. Safe to use as a path prefix.
     */
    public function backupFolderTrimmed(): string
    {
        $folder = trim($this->backupFolder, '/');
        return $folder !== '' ? $folder : '_squash-backups';
    }

    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['pluginName'], 'required'];
        $rules[] = [['pluginName'], 'string', 'max' => 50];
        $rules[] = [['activeDriver'], 'in', 'range' => [self::DRIVER_API, self::DRIVER_BINARY, self::DRIVER_IMAGICK]];
        $rules[] = [['apiService'], 'in', 'range' => [self::API_TINYPNG, self::API_SHORTPIXEL, self::API_KRAKEN]];
        $rules[] = [['apiKey', 'apiSecret', 'backupFsHandle', 'backupFolder', 'pngQuality'], 'string'];
        $rules[] = [['compressOnUpload', 'keepBackups'], 'boolean'];
        $rules[] = [['autoUploadThreshold', 'reportThreshold'], 'integer', 'min' => 0];
        $rules[] = [['jpegQuality'], 'integer', 'min' => 1, 'max' => 100];
        $rules[] = [['gifOptimizationLevel'], 'integer', 'min' => 1, 'max' => 3];
        $rules[] = [['pdfQuality'], 'in', 'range' => self::PDF_PRESETS];
        $rules[] = [['enabledFormats'], 'each', 'rule' => ['in', 'range' => self::SUPPORTED_FORMATS]];
        // Require an API key when the API driver is selected.
        $rules[] = [['apiKey'], 'required', 'when' => fn(self $model) => $model->activeDriver === self::DRIVER_API];
        // Kraken.io also needs an API secret.
        $rules[] = [['apiSecret'], 'required', 'when' => fn(self $model) => $model->activeDriver === self::DRIVER_API && $model->apiService === self::API_KRAKEN];
        return $rules;
    }
}

<?php

/**
 * Squash plugin - example config file.
 *
 * Copy this to `config/squash.php` in your Craft project. Values here override
 * whatever is saved on the CP Settings page, so you can pin per-environment
 * behaviour (e.g. only compress-on-upload in production) without depending on
 * the DB row.
 *
 * Multi-environment config is supported via the standard Craft pattern - wrap
 * keys in `'*' => [...]`, `'production' => [...]`, etc. See:
 * https://craftcms.com/docs/5.x/configure.html#multi-environment-configs
 */

return [
    // Display name shown in the CP.
    'pluginName' => 'Squash',

    // Active compression driver: 'api' | 'binary' | 'imagick'.
    'activeDriver' => 'imagick',

    // When activeDriver is 'api': which service. 'tinypng' | 'shortpixel' | 'kraken'.
    'apiService' => 'tinypng',

    // API key for the chosen service. Prefer an env var, e.g. getenv('TINYPNG_KEY').
    'apiKey' => '',

    // API secret — only used by services that need one (Kraken.io).
    'apiSecret' => '',

    // File extensions Squash is allowed to compress. 'pdf' needs the Ghostscript
    // (gs) binary and the native-binaries driver.
    'enabledFormats' => ['jpg', 'png', 'gif', 'svg'],

    // Automatically queue new uploads for compression.
    'compressOnUpload' => false,

    // Only auto-compress uploads at least this many bytes. 0 = no minimum.
    'autoUploadThreshold' => 0,

    // Bytes above which an asset shows up in the report utility's "needs
    // compression" view.
    'reportThreshold' => 2097152, // 2 MB

    // Copy the original to a backup location before overwriting it.
    'keepBackups' => true,

    // Handle of a Craft filesystem to store backups in. Empty = store on the
    // asset's own volume under the backup folder.
    'backupFsHandle' => '',

    // Folder name backups are stored in (relative to the backup filesystem).
    'backupFolder' => '_squash-backups',

    // Auto-delete backups older than this many days during Craft's garbage
    // collection. 0 = keep forever.
    'backupRetentionDays' => 90,

    // JPEG quality (1-100). Higher = better quality, larger file.
    'jpegQuality' => 82,

    // PNG quality. For the binary driver (pngquant) this is a "min-max" range
    // string, e.g. '65-80'. For the Imagick/GD driver the upper bound is used.
    'pngQuality' => '65-80',

    // gifsicle optimisation level (1-3). Higher = smaller, slower.
    'gifOptimizationLevel' => 3,

    // Ghostscript PDF preset: 'screen' | 'ebook' | 'printer' | 'prepress'.
    'pdfQuality' => 'ebook',
];

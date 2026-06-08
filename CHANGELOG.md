# Release Notes for Squash

## 1.0.4 - 2026-06-08

### Added
- **File Modified Date** column in the report utility.
- Sortable **Size**, **Type**, **File Modified Date** and **Compressed On** columns in the report utility.

### Changed
- The report utility now orders by **File Modified Date** (newest first) by default.

## 1.0.3 - 2026-06-08

### Changed
- Settings are now stored in Project Config instead of the database. `config/squash.php` still overrides them.

## 1.0.2 - 2026-06-05

### Added
- **Needs Compression** dashboard widget showing how many assets are still over the threshold and uncompressed, linking through to the report utility. Only available to users with report-utility access.

## 1.0.1 - 2026-06-04

### Changed
- File sizes now display with one decimal place (e.g. `777.4 KB`) consistently across the report utility's stats, Size and Saved columns, the threshold text, and the per-asset **Compressed (%)** metadata row.

## 1.0.0 - 2026-06-04

### Added
- Pluggable compression drivers: external API (TinyPNG / ShortPixel / Kraken.io), native binaries (jpegoptim, pngquant, gifsicle, svgo), and a zero-dependency Imagick/GD fallback.
- Compress JPEG, PNG, GIF and SVG assets, plus PDF (via Ghostscript, with a quality preset).
- Automatic compression on upload (optional, with its own size threshold).
- Manual bulk compression via an Assets element-index action.
- Bulk compression runs in batched queue jobs (up to 50 assets each) that report progress, rather than one queue job per asset.
- Per-asset **Compress** / **Restore** items in an asset's action (•••) menu, shown only when relevant — Compress is hidden once an asset is compressed, and Restore is hidden until a backup exists.
- Original-file backups before every overwrite, stored alongside the asset or on a chosen Craft filesystem, under a configurable backup folder name.
- One-click **Restore** that puts the original back, then deletes the backup file and clears the asset's log rows so it can be compressed again.
- Backup retention: backups older than a configurable window (default 90 days; 0 = keep forever) are pruned during Craft's garbage collection, with a `squash/backups/prune` console command to force it.
- Permanently deleting an asset removes its backup and log records — immediately when deleted from the trash, and via garbage collection for any orphans (e.g. assets removed by GC itself).
- "Skip if larger" guard so a compressed result never replaces a smaller original.
- Never re-compresses an already-compressed asset (avoids cumulative quality loss); mixed bulk selections only compress the not-yet-compressed ones, with the index actions disabling themselves accordingly.
- Compression state on the Assets index: an optional **Compressed** column (green check when space was saved, grey check when already optimised) and a **Compressed** filter that matches any processed asset (compressed or already optimised).
- Per-asset metadata rows on the edit screen: **Compressed at**, **Compressed by** (user chip) and **Compressed (%)** — also reflecting "Already optimised" for skipped assets.
- Records which user triggered each compression / restore run.
- Dedicated `storage/logs/squash.log` capturing every outcome (status and before → after sizes).
- Configurable plugin name, used throughout the control panel (utility, element actions, columns).
- Driver **Server support** table showing which tools (Imagick, GD, shell access, jpegoptim, pngquant, gifsicle, svgo) are detected on the server.
- "{Plugin name} Assets" utility with **All**, **Compressed** and **Needs compression** views (compressed assets show even when now under the threshold), a Size column showing the before → after change (the result highlighted), a Saved/Compressed-on summary, savings stats, and bulk compress / restore. The table uses Craft's admin table with built-in search and pagination. Skipped assets read "Already optimised" and keep a **Compress** button so they can be retried.
- Per-format quality settings and per-environment config overrides (`config/squash.php`).
- Auto-compress and report thresholds are chosen from preset sizes (256 KB – 20 MB) with a **Custom** option; values are stored as bytes, so `config/squash.php` overrides still use raw byte counts and map back to the matching preset (or Custom).
- Local and remote (S3/etc.) filesystem support via Craft's filesystem layer.
- User permissions for compressing and restoring assets; report-utility access uses Craft's standard per-utility permission, and settings are admin-only.

### Fixed
- The reported saving is measured from the actual file on disk rather than the (sometimes stale) asset record size, so savings can no longer be reported when the file didn't actually shrink.
- The compression log keeps a single row per asset (replaced in place) instead of inserting a new row on every attempt, so repeated "skipped" runs no longer pile up duplicates.

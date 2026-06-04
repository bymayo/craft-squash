# Squash

Compress images in your Craft CMS asset library — automatically on upload, or on demand from the control panel. Squash keeps a backup of every original so you can restore it with one click, and ships a report of oversized assets so you can find and fix the heavy files dragging your site down.

## Features

- **Pluggable compression drivers.** Choose the engine that fits your host:
  - **API** — [TinyPNG](https://tinypng.com/developers), [ShortPixel](https://shortpixel.com/api-docs) or [Kraken.io](https://kraken.io/docs/getting-started). Best ratios, works on any host, needs an API key (Kraken.io also needs an API secret).
  - **Binaries** — `jpegoptim`, `pngquant`, `gifsicle`, `svgo`, `gs` (Ghostscript, for PDFs) via shell. Free and fast on hosts with shell access.
  - **Imagick / GD** — zero-dependency PHP fallback that works everywhere.
- **Formats:** JPEG, PNG, GIF, SVG, and PDF (PDF requires the Ghostscript `gs` binary).
- **Three ways to compress:**
  - *On upload* — new uploads are queued for compression automatically (optional, with its own size threshold).
  - *On demand (bulk)* — select assets in the Assets index and choose **Compress file**, or compress straight from the report.
  - *Per asset* — **Compress file** / **Restore file** items in an asset's action (•••) menu, shown only when they apply.
- **Safe by default.** The original file is backed up before it's overwritten, and a compressed result is only written back if it's actually smaller. Restoring puts the original back, then removes the backup so the asset can be compressed again. Already-compressed assets are never re-compressed, so quality doesn't degrade over repeated runs.
- **See what's compressed at a glance.** An optional **Compressed** column and a **Compressed** filter on the Assets index, plus **Compressed at / by / (%)** rows in each asset's metadata panel. Every run records which user triggered it. (See [Compressed vs already optimised](#compressed-vs-already-optimised).)
- **Local + remote filesystems.** Remote assets (S3, DigitalOcean, etc.) are streamed to a temp file, compressed, and written back through Craft's filesystem layer.
- **Report utility.** A control-panel utility (named after your plugin) with **All**, **Compressed** and **Needs compression** views, in a searchable, paginated table. The Size column shows the before → after change for compressed assets, alongside the amount saved and when. Plus running savings stats and one-click bulk compress / restore.
- **Diagnostics.** A **Server support** table on the Image driver settings tab shows which tools are detected, and every run is written to a dedicated `storage/logs/squash.log` with before → after sizes.

## Requirements

Craft CMS 5.6.0 or later, running on PHP 8.2 or later.

The **binaries** driver additionally needs the relevant command-line tools installed and shell access enabled. The **API** driver needs a TinyPNG or ShortPixel API key. The **Imagick / GD** driver needs the `imagick` or `gd` PHP extension (one is present on virtually every Craft host).

## Installation

Install with Composer and enable in the control panel:

```bash
composer require bymayo/squash
./craft plugin/install squash
```

## Configuration

Open **Settings → Plugins → Squash** to choose an engine, set quality and thresholds, and toggle compress-on-upload. Settings are grouped into **General** (plugin name, on-upload, backups & report), **Image driver** (the image engine, API key, and a **Server support** table showing which tools are available), and **Formats & quality** (including the PDF quality preset; PDFs always use Ghostscript).

The auto-compress and report **thresholds** are picked from preset sizes (256 KB – 20 MB) with a **Custom** option for anything in between. They're stored as bytes under the hood.

To pin behaviour per environment, copy `src/config.php` to `config/squash.php` in your project. Values there override the control-panel settings — thresholds are still given in raw bytes (e.g. `'reportThreshold' => 2097152`), and the settings screen maps a matching value back to its preset, or shows it under "Custom".

## Compressed vs already optimised

A run can end in one of two "done" states, and Squash distinguishes them so you don't waste time re-processing files that can't get any smaller:

- **Compressed** — the file was made smaller and the new, lighter version was written back (the original is kept as a backup). Shown with a **green check** in the Assets index, and the saving (e.g. `4.2 MB → 3.1 MB`) in the report and metadata.
- **Already optimised** — Squash ran but the result wasn't any smaller, so the original was left untouched. Shown with a **grey check** in the index and "Already optimised" in the report/metadata. No backup is made (nothing changed). You can still hit **Compress** to try again — useful after switching driver or quality settings.

Both count as "processed", so the **Compressed** filter on the Assets index returns compressed *and* already-optimised assets. Assets that have never been run show no check and aren't matched by the filter.

## Backups & restoring

When **Keep backups** is on (the default), the untouched original is copied to a backup location before Squash overwrites the asset. By default backups live alongside the asset under a `_squash-backups/` folder (the folder name is configurable); point **Backup filesystem** at a dedicated Craft filesystem to store them elsewhere. The backup mirrors the asset's own folder structure.

Use the **Restore file** action (in an asset's action menu, the Assets index, or the report) to roll an asset back. Restoring puts the original back, deletes the backup file, and clears the asset's compression history so it can be compressed again.

## Permissions

Access is controlled per user group under **Settings → Users → (group) → Permissions**:

- **Compress assets** (`squash-compressAssets`) — compress files from the Assets index action, an asset's action (•••) menu, and the report utility.
- **Restore assets** (`squash-restoreAssets`) — restore a compressed asset back to its original.

Access to the report utility itself is Craft's standard per-utility permission: tick the **"{Plugin name} Assets"** utility under the group's **Utilities** permissions. A user with utility access but without *Compress*/*Restore* sees the report read-only.

Plugin **settings** are admin-only (they're meant to be managed locally with admin changes enabled), so there's no separate permission for them.

## Support

[jason@bymayo.co.uk](mailto:jason@bymayo.co.uk)

# Squash for Craft CMS 5

<img src="https://raw.githubusercontent.com/bymayo/craft-squash/craft-5/src/icon.svg" width="70">

We've all been there: the client's uploaded a 15MB hero image — amongst plenty of others — and quietly filled the storage. Squash compresses your asset library, automatically on upload or on demand, shrinking JPEG, PNG, GIF, SVG and PDF files with your choice of engine. It keeps a backup of every original so you can restore in one click, and tracks your savings from a built-in report.

## Features

- **Choose your engine**: API (TinyPNG / ShortPixel / Kraken.io), native binaries (jpegoptim, pngquant, gifsicle, svgo, Ghostscript), or a zero-dependency Imagick/GD fallback
- **Formats**: JPEG, PNG, GIF, SVG and PDF
- **Compress anywhere**: automatically on upload, in bulk from the Assets index, or per-asset from its action menu
- **Safe by default**: backs up the original first, only writes back if it's actually smaller, and never re-compresses an already-optimised file
- **One-click restore**: roll any asset back to its original, which removes the backup and lets it be compressed again
- **Report utility**: *All / Compressed / Needs compression* views with search, pagination, savings stats, and bulk actions
- **At a glance**: a Compressed column + filter on the Assets index, plus *Compressed at / by / %* in each asset's metadata
- **Batched queue jobs**: large runs are chunked and report progress, instead of one task per asset
- **Renameable**: rebrand the plugin name throughout the control panel
- **Granular permissions**: separate compress / restore controls per user group
- **Diagnostics**: a server-support table showing which tools are available, and a dedicated `squash.log`
- **Extensible**: register your own compression drivers via an event

## Why not ImageOptimize or Imager X?

They solve a different problem. ImageOptimize and Imager X optimise **transforms** — the resized variants you generate in templates — and leave your original uploads untouched. Squash optimises the **originals in your asset library**, which means lower storage and backup costs, lighter direct downloads, and a leaner DAM — and it covers files transforms don't, like PDFs and downloadable originals. Every original is backed up so you can restore it.

They're complementary: use a transform plugin for front-end delivery, and Squash to keep the library itself lean.

## Requirements

Craft CMS 5.6.0+ and PHP 8.2+. Each driver has its own needs:

- **API** — a TinyPNG / ShortPixel / Kraken.io API key (Kraken.io also needs a secret).
- **Binaries** — the relevant CLI tools (`jpegoptim`, `pngquant`, `gifsicle`, `svgo`, and `gs` for PDFs) plus shell access.
- **Imagick / GD** — the `imagick` or `gd` PHP extension (present on virtually every host).

The **Image driver → Server support** table shows what's detected on your server.

## Installation

```bash
composer require bymayo/squash
./craft plugin/install squash
```

## Configuration

Open **Settings → Plugins → Squash**. Settings are grouped into **General** (plugin name, on-upload, backups & report threshold), **Image driver** (engine, API credentials, server support), and **Formats & quality** (per-format quality and the PDF preset).

To pin behaviour per environment, copy `src/config.php` to `config/squash.php`; values there override the control-panel settings.

## Compressed vs already optimised

A run ends in one of two states:

- **Compressed** — the file got smaller, so the lighter version is written back (original kept as a backup). Green tick.
- **Already optimised** — Squash ran but couldn't shrink it, so the original is left untouched. Grey tick; you can still retry.

Both count as "processed", so the Compressed column and filter match either.

## Backups & restoring

With **Keep backups** on (the default), the original is copied to a backup location before Squash overwrites the asset — alongside it under a configurable `_squash-backups/` folder, or on a dedicated filesystem. Backups are written straight to the filesystem and aren't shown in the control panel (so they're never re-compressed). Use **Restore original file** to roll an asset back; restoring deletes the backup and clears its history so it can be compressed again.

## Permissions

Per user group under **Settings → Users → (group) → Permissions**:

- **Compress assets** (`squash-compressAssets`)
- **Restore assets** (`squash-restoreAssets`)

Report-utility access is Craft's standard per-utility permission (under **Utilities**). Plugin settings are admin-only.

## Support

If you have any issues (surely not!) then I'll aim to reply to these as soon as possible. If it's a site-breaking-oh-no-what-has-happened moment, then hit me up on the Craft CMS Discord - `@bymayo`.

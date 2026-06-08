<?php

namespace bymayo\squash\compressors;

use bymayo\squash\models\CompressionResult;
use bymayo\squash\models\Settings;
use bymayo\squash\Squash;

/**
 * Shared driver helpers: settings access, result factories, and a dependency-
 * free SVG minifier every driver can fall back to (image libraries and the
 * raster APIs can't touch SVG, but it's pure text and safe to minify in PHP).
 */
abstract class BaseCompressor implements CompressorInterface
{
    protected function settings(): Settings
    {
        return Squash::getInstance()->getSettings();
    }

    protected function ok(?string $message = null): CompressionResult
    {
        $r = new CompressionResult();
        $r->success = true;
        $r->message = $message;
        return $r;
    }

    protected function fail(string $message): CompressionResult
    {
        $r = new CompressionResult();
        $r->success = false;
        $r->message = $message;
        return $r;
    }

    /**
     * Lightweight, lossless SVG minify: drop the XML prolog comment noise,
     * editor metadata, comments, and collapse inter-tag and attribute
     * whitespace. Conservative on purpose; never touches element/attr names.
     *
     * @return bool true if $path was rewritten smaller
     */
    protected function minifySvg(string $path): bool
    {
        $svg = @file_get_contents($path);
        if ($svg === false || $svg === '') {
            return false;
        }

        $out = $svg;
        // Strip comments.
        $out = preg_replace('/<!--.*?-->/s', '', $out) ?? $out;
        // Strip editor metadata/namespaced cruft blocks.
        $out = preg_replace('/<metadata\b[^>]*>.*?<\/metadata>/is', '', $out) ?? $out;
        $out = preg_replace('/<\?xml[^>]*\?>/i', '', $out) ?? $out;
        $out = preg_replace('/<!DOCTYPE[^>]*>/i', '', $out) ?? $out;
        // Collapse whitespace between tags.
        $out = preg_replace('/>\s+</', '><', $out) ?? $out;
        // Collapse runs of whitespace inside the markup.
        $out = preg_replace('/\s{2,}/', ' ', $out) ?? $out;
        $out = trim($out);

        if ($out === '' || strlen($out) >= strlen($svg)) {
            return false;
        }

        return @file_put_contents($path, $out) !== false;
    }
}

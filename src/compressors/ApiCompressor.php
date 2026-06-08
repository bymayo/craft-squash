<?php

namespace bymayo\squash\compressors;

use bymayo\squash\models\CompressionResult;
use bymayo\squash\models\Settings;
use Craft;
use craft\helpers\Json;

/**
 * External-API driver. Uploads the file to TinyPNG, ShortPixel or Kraken.io and
 * writes the compressed bytes back. Raster only; SVG isn't sent off-box (it's
 * minified locally via the PHP fallback instead).
 *
 *   TinyPNG    -> jpg, png
 *   ShortPixel -> jpg, png, gif
 *   Kraken.io  -> jpg, png, gif
 */
class ApiCompressor extends BaseCompressor
{
    public function handle(): string
    {
        return 'api';
    }

    public function displayName(): string
    {
        return 'External API (TinyPNG / ShortPixel / Kraken.io)';
    }

    public function isAvailable(): bool
    {
        return $this->settings()->apiKey !== '';
    }

    public function supports(string $extension): bool
    {
        if ($extension === 'svg') {
            return true; // handled locally, never uploaded
        }
        return match ($this->settings()->apiService) {
            Settings::API_SHORTPIXEL,
            Settings::API_KRAKEN => in_array($extension, ['jpg', 'jpeg', 'png', 'gif'], true),
            default => in_array($extension, ['jpg', 'jpeg', 'png'], true), // TinyPNG
        };
    }

    public function compress(string $path, string $extension): CompressionResult
    {
        if ($extension === 'svg') {
            return $this->minifySvg($path)
                ? $this->ok('Minified SVG (local).')
                : $this->fail('No smaller SVG result.');
        }

        $settings = $this->settings();
        if ($settings->apiKey === '') {
            return $this->fail('No API key configured.');
        }

        try {
            return match ($settings->apiService) {
                Settings::API_SHORTPIXEL => $this->compressWithShortPixel($path),
                Settings::API_KRAKEN => $this->compressWithKraken($path),
                default => $this->compressWithTinyPng($path),
            };
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    private function compressWithTinyPng(string $path): CompressionResult
    {
        $client = Craft::createGuzzleClient(['http_errors' => false]);
        $bytes = @file_get_contents($path);
        if ($bytes === false) {
            return $this->fail('Could not read file for upload.');
        }

        $shrink = $client->post('https://api.tinify.com/shrink', [
            'auth' => ['api', $this->settings()->apiKey],
            'body' => $bytes,
        ]);

        $status = $shrink->getStatusCode();
        if ($status !== 201) {
            $body = Json::decodeIfJson((string) $shrink->getBody());
            $message = is_array($body) && isset($body['message']) ? $body['message'] : "HTTP {$status}";
            return $this->fail("TinyPNG: {$message}");
        }

        $location = $shrink->getHeaderLine('Location');
        if ($location === '') {
            return $this->fail('TinyPNG: no result URL returned.');
        }

        $download = $client->get($location, ['auth' => ['api', $this->settings()->apiKey]]);
        if ($download->getStatusCode() !== 200) {
            return $this->fail('TinyPNG: failed to download result.');
        }

        return @file_put_contents($path, (string) $download->getBody()) !== false
            ? $this->ok('TinyPNG.')
            : $this->fail('TinyPNG: could not write result.');
    }

    private function compressWithShortPixel(string $path): CompressionResult
    {
        $client = Craft::createGuzzleClient(['http_errors' => false]);

        $response = $client->post('https://api.shortpixel.com/v2/post-reducer.php', [
            'multipart' => [
                ['name' => 'key', 'contents' => $this->settings()->apiKey],
                ['name' => 'lossy', 'contents' => '1'],
                ['name' => 'wait', 'contents' => '25'],
                ['name' => 'convertto', 'contents' => ''],
                ['name' => 'file1', 'contents' => fopen($path, 'r'), 'filename' => basename($path)],
            ],
        ]);

        if ($response->getStatusCode() !== 200) {
            return $this->fail('ShortPixel: HTTP ' . $response->getStatusCode());
        }

        $data = Json::decodeIfJson((string) $response->getBody());
        if (!is_array($data) || $data === []) {
            return $this->fail('ShortPixel: unexpected response.');
        }

        // post-reducer returns a list of per-file result objects.
        $item = array_values($data)[0];
        $code = (int) ($item['Status']['Code'] ?? 0);
        if ($code !== 2) {
            $msg = $item['Status']['Message'] ?? "status {$code}";
            return $this->fail("ShortPixel: {$msg}");
        }

        $resultUrl = $item['LossyURL'] ?? $item['LosslessURL'] ?? null;
        if (!$resultUrl) {
            return $this->fail('ShortPixel: no result URL.');
        }

        $download = $client->get($resultUrl);
        if ($download->getStatusCode() !== 200) {
            return $this->fail('ShortPixel: failed to download result.');
        }

        return @file_put_contents($path, (string) $download->getBody()) !== false
            ? $this->ok('ShortPixel.')
            : $this->fail('ShortPixel: could not write result.');
    }

    private function compressWithKraken(string $path): CompressionResult
    {
        $settings = $this->settings();
        if ($settings->apiSecret === '') {
            return $this->fail('Kraken.io: no API secret configured.');
        }

        $client = Craft::createGuzzleClient(['http_errors' => false]);

        // Kraken takes a JSON `data` part (auth + options) alongside the file, and
        // with wait=true returns the optimised result synchronously.
        $params = Json::encode([
            'auth' => [
                'api_key' => $settings->apiKey,
                'api_secret' => $settings->apiSecret,
            ],
            'wait' => true,
            'lossy' => true,
        ]);

        $response = $client->post('https://api.kraken.io/v1/upload', [
            'multipart' => [
                ['name' => 'data', 'contents' => $params],
                ['name' => 'upload', 'contents' => fopen($path, 'r'), 'filename' => basename($path)],
            ],
        ]);

        if ($response->getStatusCode() !== 200) {
            return $this->fail('Kraken.io: HTTP ' . $response->getStatusCode());
        }

        $data = Json::decodeIfJson((string) $response->getBody());
        if (!is_array($data) || empty($data['success'])) {
            $msg = is_array($data) && isset($data['message']) ? $data['message'] : 'unexpected response';
            return $this->fail("Kraken.io: {$msg}");
        }

        $resultUrl = $data['kraked_url'] ?? null;
        if (!$resultUrl) {
            return $this->fail('Kraken.io: no result URL.');
        }

        $download = $client->get($resultUrl);
        if ($download->getStatusCode() !== 200) {
            return $this->fail('Kraken.io: failed to download result.');
        }

        return @file_put_contents($path, (string) $download->getBody()) !== false
            ? $this->ok('Kraken.io.')
            : $this->fail('Kraken.io: could not write result.');
    }
}

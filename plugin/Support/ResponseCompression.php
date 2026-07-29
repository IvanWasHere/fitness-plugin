<?php

namespace FitnessClub\Support;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * gzip for this plugin's REST responses (W4.2).
 *
 * The payloads a mobile client syncs are JSON, which compresses by roughly 80%.
 * On a phone with one bar that is the difference between a sync completing and a
 * sync timing out, and it costs a few milliseconds of CPU.
 *
 * ## Scoped to our namespace, and this is not caution for its own sake
 *
 * Compression belongs to the web server, and on most hosts it is already on. A
 * plugin that enables it globally is a plugin that double-compresses on the
 * hosts where it was configured properly — which produces a response no client
 * can read, on exactly the well-run servers you would least expect to break.
 *
 * So: our routes only, and only after establishing that nobody else is already
 * doing it. Every one of the checks below is a way that assumption can be wrong.
 *
 * ## Why not just tell people to configure their server
 *
 * Because they cannot always. Shared hosting frequently forbids editing the
 * server config, and the mobile API is precisely the surface where the payload
 * is large and the connection is poor. Doing it here when nothing else is means
 * the feature works on the hosts that need it most.
 */
final class ResponseCompression
{
    /** Below this, the gzip header costs more than the saving. */
    private const MIN_BYTES = 1024;

    /**
     * Start buffering if — and only if — it is safe to.
     *
     * Called from the REST dispatch path for our namespace.
     */
    public static function maybeStart(): void
    {
        if (!self::shouldCompress()) {
            return;
        }

        ob_start([self::class, 'compress']);
    }

    /**
     * The buffer callback. Returning the input unchanged is a valid "no".
     */
    public static function compress(string $buffer): string
    {
        if (strlen($buffer) < self::MIN_BYTES || headers_sent()) {
            return $buffer;
        }

        $encoded = gzencode($buffer, 6);

        // A failure here is not worth an error: the uncompressed body is
        // perfectly correct, merely larger.
        if (false === $encoded || strlen($encoded) >= strlen($buffer)) {
            return $buffer;
        }

        header('Content-Encoding: gzip');
        header('Content-Length: ' . strlen($encoded));
        // Without this, a shared cache can serve the gzipped bytes to a client
        // that never said it could read them.
        header('Vary: Accept-Encoding', false);

        return $encoded;
    }

    /**
     * Every reason not to, in the order they are cheapest to check.
     */
    private static function shouldCompress(): bool
    {
        if (!function_exists('gzencode')) {
            return false;
        }

        // The server is already doing it. Compressing again produces bytes that
        // no client can decode.
        if (ini_get('zlib.output_compression')) {
            return false;
        }

        foreach (ob_list_handlers() as $handler) {
            if (false !== stripos((string) $handler, 'gzhandler') || false !== stripos((string) $handler, 'zlib')) {
                return false;
            }
        }

        // Some other layer has already declared an encoding.
        foreach (headers_list() as $header) {
            if (0 === stripos($header, 'content-encoding:')) {
                return false;
            }
        }

        if (headers_sent()) {
            return false;
        }

        return self::clientAcceptsGzip();
    }

    private static function clientAcceptsGzip(): bool
    {
        if (!isset($_SERVER['HTTP_ACCEPT_ENCODING'])) {
            return false;
        }

        $accepted = strtolower(sanitize_text_field(wp_unslash($_SERVER['HTTP_ACCEPT_ENCODING'])));

        // `gzip;q=0` is a client explicitly refusing it, which is not the same
        // as not mentioning it — and a naive substring check reads it as a yes.
        if (preg_match('/gzip\s*;\s*q\s*=\s*0(?:\.0+)?(?![\d.])/', $accepted)) {
            return false;
        }

        return str_contains($accepted, 'gzip');
    }
}

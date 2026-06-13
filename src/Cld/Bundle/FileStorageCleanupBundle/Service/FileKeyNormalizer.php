<?php

declare(strict_types=1);

namespace Cld\Bundle\FileStorageCleanupBundle\Service;

/**
 * Normalizes and extracts Akeneo file storage keys.
 *
 * A standard key is "<x>/<y>/<z>/<w>/<40 hex chars>_<sanitized filename>" where the
 * 40-hex "uuid" is sha1(filename . microtime()) — an upload-time identifier, NOT a
 * content hash (see Akeneo\Tool\Component\FileStorage\PathGenerator). The shard prefix
 * is the first 4 chars of that uuid. PathGenerator sanitizes filenames to
 * [A-Za-z0-9_.], so a standard key never contains quotes, spaces or backslashes.
 *
 * The "bare key" (prefix stripped) is the canonical comparison form: both
 * akeneo_file_storage_file_info.file_key and the values embedded in raw_values JSON
 * carry the prefix, but normalizing both sides makes the match independent of it.
 */
class FileKeyNormalizer
{
    private const SHARD_PREFIX_PATTERN = '#^(?:[0-9a-f]/){4}#';
    private const BARE_KEY_PATTERN = '#^[0-9a-f]{40}_.#';

    /**
     * Strict extraction: optional shard prefix, 40-hex uuid, underscore, then any run of
     * characters that cannot terminate a JSON or PHP-serialized string. Capturing up to
     * the string boundary (instead of only the sanitized charset) guarantees a key with
     * unexpected filename characters is captured whole rather than silently truncated —
     * truncation would under-build the referenced set and wrongly flag live files.
     */
    private const STRICT_EXTRACTION_PATTERN = '#(?:(?:[0-9a-f]/){4})?([0-9a-f]{40}_[^"\'\\\\\s]+)#';

    /**
     * Loose probe used to cross-check strict extraction completeness: every occurrence
     * of a 40-hex-underscore token should yield exactly one strict match.
     */
    private const LOOSE_EXTRACTION_PATTERN = '#[0-9a-f]{40}_#';

    /** Strip the "x/y/z/w/" shard prefix, if present. */
    public function bareKey(string $key): string
    {
        return (string) preg_replace(self::SHARD_PREFIX_PATTERN, '', $key);
    }

    /** Whether a (bare or prefixed) key follows the standard PathGenerator format. */
    public function isStandardKey(string $key): bool
    {
        return 1 === preg_match(self::BARE_KEY_PATTERN, $this->bareKey($key));
    }

    /**
     * Extract all bare keys from a text blob (JSON raw_values, serialized snapshot...).
     *
     * @return array{keys: string[], strict_count: int, loose_count: int, suspicious_count: int}
     *         keys are unique bare keys. Two completeness signals the caller must treat
     *         as failures before trusting the referenced set:
     *         - loose_count > strict_count: a key-like token could not be captured at all
     *         - suspicious_count > 0: a captured key was terminated by whitespace instead
     *           of a string boundary, i.e. the real key may continue (truncation risk)
     */
    public function extract(string $blob): array
    {
        // JSON encoders may escape forward slashes; normalize before matching so the
        // shard prefix is recognized either way.
        if (str_contains($blob, '\\/')) {
            $blob = str_replace('\\/', '/', $blob);
        }

        $strict = preg_match_all(self::STRICT_EXTRACTION_PATTERN, $blob, $matches, PREG_OFFSET_CAPTURE);
        $loose = preg_match_all(self::LOOSE_EXTRACTION_PATTERN, $blob);

        $keys = [];
        $suspicious = 0;
        foreach ($matches[1] ?? [] as [$key, $offset]) {
            $keys[$key] = true;
            $terminator = $blob[$offset + strlen($key)] ?? '';
            // A key inside a JSON/serialized string ends at " ' or \; whitespace means
            // the string value continues beyond what we captured.
            if ('' !== $terminator && !in_array($terminator, ['"', "'", '\\'], true)) {
                ++$suspicious;
            }
        }

        return [
            'keys' => array_keys($keys),
            'strict_count' => (int) $strict,
            'loose_count' => (int) $loose,
            'suspicious_count' => $suspicious,
        ];
    }
}

<?php

namespace App\Support;

/**
 * Tolerant reader for the `attachments` JSON column on
 * workflows / tasks / approvals / contents / comments.
 *
 * Historical shape variance:
 *   - null
 *   - "[]" / "null" (legacy default)
 *   - json-encoded string (single encode — Eloquent `array` cast)
 *   - json-encoded string wrapped in another json-encoded string
 *     (double-encode bug fixed by 2026_07_29_164201_fix_double_encoded_attachments)
 *   - array of {name, url, type, ext?} entries
 *
 * Uploaded items historically had `type` set from the mime family
 * ('image' | 'video' | 'audio' | 'document' | 'file'). Google Drive
 * items — the new source added by the Drive integration — carry
 * `type: 'drive'` plus optional `drive_file_id` / `mime` / `size`
 * so drive-browser can round-trip them. The public partial
 * `livewire.partials.attachment-display` already renders `type: 'drive'`
 * as a Drive-branded link tile, so no template changes are needed.
 *
 * Every reader — Volt component, controller, notification builder —
 * MUST route through `Attachment::normalize()` before touching the
 * value. See MEMORY.md (madhyam-json-field-shape-normalizer) for the pattern.
 */
final class Attachment
{
    public const TYPE_IMAGE = 'image';

    public const TYPE_VIDEO = 'video';

    public const TYPE_AUDIO = 'audio';

    public const TYPE_DOCUMENT = 'document';

    public const TYPE_FILE = 'file';

    public const TYPE_DRIVE = 'drive';

    /**
     * Coerce any legacy value into a clean array of attachment records.
     * Guarantees each returned item has at least `name`, `url`, `type` keys.
     * Unrecognised entries are dropped, not thrown — attachments render
     * on many pages and one bad row must never break a whole modal.
     */
    public static function normalize(mixed $value): array
    {
        if ($value === null || $value === '' || $value === '[]' || $value === 'null') {
            return [];
        }

        // Unwrap potential double-encoding (matches the 2026_07_29 fix migration behaviour).
        while (is_string($value)) {
            $decoded = json_decode($value, true);
            if ($decoded === null) {
                return [];
            }
            $value = $decoded;
        }

        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (! is_array($item)) {
                continue;
            }
            $url = trim((string) ($item['url'] ?? ''));
            if ($url === '') {
                continue;
            }

            $type = strtolower((string) ($item['type'] ?? ''));
            // Legacy rows may not carry a type — infer from url.
            if ($type === '') {
                $type = self::inferType($url);
            }
            // Any drive.google.com url is a drive item regardless of stored type.
            if (str_contains($url, 'drive.google.com')) {
                $type = self::TYPE_DRIVE;
            }

            $clean = [
                'name' => trim((string) ($item['name'] ?? 'File')),
                'url' => $url,
                'type' => $type,
            ];

            // Preserve optional drive-specific keys so drive-browser can
            // round-trip the same row (rename/move/delete need drive_file_id).
            foreach (['drive_file_id', 'mime', 'size', 'ext', 'thumbnail'] as $optional) {
                if (isset($item[$optional]) && $item[$optional] !== '') {
                    $clean[$optional] = $item[$optional];
                }
            }

            $out[] = $clean;
        }

        return array_values($out);
    }

    /**
     * Build a Drive attachment record from Google Drive metadata. Used by
     * drive-attach picker and by any wire:call that persists Drive picks
     * into a workflow/task/approval/content JSON column.
     */
    public static function fromDrive(array $driveFile): array
    {
        return [
            'name' => $driveFile['name'] ?? 'File',
            'url' => $driveFile['webViewLink'] ?? $driveFile['url'] ?? '',
            'type' => self::TYPE_DRIVE,
            'drive_file_id' => $driveFile['id'] ?? $driveFile['drive_file_id'] ?? null,
            'mime' => $driveFile['mimeType'] ?? $driveFile['mime'] ?? null,
            'size' => isset($driveFile['size']) ? (int) $driveFile['size'] : null,
            'thumbnail' => $driveFile['thumbnailLink'] ?? $driveFile['thumbnail'] ?? null,
        ];
    }

    public static function isDrive(array $attachment): bool
    {
        return ($attachment['type'] ?? null) === self::TYPE_DRIVE
            || str_contains((string) ($attachment['url'] ?? ''), 'drive.google.com');
    }

    private static function inferType(string $url): string
    {
        $ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?: $url, PATHINFO_EXTENSION));

        return match (true) {
            in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp', 'ico'], true) => self::TYPE_IMAGE,
            in_array($ext, ['mp4', 'mov', 'avi', 'webm', 'mkv', 'm4v', 'flv'], true) => self::TYPE_VIDEO,
            in_array($ext, ['mp3', 'wav', 'ogg', 'aac', 'm4a', 'flac', 'wma'], true) => self::TYPE_AUDIO,
            in_array($ext, ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'csv', 'rtf', 'odt'], true) => self::TYPE_DOCUMENT,
            default => self::TYPE_FILE,
        };
    }
}

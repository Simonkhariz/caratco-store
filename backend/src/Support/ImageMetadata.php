<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Removes camera metadata (GPS location, device, owner, comments, editing history) from an
 * uploaded JPEG, PNG or WebP without re-encoding it, so the picture itself is untouched. The
 * browser normally does this already when it resizes a photo (lib/image-optimize.ts); this covers
 * uploads that arrive as the original file. Works on the file's structure, so no image library
 * (GD, Imagick) is needed.
 *
 * The only thing kept is a photo's rotation (EXIF Orientation), written back as a minimal EXIF
 * block: without it a portrait phone photo would be shown sideways.
 */
final class ImageMetadata
{
    /**
     * Strip metadata from $path in place. Returns true when the file was rewritten, false when
     * there was nothing to remove or the file could not be parsed (it is then left as it was).
     */
    public static function strip(string $path, string $mime): bool
    {
        $data = @file_get_contents($path);
        if ($data === false || $data === '') {
            return false;
        }
        $clean = match ($mime) {
            'image/jpeg' => self::stripJpeg($data),
            'image/png' => self::stripPng($data),
            'image/webp' => self::stripWebp($data),
            default => null,
        };
        if ($clean === null || $clean === $data || @getimagesizefromstring($clean) === false) {
            return false;
        }
        return @file_put_contents($path, $clean, LOCK_EX) === strlen($clean);
    }

    /** JPEG: drop APP1 (EXIF, XMP), APP13 (IPTC/Photoshop) and comments; keep everything else. */
    private static function stripJpeg(string $data): ?string
    {
        if (!str_starts_with($data, "\xFF\xD8")) {
            return null;
        }
        $length = strlen($data);
        $kept = [];
        $orientation = 1;
        $pos = 2;
        while ($pos + 4 <= $length) {
            if ($data[$pos] !== "\xFF") {
                return null;
            }
            $marker = ord($data[$pos + 1]);
            if ($marker === 0xFF) { // fill byte
                $pos++;
                continue;
            }
            if ($marker === 0xDA) { // start of scan: the compressed picture follows, unchanged
                $kept[] = substr($data, $pos);
                break;
            }
            if ($marker === 0x01 || ($marker >= 0xD0 && $marker <= 0xD7)) {
                $kept[] = substr($data, $pos, 2);
                $pos += 2;
                continue;
            }
            $segmentLength = unpack('n', substr($data, $pos + 2, 2))[1];
            if ($segmentLength < 2 || $pos + 2 + $segmentLength > $length) {
                return null;
            }
            $segment = substr($data, $pos, 2 + $segmentLength);
            $pos += 2 + $segmentLength;

            if ($marker === 0xE1) {
                $payload = substr($segment, 4);
                if (str_starts_with($payload, "Exif\0\0")) {
                    $orientation = self::tiffOrientation(substr($payload, 6)) ?? $orientation;
                }
                continue;
            }
            if ($marker === 0xED || $marker === 0xFE) {
                continue;
            }
            $kept[] = $segment;
        }
        if ($kept === [] || !str_starts_with((string) end($kept), "\xFF\xDA")) {
            return null;
        }

        if ($orientation !== 1) {
            $exif = "Exif\0\0" . self::orientationTiff($orientation);
            $app1 = "\xFF\xE1" . pack('n', strlen($exif) + 2) . $exif;
            // After a leading JFIF (APP0) segment, where readers expect it.
            $first = $kept[0] ?? '';
            if (str_starts_with($first, "\xFF\xE0")) {
                array_splice($kept, 1, 0, [$app1]);
            } else {
                array_unshift($kept, $app1);
            }
        }
        return "\xFF\xD8" . implode('', $kept);
    }

    /** PNG: drop text, time and EXIF chunks; keep image, colour and transparency chunks. */
    private static function stripPng(string $data): ?string
    {
        $signature = "\x89PNG\r\n\x1a\n";
        if (!str_starts_with($data, $signature)) {
            return null;
        }
        $drop = ['tEXt', 'zTXt', 'iTXt', 'eXIf', 'tIME'];
        $length = strlen($data);
        $out = $signature;
        $pos = 8;
        $sawEnd = false;
        while ($pos + 12 <= $length) {
            $chunkLength = unpack('N', substr($data, $pos, 4))[1];
            $type = substr($data, $pos + 4, 4);
            $total = 12 + $chunkLength;
            if ($pos + $total > $length) {
                return null;
            }
            if (!in_array($type, $drop, true)) {
                $out .= substr($data, $pos, $total);
            }
            $pos += $total;
            if ($type === 'IEND') {
                $sawEnd = true;
                break;
            }
        }
        return $sawEnd ? $out : null;
    }

    /** WebP: drop EXIF and XMP chunks (a rotation is kept as a minimal EXIF chunk). */
    private static function stripWebp(string $data): ?string
    {
        if (strlen($data) < 12 || !str_starts_with($data, 'RIFF') || substr($data, 8, 4) !== 'WEBP') {
            return null;
        }
        $length = strlen($data);
        $chunks = [];
        $orientation = 1;
        $pos = 12;
        while ($pos + 8 <= $length) {
            $fourcc = substr($data, $pos, 4);
            $size = unpack('V', substr($data, $pos + 4, 4))[1];
            $padded = $size + ($size & 1);
            if ($pos + 8 + $size > $length) {
                return null;
            }
            $payload = substr($data, $pos + 8, $size);
            $pos += 8 + $padded;
            if ($fourcc === 'EXIF') {
                $tiff = str_starts_with($payload, "Exif\0\0") ? substr($payload, 6) : $payload;
                $orientation = self::tiffOrientation($tiff) ?? $orientation;
                continue;
            }
            if ($fourcc === 'XMP ') {
                continue;
            }
            $chunks[] = [$fourcc, $payload];
        }
        if ($chunks === []) {
            return null;
        }

        $hasVp8x = $chunks[0][0] === 'VP8X';
        if ($hasVp8x) {
            $flags = ord($chunks[0][1][0]) & ~0x0C; // clear the EXIF (0x08) and XMP (0x04) flags
            if ($orientation !== 1) {
                $flags |= 0x08;
            }
            $chunks[0][1][0] = chr($flags);
        }
        if ($orientation !== 1 && $hasVp8x) {
            $chunks[] = ['EXIF', self::orientationTiff($orientation)];
        }

        $body = 'WEBP';
        foreach ($chunks as [$fourcc, $payload]) {
            $body .= $fourcc . pack('V', strlen($payload)) . $payload . (strlen($payload) & 1 ? "\0" : '');
        }
        return 'RIFF' . pack('V', strlen($body)) . $body;
    }

    /** The Orientation tag (1-8) from a TIFF/EXIF block, or null when absent or unreadable. */
    private static function tiffOrientation(string $tiff): ?int
    {
        if (strlen($tiff) < 8) {
            return null;
        }
        $order = substr($tiff, 0, 2);
        if ($order !== 'II' && $order !== 'MM') {
            return null;
        }
        $u16 = static fn (int $at): int => unpack($order === 'II' ? 'v' : 'n', substr($tiff, $at, 2))[1];
        $u32 = static fn (int $at): int => unpack($order === 'II' ? 'V' : 'N', substr($tiff, $at, 4))[1];
        $ifd = $u32(4);
        if ($ifd + 2 > strlen($tiff)) {
            return null;
        }
        $entries = $u16($ifd);
        for ($i = 0; $i < $entries; $i++) {
            $entry = $ifd + 2 + $i * 12;
            if ($entry + 12 > strlen($tiff)) {
                return null;
            }
            if ($u16($entry) === 0x0112) {
                $value = $u16($entry + 8);
                return $value >= 1 && $value <= 8 ? $value : null;
            }
        }
        return null;
    }

    /** A minimal big-endian TIFF block holding only the Orientation tag. */
    private static function orientationTiff(int $orientation): string
    {
        return "MM\0\x2A" . pack('N', 8)      // header, first IFD at offset 8
            . pack('n', 1)                     // one entry
            . pack('nnN', 0x0112, 3, 1)        // Orientation, SHORT, count 1
            . pack('n', $orientation) . "\0\0" // value, padded to 4 bytes
            . pack('N', 0);                    // no next IFD
    }
}

<?php

declare(strict_types=1);

namespace App\Prescriptions;

use App\Exceptions\ApiProblem;

/**
 * JPEG and PNG uploads must decode. Dimensions are taken from the header and
 * rejected before GD allocates a bitmap. The bitmap is discarded immediately.
 */
final class ImageStructureValidator
{
    public function assertJpeg(string $bytes): void
    {
        $length = strlen($bytes);

        if ($length < 4 || $bytes[0] !== "\xFF" || $bytes[1] !== "\xD8") {
            $this->reject('The prescription file is not a readable JPEG.');
        }

        $offset = 2;
        $sawFrame = false;
        $sawScan = false;
        $width = 0;
        $height = 0;

        while ($offset < $length) {
            if ($bytes[$offset] !== "\xFF") {
                $this->reject('The prescription file is not a readable JPEG.');
            }

            do {
                $offset++;

                if ($offset >= $length) {
                    $this->reject('The prescription image is truncated.');
                }
            } while ($bytes[$offset] === "\xFF");

            $marker = ord($bytes[$offset]);
            $offset++;

            if ($marker === 0xD9) {
                if (! $sawFrame || ! $sawScan || $offset !== $length || $width < 1 || $height < 1 || $width > PrescriptionFileInspector::MAX_EDGE || $height > PrescriptionFileInspector::MAX_EDGE) {
                    $this->reject('The prescription file is not a readable JPEG.');
                }

                $this->assertDecodable($bytes, $width, $height);

                return;
            }

            if ($marker === 0x00 || $marker === 0xD8 || $marker === 0x01 || ($marker >= 0xD0 && $marker <= 0xD7)) {
                if ($marker === 0x01 || ($marker >= 0xD0 && $marker <= 0xD7)) {
                    continue;
                }

                $this->reject('The prescription file is not a readable JPEG.');
            }

            if ($offset + 1 >= $length) {
                $this->reject('The prescription image is truncated.');
            }

            $segmentLength = (ord($bytes[$offset]) << 8) | ord($bytes[$offset + 1]);

            if ($segmentLength < 2 || $offset + $segmentLength > $length) {
                $this->reject('The prescription image is truncated.');
            }

            if ($this->isStartOfFrame($marker)) {
                if ($segmentLength < 7) {
                    $this->reject('The prescription file is not a readable JPEG.');
                }

                $height = (ord($bytes[$offset + 3]) << 8) | ord($bytes[$offset + 4]);
                $width = (ord($bytes[$offset + 5]) << 8) | ord($bytes[$offset + 6]);
                $sawFrame = true;
            }

            if ($marker === 0xDA) {
                $sawScan = true;
                $offset += $segmentLength;
                $offset = $this->skipScan($bytes, $offset, $length);

                continue;
            }

            $offset += $segmentLength;
        }

        $this->reject('The prescription image is truncated.');
    }

    public function assertPng(string $bytes): void
    {
        if (! str_starts_with($bytes, "\x89PNG\r\n\x1a\n")) {
            $this->reject('The prescription file is not a readable PNG.');
        }

        $length = strlen($bytes);
        $offset = 8;
        $sawHeader = false;
        $sawImage = false;
        $pixelWidth = 0;
        $pixelHeight = 0;

        while ($offset + 12 <= $length) {
            $chunkLength = unpack('N', substr($bytes, $offset, 4));
            $size = is_array($chunkLength) ? (int) $chunkLength[1] : -1;

            if ($size < 0 || $offset + 12 + $size > $length) {
                $this->reject('The prescription image is truncated.');
            }

            $type = substr($bytes, $offset + 4, 4);
            $data = substr($bytes, $offset + 8, $size);
            $stored = unpack('N', substr($bytes, $offset + 8 + $size, 4));
            $expected = crc32($type.$data);

            if (! is_array($stored) || (($expected & 0xFFFFFFFF) !== (int) $stored[1]) || preg_match('/^[A-Za-z]{4}$/', $type) !== 1) {
                $this->reject('The prescription file is not a readable PNG.');
            }

            if ($type === 'IHDR') {
                if ($sawHeader || $size !== 13) {
                    $this->reject('The prescription file is not a readable PNG.');
                }

                $width = unpack('N', substr($data, 0, 4));
                $height = unpack('N', substr($data, 4, 4));
                $pixelWidth = is_array($width) ? (int) $width[1] : 0;
                $pixelHeight = is_array($height) ? (int) $height[1] : 0;

                if ($pixelWidth < 1 || $pixelHeight < 1 || $pixelWidth > PrescriptionFileInspector::MAX_EDGE || $pixelHeight > PrescriptionFileInspector::MAX_EDGE) {
                    $this->reject('The prescription image exceeds the supported dimensions.');
                }

                $sawHeader = true;
            } elseif ($type === 'IDAT') {
                if (! $sawHeader) {
                    $this->reject('The prescription file is not a readable PNG.');
                }

                if ($size > 0) {
                    $sawImage = true;
                }
            } elseif (! $sawHeader) {
                $this->reject('The prescription file is not a readable PNG.');
            }

            $offset += 12 + $size;

            if ($type === 'IEND') {
                if ($size !== 0 || $offset !== $length || ! $sawImage) {
                    $this->reject('The prescription file is not a readable PNG.');
                }

                $this->assertDecodable($bytes, $pixelWidth, $pixelHeight);

                return;
            }
        }

        $this->reject('The prescription image is truncated.');
    }

    private function skipScan(string $bytes, int $offset, int $length): int
    {
        while ($offset < $length) {
            if ($bytes[$offset] !== "\xFF") {
                $offset++;

                continue;
            }

            $markerAt = $offset;
            $offset++;

            if ($offset >= $length) {
                $this->reject('The prescription image is truncated.');
            }

            $next = ord($bytes[$offset]);

            if ($next === 0x00 || ($next >= 0xD0 && $next <= 0xD7)) {
                $offset++;

                continue;
            }

            return $markerAt;
        }

        $this->reject('The prescription image is truncated.');
    }

    private function isStartOfFrame(int $marker): bool
    {
        return in_array($marker, [0xC0, 0xC1, 0xC2, 0xC3, 0xC5, 0xC6, 0xC7, 0xC9, 0xCA, 0xCB, 0xCD, 0xCE, 0xCF], true);
    }

    private function assertDecodable(string $bytes, int $width, int $height): void
    {
        if ($width < 1 || $height < 1 || $height > intdiv(PrescriptionFileInspector::MAX_PIXELS, $width)) {
            $this->reject('The prescription image exceeds the supported dimensions.');
        }

        if (! function_exists('imagecreatefromstring')) {
            $this->reject('The prescription image could not be read.');
        }

        $image = @imagecreatefromstring($bytes);

        if ($image === false) {
            $this->reject('The prescription image could not be read.');
        }

        $decodedWidth = imagesx($image);
        $decodedHeight = imagesy($image);
        imagedestroy($image);

        if ($decodedWidth !== $width || $decodedHeight !== $height) {
            $this->reject('The prescription image could not be read.');
        }
    }

    private function reject(string $message): never
    {
        throw new ApiProblem('invalid_prescription_file', $message, 422);
    }
}

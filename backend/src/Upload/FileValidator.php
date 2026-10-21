<?php

namespace App\Upload;

use App\Http\ApiException;

class FileValidator
{
    private const IMAGE_MIMES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    private const IMAGE_MAX_BYTES = 5 * 1024 * 1024;
    private const PDF_MAX_BYTES = 50 * 1024 * 1024;

    public static function validateImage(array $file): string
    {
        self::assertUploadOk($file);
        if ($file['size'] > self::IMAGE_MAX_BYTES) {
            throw new ApiException('File too large (max 5MB)', 400, 'invalid_file');
        }
        $mime = self::detectMime($file['tmp_name']);
        if (!isset(self::IMAGE_MIMES[$mime])) {
            throw new ApiException('File must be JPEG, PNG, or WEBP', 400, 'invalid_file');
        }
        return self::IMAGE_MIMES[$mime];
    }

    public static function validateImageOrPdf(array $file): string
    {
        self::assertUploadOk($file);
        $mime = self::detectMime($file['tmp_name']);
        if ($mime === 'application/pdf') {
            if ($file['size'] > self::PDF_MAX_BYTES) {
                throw new ApiException('File too large (max 50MB)', 400, 'invalid_file');
            }
            return 'pdf';
        }
        if (isset(self::IMAGE_MIMES[$mime])) {
            if ($file['size'] > self::IMAGE_MAX_BYTES) {
                throw new ApiException('File too large (max 5MB)', 400, 'invalid_file');
            }
            return self::IMAGE_MIMES[$mime];
        }
        throw new ApiException('File must be JPEG, PNG, WEBP, or PDF', 400, 'invalid_file');
    }

    public static function validatePdf(array $file): string
    {
        self::assertUploadOk($file);
        if ($file['size'] > self::PDF_MAX_BYTES) {
            throw new ApiException('File too large (max 50MB)', 400, 'invalid_file');
        }
        $mime = self::detectMime($file['tmp_name']);
        if ($mime !== 'application/pdf') {
            throw new ApiException('File must be a PDF', 400, 'invalid_file');
        }
        return 'pdf';
    }

    private static function assertUploadOk(array $file): void
    {
        if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            throw new ApiException('No file uploaded', 400, 'invalid_file');
        }
    }

    private static function detectMime(string $path): string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $path);
        finfo_close($finfo);
        return $mime ?: 'application/octet-stream';
    }
}

<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Private scratch files for provider downloads and renders. Callers delete
 * them once the result is in permanent storage.
 */
class TemporaryFiles
{
    public static function directory(): string
    {
        $directory = storage_path('app/private/tmp');

        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }

        return $directory;
    }

    /**
     * A new, unique path with the given extension (letters and digits only).
     */
    public static function path(string $extension): string
    {
        $extension = preg_replace('/[^a-z0-9]/', '', strtolower($extension)) ?: 'bin';

        return self::directory().DIRECTORY_SEPARATOR.Str::uuid()->toString().'.'.$extension;
    }

    public static function delete(?string $path): void
    {
        if ($path !== null && is_file($path) && str_starts_with(realpath($path) ?: '', realpath(self::directory()) ?: "\0")) {
            @unlink($path);
        }
    }
}

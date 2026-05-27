<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * Validates that an uploaded file's bytes match one of the allowed image
 * formats — `mimes:` only checks the client-declared MIME / extension and
 * can be spoofed. Backed by `exif_imagetype()` which reads the file
 * signature directly. M10 / L3 audit fix.
 *
 *   new RealImageContent(['png', 'jpeg'])
 *
 * Empty / nullable uploads pass through (combine with `required` if you
 * want to enforce presence).
 */
class RealImageContent implements ValidationRule
{
    /** @var array<int, int> exif_imagetype IMAGETYPE_* constants */
    private array $allowed;

    /**
     * @param  array<int, string> $formats  e.g. ['png','jpeg','gif']
     */
    public function __construct(array $formats)
    {
        $map = [
            'png'  => IMAGETYPE_PNG,
            'jpg'  => IMAGETYPE_JPEG,
            'jpeg' => IMAGETYPE_JPEG,
            'gif'  => IMAGETYPE_GIF,
            'webp' => IMAGETYPE_WEBP,
        ];
        $this->allowed = array_values(array_unique(array_map(
            fn (string $f) => $map[strtolower($f)] ?? -1,
            $formats,
        )));
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null) return;
        if (! $value instanceof UploadedFile) {
            $fail('The :attribute must be an uploaded file.');
            return;
        }
        $path = $value->getRealPath();
        if ($path === false || ! is_readable($path)) {
            $fail('The :attribute could not be read for validation.');
            return;
        }
        // Prefer exif_imagetype (faster + reads only the file signature) but
        // fall back to getimagesize when the Exif extension isn't installed
        // (common on minimal Windows PHP builds).
        $type = function_exists('exif_imagetype')
            ? @exif_imagetype($path)
            : (($info = @getimagesize($path)) !== false ? ($info[2] ?? false) : false);
        if ($type === false || ! in_array($type, $this->allowed, true)) {
            $fail('The :attribute is not a valid image of the expected format.');
        }
    }
}

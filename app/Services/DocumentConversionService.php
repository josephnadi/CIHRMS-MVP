<?php

namespace App\Services;

use App\Exceptions\ConversionNotSupportedException;
use App\Models\DocumentVersion;
use Illuminate\Support\Facades\Storage;

class DocumentConversionService
{
    private const DISK = 'local';

    public function __construct(private DocumentRenderService $render) {}

    /**
     * Convert a version to the requested format. Returns absolute output path.
     *
     * Supported v1: image → pdf
     * Unsupported v1: docx → pdf, pdf → docx, anything else
     */
    public function convert(DocumentVersion $version, string $to): string
    {
        $to = strtolower($to);

        if ($to !== 'pdf') {
            throw ConversionNotSupportedException::forFormat($version->mime, $to);
        }

        if ($version->mime === 'application/pdf') {
            return Storage::disk(self::DISK)->path($version->storage_path);
        }

        if (str_starts_with($version->mime, 'image/')) {
            return $this->render->imageToPdf(Storage::disk(self::DISK)->path($version->storage_path));
        }

        // DOCX / other office formats — defer
        throw ConversionNotSupportedException::forFormat($version->mime, $to);
    }
}

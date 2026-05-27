<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\EmployeeDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Authorised streaming of employee files that live on the private `local`
 * disk after the H10 audit fix. Both routes are `signed` so links must be
 * minted server-side via URL::temporarySignedRoute (15-min default TTL) —
 * raw `/storage/...` URLs no longer work because the files are off the
 * public symlink.
 */
class EmployeeFileController extends Controller
{
    /** Stream the employee's avatar. Signed link + ownership policy. */
    public function avatar(Request $request, Employee $employee): StreamedResponse
    {
        $this->authorize('view', $employee);
        abort_unless($employee->avatar_path, 404);
        abort_unless(Storage::disk('local')->exists($employee->avatar_path), 404);

        return Storage::disk('local')->response($employee->avatar_path);
    }

    /** Stream an employee document. Signed link + ownership policy. */
    public function document(Request $request, Employee $employee, EmployeeDocument $document): StreamedResponse
    {
        $this->authorize('view', $employee);
        abort_unless($document->employee_id === $employee->id, 404);
        abort_unless($document->file_path && Storage::disk('local')->exists($document->file_path), 404);

        return Storage::disk('local')->download(
            $document->file_path,
            ($document->title ?: 'document') . $this->guessExtension($document->mime_type),
        );
    }

    private function guessExtension(?string $mime): string
    {
        return match ($mime) {
            'application/pdf'  => '.pdf',
            'image/jpeg'       => '.jpg',
            'image/png'        => '.png',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => '.docx',
            default            => '',
        };
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Profile\StoreProfileDocumentRequest;
use App\Http\Requests\Profile\UpdateProfileDocumentRequest;
use App\Models\EmployeeDocument;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Self-service "My Documents" in the profile portal. An employee may upload,
 * rename, replace and delete the documents THEY uploaded; documents HR placed
 * on their file (uploaded_by !== self) are download-only. Files live on the
 * private 'local' disk and are streamed through download() — never the public
 * /storage symlink.
 */
class ProfileDocumentController extends Controller
{
    public function store(StoreProfileDocumentRequest $request): RedirectResponse
    {
        $employee = $request->user()->employee;

        $path = $request->file('document')->store('employee-documents', 'local');
        $employee->documents()->create([
            'uploaded_by' => $request->user()->id,
            'title'       => $request->validated('title'),
            'file_path'   => $path,
            'mime_type'   => $request->file('document')->getMimeType(),
        ]);

        return back()->with('success', 'Document uploaded.');
    }

    public function update(UpdateProfileDocumentRequest $request, EmployeeDocument $document): RedirectResponse
    {
        // Ownership (own employee + own upload) is enforced by the request's authorize().
        $data = ['title' => $request->validated('title')];

        if ($request->hasFile('document')) {
            Storage::disk('local')->delete($document->file_path);
            $data['file_path'] = $request->file('document')->store('employee-documents', 'local');
            $data['mime_type'] = $request->file('document')->getMimeType();
        }

        $document->update($data);

        return back()->with('success', 'Document updated.');
    }

    public function destroy(Request $request, EmployeeDocument $document): RedirectResponse
    {
        $this->assertOwnUpload($request, $document);

        Storage::disk('local')->delete($document->file_path);
        $document->delete();

        return back()->with('success', 'Document deleted.');
    }

    public function download(Request $request, EmployeeDocument $document): StreamedResponse
    {
        $employee = $request->user()->employee;
        // Employees may read ANY document on their own file (incl. HR-uploaded).
        abort_unless($employee && $document->employee_id === $employee->id, 404);
        abort_unless($document->file_path && Storage::disk('local')->exists($document->file_path), 404);

        return Storage::disk('local')->download(
            $document->file_path,
            ($document->title ?: 'document') . $this->extensionFor($document->mime_type),
        );
    }

    /** Update/delete are restricted to the employee's OWN uploads. */
    private function assertOwnUpload(Request $request, EmployeeDocument $document): void
    {
        $user     = $request->user();
        $employee = $user?->employee;

        abort_unless(
            $employee
            && $document->employee_id === $employee->id
            && $document->uploaded_by === $user->id,
            403,
        );
    }

    private function extensionFor(?string $mime): string
    {
        return match ($mime) {
            'application/pdf' => '.pdf',
            'image/jpeg'      => '.jpg',
            'image/png'       => '.png',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => '.docx',
            default           => '',
        };
    }
}

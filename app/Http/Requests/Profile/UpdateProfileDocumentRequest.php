<?php

declare(strict_types=1);

namespace App\Http\Requests\Profile;

use App\Models\EmployeeDocument;
use Illuminate\Foundation\Http\FormRequest;

class UpdateProfileDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user     = $this->user();
        $employee = $user?->employee;
        $document = $this->route('document');

        // Only the employee's OWN uploads (on their own file) are editable.
        return $document instanceof EmployeeDocument
            && $employee !== null
            && $document->employee_id === $employee->id
            && $document->uploaded_by === $user->id;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            // Optional: present only when replacing the underlying file.
            'document' => ['nullable', 'file', 'max:10240', 'mimes:pdf,doc,docx,jpg,jpeg,png'],
        ];
    }
}

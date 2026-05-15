<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiAssistantController extends Controller
{
    public function summary(Request $request): JsonResponse
    {
        $data = $request->validate([
            'employee_id' => ['required', 'exists:employees,id'],
            'prompt' => ['nullable', 'string', 'max:1000'],
        ]);

        $employee = Employee::with('department')->findOrFail($data['employee_id']);
        $focus = $data['prompt'] ?? 'overall profile';

        // MVP-friendly AI placeholder that can be swapped with an LLM provider.
        $summary = sprintf(
            'AI Summary (%s): %s works as %s in %s. Status: %s. Recommended next action: schedule a manager check-in and review leave balance.',
            $focus,
            $employee->employee_no,
            $employee->position,
            $employee->department?->name ?? 'No Department',
            $employee->status
        );

        return response()->json(['summary' => $summary]);
    }
}

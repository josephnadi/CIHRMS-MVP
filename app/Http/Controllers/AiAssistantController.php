<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Services\Ai\EmployeeSummaryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class AiAssistantController extends Controller
{
    public function __construct(private readonly EmployeeSummaryService $summaries) {}

    public function summary(Request $request): JsonResponse
    {
        $data = $request->validate([
            'employee_id' => ['required', 'exists:employees,id'],
            'prompt'      => ['nullable', 'string', 'max:1000'],
        ]);

        $employee = Employee::with('department')->findOrFail($data['employee_id']);

        try {
            $reply = $this->summaries->summarise($employee, $data['prompt'] ?? null);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'summary' => null,
                'error'   => 'AI assistant is currently unavailable. Please try again later.',
            ], 503);
        }

        return response()->json([
            'summary' => $reply->text,
            'model'   => $reply->model,
            'usage'   => [
                'input_tokens'           => $reply->inputTokens,
                'output_tokens'          => $reply->outputTokens,
                'cache_read_tokens'      => $reply->cacheReadTokens,
                'cache_creation_tokens'  => $reply->cacheCreationTokens,
            ],
        ]);
    }
}

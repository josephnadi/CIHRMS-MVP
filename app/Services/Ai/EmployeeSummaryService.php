<?php

namespace App\Services\Ai;

use App\Models\Employee;
use App\Services\Ai\Contracts\LlmProvider;
use App\Services\Ai\Contracts\LlmResponse;

/**
 * Builds the prompts for the "AI Employee Summary" endpoint and hands
 * them to the configured LlmProvider.
 *
 * Prompt layout (must stay stable to keep cache_read_input_tokens > 0):
 *   • SYSTEM   = constant instruction block (this file)
 *   • CACHED   = constant schema/template explaining the payload shape
 *   • USER     = per-request JSON envelope (redacted employee + the focus prompt)
 *
 * Only USER is allowed to vary between calls; SYSTEM and CACHED form the
 * cached prefix. If you need to change either, do it in this file so the
 * cache simply rebuilds once.
 */
final class EmployeeSummaryService
{
    public function __construct(
        private readonly LlmProvider $llm,
        private readonly PiiRedactor $redactor,
    ) {}

    public function summarise(Employee $employee, ?string $focus = null): LlmResponse
    {
        $employee->loadMissing(['department', 'manager']);

        $payload = [
            'employee'        => $this->redactor->redact($employee),
            'focus'           => $focus ?: 'overall profile',
            'requested_at'    => now()->toIso8601String(),
        ];

        $userPrompt = "Generate the summary for this employee payload:\n\n"
            .json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return $this->llm->complete(
            systemPrompt:   self::SYSTEM_PROMPT,
            cachedContext:  self::CACHED_CONTEXT,
            userPrompt:     $userPrompt,
        );
    }

    /**
     * Stable, ~700-char instruction. Treated as a cache breakpoint.
     * Keep edits rare — every byte change invalidates the cached prefix.
     */
    private const SYSTEM_PROMPT = <<<'TXT'
    You are the AI assistant embedded in CIHRMS, the Chartered Institute of Human
    Resource Management's HRMS for Ghanaian MDAs. You help HR officers triage
    employee records by producing a short, professional one-paragraph briefing.

    Hard rules — these are not optional:
      1. NEVER invent facts. Only summarise what is in the payload. If a field
         is missing, say so plainly ("No department on record") instead of
         guessing.
      2. NEVER ask for, infer, or repeat back any personally identifiable
         information beyond what the payload already contains. The payload has
         already been redacted; treat it as the only truth about the employee.
      3. Output 3–5 sentences. No bullet points. No markdown. No preface like
         "Sure! Here is...". Just the briefing.
      4. End with a single, concrete next action for the HR officer (e.g.
         "Schedule a check-in to discuss the open leave balance.").
      5. Tone: professional, neutral, Ghana public-service register.
    TXT;

    /**
     * Stable schema/template documenting the payload shape. Second cache
     * breakpoint — sits between SYSTEM and the per-call USER message.
     * Verbose on purpose: the redundancy pushes us over Haiku 4.5's minimum
     * cacheable-prefix size, so subsequent calls actually score cache hits.
     */
    private const CACHED_CONTEXT = <<<'TXT'
    The user message will always be a JSON object with this exact shape:

    {
      "employee": {
        "employee_no":   "<string, e.g. 'EMP-0042'>",
        "position":      "<string job title, e.g. 'Senior Accountant'>",
        "department":    "<string department name or null>",
        "status":        "<one of: active | on_leave | suspended | terminated | resigned>",
        "hire_date":     "<YYYY-MM (month precision) or null>",
        "tenure_years":  "<float, fractional years of service, or null>",
        "gender":        "<string or null>",
        "has_manager":   "<boolean: true if a line manager is on record>"
      },
      "focus":           "<string: what the HR officer asked you to focus on>",
      "requested_at":    "<ISO-8601 timestamp>"
    }

    Field rules you must respect when summarising:
      • employee_no is the only stable identifier you may quote verbatim.
      • department may be null — say "No department on record" in that case.
      • status maps to plain English: 'active' → "currently active",
        'on_leave' → "on approved leave", 'suspended' → "on suspension",
        'terminated' / 'resigned' → "no longer with the institute".
      • tenure_years rounds to one decimal when you mention it.
      • focus narrows the briefing. If focus == 'overall profile', cover role,
        tenure, status. If focus mentions 'leave', 'performance', 'discipline',
        'training', or 'benefits', tilt the briefing accordingly but still keep
        it grounded in the payload.
      • has_manager == false is itself worth surfacing — recommend assigning
        a line manager as the next action.

    Reminder: redact-by-construction. The payload deliberately omits salary,
    bank details, national ID, SSNIT, TIN, phone, address, emergency contacts,
    and date-of-birth. Do not ask for them. Do not pretend you have them.
    TXT;
}

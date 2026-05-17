<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\InboundSms;
use App\Models\SmsMessage;
use App\Models\StaffPhonePin;
use App\Services\Messaging\Sms\SmsDispatcher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;

class MessagingController extends Controller
{
    public function __construct(private readonly SmsDispatcher $sms) {}

    public function index(Request $request): Response
    {
        abort_unless($request->user()->hasPermission('messaging.view'), 403);

        $messages = SmsMessage::query()
            ->with('trigger:id,name')
            ->when($request->status,   fn ($q, $v) => $q->where('status', $v))
            ->when($request->to_phone, fn ($q, $v) => $q->where('to_phone', 'like', "%{$v}%"))
            ->latest()
            ->paginate(40)
            ->withQueryString();

        $inbound = InboundSms::latest('received_at')->limit(30)->get();

        $stats = [
            'sent_today'      => SmsMessage::where('status', 'sent')->whereDate('sent_at', today())->count(),
            'delivered_today' => SmsMessage::where('status', 'delivered')->whereDate('delivered_at', today())->count(),
            'failed_today'    => SmsMessage::where('status', 'failed')->whereDate('updated_at', today())->count(),
            'inbound_today'   => InboundSms::whereDate('received_at', today())->count(),
            'pin_enrolled'    => StaffPhonePin::count(),
        ];

        return Inertia::render('Messaging/SmsHistory', [
            'messages'     => $messages,
            'inbound'      => $inbound,
            'stats'        => $stats,
            'filters'      => $request->only(['status', 'to_phone']),
            'activeModule' => 'messaging',
        ]);
    }

    /** HR-side: send a one-off SMS to any phone. */
    public function send(Request $request): RedirectResponse
    {
        abort_unless($request->user()->hasPermission('messaging.send'), 403);

        $data = $request->validate([
            'to_phone' => ['required', 'string', 'max:32'],
            'body'     => ['required', 'string', 'max:1600'],
        ]);

        $this->sms->send(
            toPhone:     $data['to_phone'],
            body:        $data['body'],
            triggeredBy: $request->user(),
        );

        return back()->with('success', 'SMS queued.');
    }

    /**
     * Issue or rotate the 4-digit PIN that gates the employee's USSD
     * self-service path. PIN is generated server-side, SMS'd to the
     * registered phone, only the bcrypt hash is persisted.
     */
    public function issuePin(Request $request): RedirectResponse
    {
        abort_unless($request->user()->hasPermission('messaging.manage'), 403);

        $data = $request->validate([
            'employee_id'   => ['required', 'integer', 'exists:employees,id'],
            'phone'         => ['required', 'string', 'max:32'],
            'validity_days' => ['nullable', 'integer', 'min:1', 'max:730'],
        ]);

        $employee = Employee::findOrFail($data['employee_id']);
        $pinPlain = (string) random_int(1000, 9999);

        StaffPhonePin::updateOrCreate(
            ['employee_id' => $employee->id],
            [
                'phone'           => $data['phone'],
                'pin_hash'        => Hash::make($pinPlain),
                'pin_expires_at'  => $data['validity_days'] ? now()->addDays((int) $data['validity_days']) : null,
                'failed_attempts' => 0,
                'locked_until'    => null,
            ],
        );

        $this->sms->send(
            toPhone:     $data['phone'],
            body:        "CIHRMS PIN for {$employee->employee_no}: {$pinPlain}. Do not share. Reply STOP to opt out.",
            contextType: 'pin_issue',
            contextId:   $employee->id,
            triggeredBy: $request->user(),
        );

        return back()->with('success', "PIN issued and SMS'd to {$data['phone']}.");
    }
}

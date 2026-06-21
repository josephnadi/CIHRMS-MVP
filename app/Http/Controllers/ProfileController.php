<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use App\Http\Resources\EmployeeResource;
use App\Http\Resources\LeaveBalanceResource;
use App\Models\EmployeeSkill;
use App\Services\EmployeeService;
use App\Services\LeaveService;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    public function __construct(
        private readonly EmployeeService $employees,
        private readonly LeaveService $leaves,
    ) {}

    /**
     * Render the employee self-service portal (replaces the basic profile editor).
     */
    public function edit(Request $request): Response
    {
        $user     = $request->user();
        $employee = $user->employee
            ? $this->employees->find($user->employee->id)
            : null;

        return Inertia::render('Profile/Edit', [
            'mustVerifyEmail' => $user instanceof MustVerifyEmail,
            'status'          => session('status'),
            'employee'        => $employee ? new EmployeeResource($employee) : null,
            // ->resolve() turns the ResourceCollection into the bare array
            // the Vue page's prop declaration expects (`leaveBalances: Array`).
            // Same pattern used in PrivacyController::myRequests after the V2 audit.
            'leaveBalances'   => $employee
                ? LeaveBalanceResource::collection($this->leaves->balances($employee->id, now()->year))->resolve()
                : [],
            'recentLeave'     => $employee
                ? $employee->leaveRequests()->latest()->limit(5)->get()
                    ->map(fn ($lr) => [
                        'id'         => $lr->id,
                        'type'       => $lr->type?->value,
                        'type_label' => $lr->type?->label(),
                        'status'     => $lr->status?->value,
                        'start_date' => $lr->start_date?->toDateString(),
                        'end_date'   => $lr->end_date?->toDateString(),
                        'created_at' => $lr->created_at?->toISOString(),
                    ])
                : [],
            'recentTickets'   => $employee
                ? $employee->tickets()->latest()->limit(5)->get()
                    ->map(fn ($t) => [
                        'id'       => $t->id,
                        'title'    => $t->title,
                        'status'   => $t->status?->value,
                        'priority' => $t->priority?->value,
                        'created_at' => $t->created_at?->toISOString(),
                    ])
                : [],
            'recentPayments'  => $employee
                ? $employee->payments()->latest()->limit(6)->get()
                    ->map(fn ($p) => [
                        'id'          => $p->id,
                        'description' => $p->description,
                        'amount'      => $p->amount,
                        'currency'    => $p->currency,
                        'status'      => $p->status?->value,
                        'paid_at'     => $p->paid_at?->toISOString(),
                        'created_at'  => $p->created_at?->toISOString(),
                    ])
                : [],
            'documents' => $employee
                ? $employee->documents->map(fn ($d) => [
                    'id'           => $d->id,
                    'title'        => $d->title,
                    'mime_type'    => $d->mime_type,
                    // can_manage = this is the employee's OWN upload (HR docs are
                    // download-only). Drives the rename/replace/delete controls.
                    'can_manage'   => $d->uploaded_by === $user->id,
                    'download_url' => route('profile.documents.download', $d->id),
                    'created_at'   => $d->created_at?->toISOString(),
                ])
                : [],
            'skillLevels' => EmployeeSkill::LEVELS,
        ]);
    }

    /** Update name + email (legacy Breeze endpoint). */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        return Redirect::route('profile.edit')->with('success', 'Profile updated.');
    }

    /** Self-service personal info (gender, DOB, national_id, address, phone). */
    public function updatePersonal(Request $request): RedirectResponse
    {
        $employee = $request->user()->employee;
        abort_unless($employee, 404, 'No employee record linked to this account.');

        $data = $request->validate([
            'phone'         => ['nullable', 'string', 'max:20'],
            'gender'        => ['nullable', 'string', 'in:male,female,other,prefer_not_to_say'],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'national_id'   => ['nullable', 'string', 'max:64'],
            'address'       => ['nullable', 'string', 'max:255'],
        ]);

        $employee->update($data);

        return back()->with('success', 'Personal details updated.');
    }

    /** Self-service emergency contact. */
    public function updateEmergency(Request $request): RedirectResponse
    {
        $employee = $request->user()->employee;
        abort_unless($employee, 404, 'No employee record linked to this account.');

        $data = $request->validate([
            'emergency_contact_name'         => ['nullable', 'string', 'max:255'],
            'emergency_contact_phone'        => ['nullable', 'string', 'max:32'],
            'emergency_contact_relationship' => ['nullable', 'string', 'max:64'],
        ]);

        $employee->update($data);

        return back()->with('success', 'Emergency contact updated.');
    }

    /** Self-service bank details. */
    public function updateBank(Request $request): RedirectResponse
    {
        $employee = $request->user()->employee;
        abort_unless($employee, 404, 'No employee record linked to this account.');

        $data = $request->validate([
            'bank_name'    => ['nullable', 'string', 'max:255'],
            'bank_account' => ['nullable', 'string', 'max:64'],
        ]);

        $employee->update($data);

        return back()->with('success', 'Bank details updated.');
    }

    /** Self-service avatar upload. */
    public function updateAvatar(Request $request): RedirectResponse
    {
        $employee = $request->user()->employee;
        abort_unless($employee, 404, 'No employee record linked to this account.');

        $request->validate([
            'avatar' => ['required', 'image', 'max:4096', 'mimes:jpg,jpeg,png,webp'],
        ]);

        $this->employees->uploadAvatar($employee, $request->file('avatar'));

        return back()->with('success', 'Profile photo updated.');
    }

    /** Self-service password change (separate from /profile DELETE which removes account). */
    public function updatePassword(Request $request): RedirectResponse
    {
        $request->validate([
            'current_password' => ['required', 'current_password'],
            'password'         => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $request->user()->update([
            'password'             => Hash::make($request->password),
            'password_must_change' => false,
        ]);

        return back()->with('success', 'Password updated.');
    }

    /** Account deletion stays gated by current_password. */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();
        Auth::logout();
        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }
}

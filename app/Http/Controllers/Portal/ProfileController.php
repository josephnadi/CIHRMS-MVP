<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    public function edit(Request $request): Response
    {
        $member = $request->user('member');
        return Inertia::render('Portal/Profile/Edit', [
            'member' => [
                'member_no'   => $member->member_no,
                'class'       => is_object($member->class) ? $member->class->value : (string) $member->class,
                'status'      => is_object($member->status) ? $member->status->value : (string) $member->status,
                'name'        => $member->name,
                'email'       => $member->email,
                'phone'       => $member->phone,
                'address'     => $member->address,
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $member = $request->user('member');

        $data = $request->validate([
            // Members can edit their own contact details. They CANNOT
            // change class, status, member_no, or any institute-side
            // identity field — those move via admin only.
            'name'    => ['sometimes', 'string', 'max:200'],
            'email'   => ['sometimes', 'nullable', 'email', 'max:200', Rule::unique('members', 'email')->ignore($member->id)->whereNull('deleted_at')],
            'phone'   => ['sometimes', 'nullable', 'string', 'max:30'],
            'address' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        $member->fill($data)->save();

        return back()->with('success', 'Profile updated.');
    }
}

<?php

namespace App\Http\Controllers;

use App\Services\AccountPasswordService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class AccountController extends Controller
{
    public function edit(): View
    {
        return view('account.password');
    }

    public function update(Request $request, AccountPasswordService $passwords): RedirectResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'string', 'min:12', 'max:128', 'confirmed', 'different:current_password'],
        ]);
        $passwords->change($request->user(), $validated['password'], 'account-settings');
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('success', 'Password changed. Sign in with your new password.');
    }
}

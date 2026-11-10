<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function create(): View|RedirectResponse
    {
        if (User::query()->doesntExist()) {
            return redirect()->route('setup.create');
        }

        return view('auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'login' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
        ]);
        $loginField = filter_var($credentials['login'], FILTER_VALIDATE_EMAIL) ? 'email' : 'username';

        if (! Auth::attempt([$loginField => $credentials['login'], 'password' => $credentials['password']], $request->boolean('remember'))) {
            return back()->onlyInput('login')->withErrors(['login' => 'The provided credentials are incorrect.']);
        }

        /** @var User $user */
        $user = Auth::user();

        // Enforce account active status
        if ($user->is_active === false) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return back()->onlyInput('login')->withErrors([
                'login' => 'This account has been deactivated. Please contact the Systems Engineer or Super Admin.',
            ]);
        }

        // Record login audit metrics
        $user->update([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ]);

        $request->session()->regenerate();

        // Prompt temporary password recipients to update their credentials
        if ($user->must_change_password) {
            return redirect()->route('account.password.edit')
                ->with('warning', 'Please update your temporary password before accessing the system.');
        }

        // Dedicated initial landing by role
        if (! $user->isFullAdmin()) {
            if ($user->isDirector() || $user->isDeputyDirector()) {
                return redirect()->route('dashboard.executive');
            }
            if ($user->isHrOfficer()) {
                return redirect()->route('dashboard.hr');
            }
            if ($user->isAuditOfficer()) {
                return redirect()->route('dashboard.audit');
            }
        }

        return redirect()->intended(route('dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    public function setupCreate(): View|RedirectResponse
    {
        if (User::query()->exists()) {
            return redirect()->route('login');
        }

        return view('auth.setup');
    }

    public function setupStore(Request $request): RedirectResponse
    {
        abort_if(User::query()->exists(), 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'min:3', 'max:64', 'alpha_dash', 'unique:users,username'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:12', 'confirmed'],
        ]);

        $user = User::create($validated);
        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('dashboard');
    }
}

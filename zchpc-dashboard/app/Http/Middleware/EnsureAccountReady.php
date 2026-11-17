<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureAccountReady
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && $user->is_active === false) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return $request->expectsJson() ? response()->json(['message' => 'Account is inactive.'], 403)
                : redirect()->route('login')->withErrors(['login' => 'Your account is inactive. Contact an administrator.']);
        }
        if ($user?->must_change_password && ! $request->routeIs('account.password.*', 'logout')) {
            return $request->expectsJson() ? response()->json(['message' => 'Change your temporary password first.'], 403)
                : redirect()->route('account.password.edit')->with('warning', 'Change your temporary password before continuing.');
        }

        return $next($request);
    }
}

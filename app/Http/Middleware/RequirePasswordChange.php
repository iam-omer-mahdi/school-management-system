<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A password handed over by an administrator is a shared secret, and the three
 * security answers are the offline way back into the account. Until the member
 * replaces both, every page except the setup form is closed (plan §4.5).
 *
 * This is the flow guard, not the security boundary: permissions on the Actions
 * are what stop a crafted request (plan §5).
 *
 * shortcut: Livewire's update endpoint is allowed wholesale, so a flagged account
 * could still call another component's methods directly. Upgrade to a per-component
 * check if Livewire ever sends the target component before this middleware runs.
 */
class RequirePasswordChange
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $user->must_change_password || $this->isSetupRequest($request)) {
            return $next($request);
        }

        return redirect()->route('first-login');
    }

    /**
     * The form, a way out (logout), the locale switch, and Livewire's update
     * endpoint — that last one serves every component, so without it the setup
     * form could not submit its own first request.
     */
    private function isSetupRequest(Request $request): bool
    {
        return $request->routeIs('first-login', 'logout', 'locale.switch', '*livewire.update');
    }
}

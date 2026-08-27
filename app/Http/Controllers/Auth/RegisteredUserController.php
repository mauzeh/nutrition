<?php

namespace App\Http\Controllers\Auth;

use App\Events\UserRegistered;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\UserSeederService;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Illuminate\View\View;

class RegisteredUserController extends Controller
{
    /**
     * Display the registration view.
     */
    public function create(): View
    {
        return view('auth.register');
    }

    /**
     * Handle an incoming registration request.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function store(Request $request, UserSeederService $userSeederService): RedirectResponse
    {
        // Reject automated/bot submissions before any other processing.
        $this->guardAgainstBots($request);

        // Custom validation to handle soft-deleted users
        $this->validateRegistration($request);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
        ]);

        // Seed the new user with default data
        $userSeederService->seedNewUser($user);

        // Dispatch user registered event
        UserRegistered::dispatch($user);

        event(new Registered($user));

        Auth::login($user);

        return redirect(route('mobile-entry.lifts', absolute: false));
    }

    /**
     * Reject bot submissions via a honeypot field and a minimum fill-time check.
     *
     * The 'website' field is hidden from real users, so any value indicates a bot.
     * The encrypted 'form_loaded_at' timestamp lets us reject submissions completed
     * faster than a human plausibly could. Both failures return a generic error so
     * bots cannot learn which check caught them.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    private function guardAgainstBots(Request $request): void
    {
        $failed = false;

        // The decoy field is invisible to real users; any value indicates a bot.
        if (filled($request->input('website'))) {
            $failed = true;
        }

        // The timing check only applies when the form supplied a timestamp. A
        // present-but-tampered value fails; a submission faster than a human
        // could plausibly complete the form fails. A missing timestamp is not
        // treated as a failure so that non-browser flows are not falsely blocked.
        if ($request->filled('form_loaded_at')) {
            try {
                $loadedAt = (int) decrypt($request->input('form_loaded_at'));

                if ((now()->timestamp - $loadedAt) < 2) {
                    $failed = true;
                }
            } catch (\Illuminate\Contracts\Encryption\DecryptException $e) {
                $failed = true;
            }
        }

        if ($failed) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'email' => 'Your registration could not be processed. Please try again.',
            ]);
        }
    }

    /**
     * Validate registration request with custom email uniqueness check.
     */
    private function validateRegistration(Request $request): void
    {
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        // Check if user exists with this email, including soft-deleted ones
        $existingUser = User::withTrashed()->where('email', $request->email)->first();

        if ($existingUser) {
            if ($existingUser->trashed()) {
                // Soft-deleted user - provide specific error message
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'email' => 'This email address was previously registered but the account has been deactivated. Please contact support to reactivate your account or use a different email address.'
                ]);
            } else {
                // Active user - standard uniqueness error
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'email' => 'The email has already been taken.'
                ]);
            }
        }
    }
}

<?php

namespace App\Sync\Controllers;

use App\Models\User;
use App\Sync\Services\AppleJwtVerifier;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use UnexpectedValueException;

class AuthController
{
    /**
     * Register a new sync user.
     */
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8',
            'name' => 'required|string',
            'device_id' => 'required|string',
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
        ]);

        return $this->authResponse($user, $validated['device_id']);
    }

    /**
     * Authenticate an existing user and generate a token.
     */
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
            'device_id' => 'required|string',
        ]);

        $user = User::where('email', $validated['email'])->first();

        // In local environment, skip password verification (dev mode bypass)
        $passwordValid = app()->environment('local')
            ? true
            : ($user && Hash::check($validated['password'], $user->password));

        if (! $user || ! $passwordValid) {
            throw new AuthenticationException('Invalid credentials.');
        }

        return $this->authResponse($user, $validated['device_id']);
    }

    /**
     * Redirect to Google OAuth consent screen (server-side flow for athlete PWA).
     */
    public function googleRedirect(Request $request): RedirectResponse
    {
        $deviceId = $request->query('device_id', 'athlete-pwa');
        $athleteUrl = config('services.athlete.url', 'https://squirby.app');

        // Encode state as base64 JSON to survive the OAuth round-trip without sessions
        $state = base64_encode(json_encode([
            'device_id' => $deviceId,
            'athlete_url' => $athleteUrl,
        ]));

        return Socialite::driver('google')
            ->stateless()
            ->redirectUrl(url('/api/sync/auth/google/callback'))
            ->with(['prompt' => 'select_account', 'state' => $state])
            ->redirect();
    }

    /**
     * Handle Google OAuth callback — issue Sanctum token and redirect to athlete app.
     */
    public function googleCallback(Request $request)
    {
        // Decode state passed through the OAuth flow. The state is unsigned and
        // therefore attacker-controllable, so the redirect target it carries must
        // be validated against an allowlist before we redirect the issued token.
        $state = json_decode(base64_decode($request->query('state', '')), true) ?? [];
        $athleteUrl = $this->resolveSafeRedirectUrl($state['athlete_url'] ?? null);
        $deviceId = $state['device_id'] ?? 'athlete-pwa';

        try {
            $googleUser = Socialite::driver('google')
                ->stateless()
                ->redirectUrl(url('/api/sync/auth/google/callback'))
                ->user();
        } catch (\Exception $e) {
            return redirect($athleteUrl . '/auth/callback?error=google_auth_failed');
        }

        $user = $this->findOrCreateSocialUser(
            email: $googleUser->getEmail(),
            name: $googleUser->getName() ?? $googleUser->getEmail(),
            googleId: $googleUser->getId()
        );

        $token = $user->createToken($deviceId)->plainTextToken;

        // Redirect back to athlete app with token and user info
        $params = http_build_query([
            'token' => $token,
            'athlete' => $user->name,
            'email' => $user->email,
        ]);

        return redirect($athleteUrl . '/auth/callback?' . $params);
    }

    /**
     * Authenticate via Apple social sign-in.
     */
    public function appleAuth(Request $request, AppleJwtVerifier $verifier): JsonResponse
    {
        $validated = $request->validate([
            'identity_token' => 'required|string',
            'device_id' => 'required|string',
        ]);

        try {
            $payload = $verifier->verify($validated['identity_token']);
        } catch (UnexpectedValueException $e) {
            throw new AuthenticationException('Invalid Apple token.');
        }

        if (empty($payload['email'])) {
            throw new AuthenticationException('Invalid Apple token: email missing.');
        }

        $user = $this->findOrCreateSocialUser(
            email: $payload['email'],
            name: $payload['name'] ?? explode('@', $payload['email'])[0],
            googleId: null
        );

        return $this->authResponse($user, $validated['device_id']);
    }

    /**
     * Check if an email has an existing account and what auth methods are set.
     */
    public function checkEmail(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email',
        ]);

        $user = User::where('email', $validated['email'])->first();

        return response()->json([
            'status' => 'ok',
            'next_step' => $this->resolveAuthNextStep($user),
        ]);
    }

    /**
     * Resolve the auth screen the client should route the user to.
     *
     * Returns a single hint rather than exposing separate exists / has_password /
     * has_google booleans, so the endpoint is not a clean account-enumeration
     * oracle. Combined with rate limiting on the route, this limits how much an
     * attacker can learn while still letting the app route returning users to the
     * right sign-in method.
     *
     * - 'password': an existing account that can sign in with a password
     * - 'google':   an existing account that authenticates via Google only
     * - 'register': no account matched; the user should create one
     */
    private function resolveAuthNextStep(?User $user): string
    {
        if (! $user) {
            return 'register';
        }

        $hasRealPassword = ! is_null($user->password)
            && $user->password !== ''
            && ! Hash::check('social-auth-placeholder-value', $user->password);

        if ($hasRealPassword) {
            return 'password';
        }

        if (! is_null($user->google_id)) {
            return 'google';
        }

        // Account exists but has neither a usable password nor Google link;
        // routing to password lets them recover via the forgot-password flow.
        return 'password';
    }

    /**
     * Send a password reset link to the given email.
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
        ]);

        $status = Password::sendResetLink(
            $request->only('email')
        );

        // Always return success to prevent email enumeration
        return response()->json([
            'status' => 'ok',
            'message' => 'If an account exists with that email, a reset link has been sent.',
        ]);
    }

    /**
     * Reset the user's password using a valid token.
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $request->validate([
            'token' => 'required|string',
            'email' => 'required|email',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user) use ($request) {
                $user->forceFill([
                    'password' => Hash::make($request->password),
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($user));
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return response()->json([
                'status' => 'ok',
                'message' => 'Password has been reset successfully.',
            ]);
        }

        return response()->json([
            'status' => 'error',
            'message' => __($status),
        ], 422);
    }

    /**
     * Resolve a safe redirect base URL for the OAuth callback.
     *
     * The configured athlete URL is always trusted. A state-supplied URL is only
     * honored when its host matches the configured URL's host or appears in the
     * services.athlete.allowed_redirect_hosts allowlist. Anything else falls back
     * to the configured default so an unsigned state cannot redirect the token
     * to an attacker-controlled origin.
     */
    private function resolveSafeRedirectUrl(?string $candidate): string
    {
        $default = config('services.athlete.url', 'https://flagship.squirby.ai');

        if (empty($candidate) || ! is_string($candidate)) {
            return $default;
        }

        $candidateHost = parse_url($candidate, PHP_URL_HOST);

        if (empty($candidateHost)) {
            return $default;
        }

        $allowedHosts = config('services.athlete.allowed_redirect_hosts', []);
        $defaultHost = parse_url($default, PHP_URL_HOST);

        if ($defaultHost) {
            $allowedHosts[] = $defaultHost;
        }

        if (in_array($candidateHost, $allowedHosts, true)) {
            return $candidate;
        }

        return $default;
    }

    /**
     * Find or create a social auth user.
     */
    private function findOrCreateSocialUser(string $email, string $name, ?string $googleId): User
    {
        $user = User::where('email', $email)->first();

        if ($user) {
            if ($googleId && ! $user->google_id) {
                $user->update(['google_id' => $googleId]);
            }

            return $user;
        }

        return User::create([
            'name' => $name,
            'email' => $email,
            'google_id' => $googleId,
            'password' => 'social-auth-placeholder-value',
        ]);
    }

    /**
     * Generate Sanctum token and return unified auth response.
     */
    private function authResponse(User $user, string $deviceId): JsonResponse
    {
        $token = $user->createToken($deviceId)->plainTextToken;

        return response()->json([
            'status' => 'ok',
            'token' => $token,
            'athlete' => $user->name,
            'email' => $user->email,
        ]);
    }
}

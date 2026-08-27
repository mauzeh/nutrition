# Login & Account-Existence Disclosure

This document describes how the app currently discloses whether an account exists
for a given email, why that is a privacy/security concern, and how to move to a
stricter "no synchronous disclosure" model (referred to here as **Option 3**) in
the future.

It is written for engineers who did not build the current auth flow. Read the
"How it works today" section first, then the risk analysis, then the Option 3
implementation plan.

---

## Background: two auth surfaces

The app has two separate authentication surfaces:

- **Web (Breeze)** — session-based login/registration used by the web UI.
  Routes in `routes/auth.php`; registration in
  `app/Http/Controllers/Auth/RegisteredUserController.php`.
- **Sync API** — token-based (Sanctum) auth used by the athlete app (PWA).
  Routes in `routes/sync.php`; logic in `app/Sync/Controllers/AuthController.php`.

Account-existence disclosure is primarily a concern on the **Sync API**, via the
`POST /api/sync/auth/check` endpoint (`checkEmail`).

---

## How it works today

### The `checkEmail` endpoint

Route (`routes/sync.php`):

```php
Route::post('/auth/check', [AuthController::class, 'checkEmail'])->middleware('throttle:email-check');
```

Handler: `AuthController::checkEmail()` (`app/Sync/Controllers/AuthController.php`,
around line 158). It validates an `email`, loads the matching `User`, and returns
a single routing hint produced by `resolveAuthNextStep()` (around line 185):

```json
{ "status": "ok", "next_step": "password" | "google" | "register" }
```

- `password` — an account exists that can sign in with a password (or exists but
  has no usable credential, in which case the user is routed to password so they
  can recover via forgot-password).
- `google` — an account exists that authenticates via Google only.
- `register` — no account matched.

The athlete app calls this after the user types their email, then shows the
appropriate screen (password field, "Continue with Google", or the signup form).

**This is the current state after "Option 2".** Previously the endpoint returned
three separate booleans — `exists`, `has_password`, and `has_google` — which was a
cleaner enumeration oracle. That shape has been collapsed into the single
`next_step` hint, and the route is now rate limited.

### Rate limiting

The `email-check` limiter is defined in
`app/Providers/AppServiceProvider.php` (in `boot()`, alongside the sync limiters):

```php
RateLimiter::for('email-check', function (Request $request) {
    return Limit::perMinute(5)->by($request->ip());
});
```

This caps bulk scraping but does not stop a patient attacker from probing
individual emails.

### Related endpoints that already avoid disclosure

`forgotPassword()` (`app/Sync/Controllers/AuthController.php`, around line 211)
deliberately returns the same response whether or not the account exists:

> "If an account exists with that email, a reset link has been sent."

This is the anti-enumeration pattern we would extend to signup under Option 3.

### Registration disclosure (web)

The web registration flow in
`RegisteredUserController::validateRegistration()` (around line 104) returns
*distinct* error messages for three cases:

- active account already exists ("The email has already been taken.")
- soft-deleted account exists ("previously registered ... deactivated ...")
- success

That difference is itself a (smaller) enumeration signal on the web surface and
is called out again in the Option 3 plan below.

---

## The risk

`next_step` (and, before Option 2, the boolean triplet) is an **account-existence
oracle**: anyone, unauthenticated, can learn whether a given email has an account
and which sign-in method it uses.

Concrete concerns:

1. **Presence disclosure.** For a fitness app, simply revealing that a person has
   an account is a privacy leak. It confirms platform membership to anyone who
   asks (an ex-partner, an employer, a stalker).
2. **Attack targeting.** Knowing the auth method lets an attacker pick the right
   attack: credential stuffing against `password` accounts, or OAuth-consent
   phishing against `google` accounts.
3. **Scale.** Without disclosure controls, the endpoint can be scraped to build a
   membership list. Rate limiting (Option 2) raises the cost but does not remove
   the oracle — one request per email still answers the question.

The fundamental limitation of Option 2: **you cannot both hide account existence
from an attacker and reveal it to a user through the same unauthenticated,
synchronous endpoint.** The response is the disclosure. Option 3 removes the
synchronous disclosure entirely and moves the "you already have an account" help
into a channel only the real inbox owner can read: email.

---

## Option 3: no synchronous disclosure

**Goal:** the API never tells an unauthenticated caller whether an account
exists. The "you already registered, here's how to sign in" experience is
delivered by emailing the address, so only the person who controls the inbox
learns anything.

### Behavioral contract

1. **`checkEmail` stops disclosing.** It either:
   - is removed entirely and the client always shows a combined
     email + password/Google screen, or
   - always returns the same neutral response regardless of account state, e.g.
     `{ "status": "ok" }`, with the client no longer branching on it.

2. **Signup with an existing email does not error differently.** When someone
   attempts to register (`POST /api/sync/auth/register`, or the web
   `POST /register`) with an email that already has an account:
   - Do **not** create a second account.
   - Do **not** return an "email already taken" error that reveals existence.
   - Instead, return the **same** "check your inbox to continue" response that a
     brand-new signup would trigger, and send an email to that address:
     - If no account exists: a normal "confirm your email / welcome" message.
     - If an account already exists: an "you already have an account — sign in
       here (and reset your password / use Google)" message.
   - The two responses to the caller are byte-for-byte identical.

3. **Login remains method-agnostic in its errors.** `login()`
   (`app/Sync/Controllers/AuthController.php`) must return the same
   "invalid credentials" error whether the email is unknown, the password is
   wrong, or the account is Google-only. It must not say "this account uses
   Google."

4. **Timing.** Make existent vs. non-existent paths take similar time. Always
   dispatch the email send to a queue (`ShouldQueue`) so the HTTP response time
   does not depend on whether an email was actually sent, and avoid doing a
   password hash only on the "exists" path.

### Implementation steps

Below, "the same response" means an identical HTTP status, body, and headers.

#### 1. Neutralize `checkEmail`

In `AuthController::checkEmail()`:

- Remove the `User` lookup and the `resolveAuthNextStep()` branch.
- Return a constant response, e.g. `{ "status": "ok" }`.
- Consider deprecating the route in `routes/sync.php` once the athlete app no
  longer depends on it. Coordinate the client change first (see "Athlete app
  changes").
- Keep the `throttle:email-check` limiter regardless.

`resolveAuthNextStep()` can then be deleted.

#### 2. Make registration existence-safe (Sync API)

In `AuthController::register()` (`app/Sync/Controllers/AuthController.php`,
around line 23):

- Replace the `unique:users,email` validation rule (which returns a
  disclosing 422) with logic that:
  - looks up the existing user *without* surfacing the result to the caller;
  - if none exists, creates the account as today;
  - if one exists, skips creation;
  - in **both** cases dispatches the appropriate queued mail and returns the
    same neutral "check your inbox" JSON.
- Do not auto-issue a Sanctum token on this path anymore if you require email
  confirmation before first login (see step 4). If you keep immediate login for
  brand-new users, be careful: issuing a token only in the "new account" branch
  re-introduces a timing/behavior oracle. Prefer requiring inbox confirmation
  for both branches so the observable behavior is identical.

#### 3. Make registration existence-safe (web)

In `RegisteredUserController::validateRegistration()`
(`app/Http/Controllers/Auth/RegisteredUserController.php`, around line 104):

- Collapse the three distinct outcomes (active-exists, soft-deleted-exists,
  success) into one neutral flow.
- On an existing (or soft-deleted) email, do not throw a distinguishable
  validation error. Instead, show the same "check your inbox" confirmation page
  a new signup shows, and email the address with sign-in / reactivation
  instructions.
- Keep the honeypot (`guardAgainstBots()`, around line 69) and throttling in
  place; they are complementary.

> Note: this changes the web signup UX from "instant account + login" to
> "confirm via email first." That is a product decision. It also interacts with
> the `MustVerifyEmail` enforcement already in place (`app/Models/User.php`
> implements `MustVerifyEmail`; the `verified` middleware guards the main route
> groups in `routes/web.php`).

#### 4. Add the two mailables

Create two queued mailables (`php artisan make:mail`, implement `ShouldQueue`):

- `AccountSignupConfirmation` — for genuinely new emails (verify + welcome).
- `ExistingAccountNotice` — for emails that already have an account: explains
  they already registered and links to sign-in, password reset, and Google.
  For a Google-only account, guide them to "Continue with Google".

Always dispatch one of these on the signup path; never branch the HTTP response
on which one was sent.

#### 5. Login error parity

Audit `AuthController::login()` and the web
`AuthenticatedSessionController` to confirm a single generic failure message for
all of: unknown email, wrong password, Google-only account. (The Sync `login()`
already throws a generic `AuthenticationException('Invalid credentials.')` —
verify no upstream layer adds method-specific detail.)

#### 6. Timing hygiene

- Ensure mail sends are queued so response time is independent of the branch.
- Avoid performing a password hash comparison only in the "account exists"
  branch of any endpoint; if you need constant-time behavior, hash against a
  dummy value on the "not found" branch too.

### Athlete app changes (client)

Option 3 requires client coordination, because the app currently branches on
`next_step`:

- Replace the "type email → we tell you which screen" flow with a combined
  screen (email + password, plus a "Continue with Google" button and a
  "Create account" action), OR keep asking for the email but always proceed to a
  single next screen without server-provided branching.
- Handle the new signup response: show "check your inbox" instead of logging the
  user straight in (if step 2/3 defer login to post-confirmation).

Ship the client change **before** removing or neutralizing `checkEmail`, or
version the endpoint, to avoid breaking older app builds.

### Testing

Add feature tests asserting the disclosure is gone:

- `checkEmail` returns an identical response for an existing vs. non-existing
  email (compare status + body).
- `POST /api/sync/auth/register` and web `POST /register` return identical
  responses for a new email vs. an already-registered email, and do **not**
  create a second user in the existing-email case.
- Login returns the same error for unknown email, wrong password, and
  Google-only account.
- The correct mailable is queued in each branch (`Mail::fake()` +
  `Mail::assertQueued`), without the HTTP response revealing which.

Existing tests that will need updating (they currently assert the disclosing
behavior or the `next_step` hint):

- `tests/Feature/Sync/AuthUpgradeTest.php` — `test_email_check_nonexistent`,
  `test_email_check_existing` assert `next_step`. Under Option 3 they must assert
  the neutral, identical response.
- `tests/Feature/Auth/RegistrationTest.php` — several tests assert the
  distinguishable "email already taken" / soft-deleted errors and immediate
  authentication after signup. These encode the current web behavior and must be
  revised to the "check your inbox, no disclosure" contract.

Do not delete these tests; update them to the new contract (and get review, per
the workspace rule on altering existing tests).

---

## Summary

| | Discloses existence? | UX for returning users | Client change | Effort |
|---|---|---|---|---|
| **Option 2 (current)** | Yes (via `next_step`), rate limited | Instant, friendly routing | none | done |
| **Option 3 (future)** | No (moved to email) | "Check your inbox", email guides them | required | larger |

Option 2 is harm-reduction: it removes the clean boolean oracle and stops bulk
scraping, but a determined attacker can still learn existence one email at a
time. Option 3 eliminates synchronous disclosure at the cost of an email
round-trip on signup and coordinated client changes. Choose Option 3 when
presence-privacy becomes a hard requirement.

### Key source references

- `app/Sync/Controllers/AuthController.php` — `checkEmail()`,
  `resolveAuthNextStep()`, `register()`, `login()`, `forgotPassword()`.
- `routes/sync.php` — `/auth/check`, `/register`, `/login` routes and throttles.
- `app/Providers/AppServiceProvider.php` — `email-check` rate limiter.
- `app/Http/Controllers/Auth/RegisteredUserController.php` —
  `validateRegistration()`, `guardAgainstBots()`.
- `app/Models/User.php` — `MustVerifyEmail`, and the social-auth placeholder
  password sentinel used to distinguish password vs. Google accounts.
- `routes/web.php` — `verified` middleware on the authenticated route groups.

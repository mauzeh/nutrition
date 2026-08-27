@extends('layouts.auth')

@section('title', 'Register')

@section('description')
    <div class="register-link-container" style="margin-bottom: 20px; border: none; padding: 0;">
        <p>Already registered? <a href="{{ route('login') }}" class="register-link">Sign in here</a></p>
    </div>
@endsection

@section('google-auth')
    <div class="google-signin-container">
        <a href="{{ route('auth.google') }}" class="google-signin-btn">
            <svg class="google-icon" viewBox="0 0 24 24" width="18" height="18">
                <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
                <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
                <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/>
                <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/>
            </svg>
            <span>Sign up with Google</span>
        </a>
    </div>
@endsection

@section('form')
    <form method="POST" action="{{ route('register') }}" class="login-form">
        @csrf

        {{-- Honeypot: hidden from real users, bots tend to fill it. Leave empty. --}}
        <div aria-hidden="true" style="position:absolute;left:-9999px;top:-9999px;height:0;width:0;overflow:hidden;">
            <label for="website">Website</label>
            <input id="website" type="text" name="website" value="" tabindex="-1" autocomplete="off" />
        </div>
        {{-- Timestamp: submissions faster than a human can fill the form are rejected. --}}
        <input type="hidden" name="form_loaded_at" value="{{ encrypt(now()->timestamp) }}" />

        <!-- Name -->
        <div class="login-form-group">
            <label for="name">Name</label>
            <input id="name" type="text" name="name" value="{{ old('name') }}" required autofocus autocomplete="name" />
        </div>

        <!-- Email Address -->
        <div class="login-form-group">
            <label for="email">Email</label>
            <input id="email" type="email" name="email" value="{{ old('email') }}" required autocomplete="username" />
        </div>

        <!-- Password -->
        <div class="login-form-group">
            <label for="password">Password</label>
            <input id="password" type="password" name="password" required autocomplete="new-password" />
            <div class="password-requirements">
                <small>Must be at least 8 characters</small>
            </div>
            @error('password')
                <div class="field-error">{{ $message }}</div>
            @enderror
        </div>

        <!-- Confirm Password -->
        <div class="login-form-group">
            <label for="password_confirmation">Confirm Password</label>
            <input id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password" />
            @error('password_confirmation')
                <div class="field-error">{{ $message }}</div>
            @enderror
        </div>

        <button type="submit" class="login-button">
            Register
        </button>
    </form>
@endsection


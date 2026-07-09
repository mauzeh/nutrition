<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('favicon/apple-touch-icon.png') }}">
        <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon/favicon-32x32.png') }}">
        <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon/favicon-16x16.png') }}">
        <link rel="manifest" href="{{ asset('favicon/site.webmanifest') }}">
        <link rel="shortcut icon" href="{{ asset('favicon/favicon.ico') }}">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

        <title>{{ config('app.name', 'Quantified Athletics') }}</title>

        <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ config('app.version', '1.0') }}">
        @yield('styles')
        @yield('scripts')
    </head>
    <body>
        @if(session()->has('impersonator_id'))
            <div class="impersonation-bar">
                {{ auth()->user()->name }} ({{ auth()->user()->id }}). <a href="{{ route('users.leave-impersonate') }}" style="color: #000;">Switch Back</a>
            </div>
        @endif
        @if(app()->environment('staging'))
            <div class="env-bar staging">
                STAGING ENVIRONMENT
            </div>
        @elseif(app()->environment('local'))
            <div class="env-bar local">
                LOCAL DEV ENVIRONMENT
            </div>
        @endif
        @auth
        @php
            $showAdminHues = request()->get('test_admin_hues') == '1' || 
                            (app()->environment('production') && auth()->user()->hasRole('Admin') && !session()->has('impersonator_id'));
            $showNonProdHues = !app()->environment('production') && request()->get('test_admin_hues') != '1';
        @endphp
        <div class="navbar @if($showNonProdHues) env-navbar-non-production @elseif($showAdminHues) env-navbar-admin-production @endif">
            @foreach($menuService->getMainMenu() as $item)
                <a @if(isset($item['id']))id="{{ $item['id'] }}"@endif 
                   href="{{ route($item['route']) }}" 
                   class="top-level-nav-item {{ $item['active'] ? 'active' : '' }}">
                    <i class="fas {{ $item['icon'] }} menu-icon"></i>{{ $item['label'] }}
                    @if(isset($item['badgeCount']) && $item['badgeCount'] > 0)
                        <span class="menu-badge">{{ $item['badgeCount'] }}</span>
                    @endif
                </a>
            @endforeach

            <div style="margin-left: auto;">
                @foreach($menuService->getUtilityMenu() as $item)
                    @if(isset($item['type']) && $item['type'] === 'logout')
                        <form method="POST" action="{{ route('logout') }}" style="display: inline-block;">
                            @csrf
                            <button type="submit" class="icon-only" style="background: none; border: none; color: #f2f2f2; text-align: center; padding: 14px 8px; text-decoration: none; font-size: 17px; cursor: pointer;">
                                <i class="fas {{ $item['icon'] }} menu-icon"></i>
                            </button>
                        </form>
                    @else
                        <a href="{{ route($item['route']) }}" 
                           class="{{ $item['active'] ? 'active' : '' }}{{ !isset($item['label']) || empty($item['label']) ? ' icon-only' : '' }}" 
                           @if(isset($item['style']))style="{{ $item['style'] }}"@endif>
                            <i class="fas {{ $item['icon'] }} menu-icon"></i>
                            @if(isset($item['label'])){{ $item['label'] }}@endif
                        </a>
                    @endif
                @endforeach
            </div>
        </div>

        @if ($menuService->shouldShowSubMenu())
        <div class="navbar sub-navbar @if($showNonProdHues) env-navbar-non-production @elseif($showAdminHues) env-navbar-admin-production @endif">
            @foreach($menuService->getSubMenu() as $item)
                <a href="{{ isset($item['routeParams']) ? route($item['route'], $item['routeParams']) : route($item['route']) }}" 
                   class="{{ $item['active'] ? 'active' : '' }}{{ !isset($item['label']) || empty($item['label']) ? ' icon-only' : '' }}{{ isset($item['mobileHideLabel']) && $item['mobileHideLabel'] ? ' mobile-icon-only' : '' }} sub-nav-item"
                   @if(isset($item['title']))title="{{ $item['title'] }}"@endif
                   style="position: relative;">
                    @if(isset($item['icon']))<i class="fas {{ $item['icon'] }} menu-icon"></i>@endif
                    @if(isset($item['label']))<span class="sub-nav-text">{{ $item['label'] }}</span>@endif
                    @if(isset($item['badgeCount']) && $item['badgeCount'] > 0)
                        <span class="menu-badge">{{ $item['badgeCount'] }}</span>
                    @endif
                </a>
            @endforeach
        </div>
        @endif
        @endauth
        <div class="content">
            @yield('content')
        </div>
        @auth
        @if(app()->environment('local') || (auth()->check() && auth()->user()->hasRole('Admin')) || session()->has('impersonator_id'))
        <footer>
            <div class="container">
                @if(isset($queryCount))
                    <pre class="git-log">Queries: {{ $queryCount }} | Database: {{ $dbConnection ?? 'unknown' }} ({{ $dbDriver ?? 'unknown' }}) | Name: {{ $dbName ?? 'unknown' }} | User: {{ $dbUsername ?? 'unknown' }}</pre><br>
                @endif
                @if(isset($gitBranch))
                    <pre class="git-log">Branch: {{ $gitBranch }}</pre>
                @endif
                @if(isset($gitLog))
                    <pre class="git-log">{{ $gitLog }}</pre>
                @endif
                <br />
                <pre class="git-log">SQL Queries:</pre>
                @if(isset($queries) && count($queries) > 0)
                    @foreach($queries as $index => $query)
                        <pre class="git-log">Query #{{ $index + 1 }} ({{ number_format($query['time'], 2) }}ms): {{ $query['query'] }}@if(!empty($query['bindings'])) | Bindings: {{ json_encode($query['bindings']) }}@endif</pre>
                    @endforeach
                @endif
                <br />
                <pre class="git-log">Application Logs:</pre>
                @if(isset($logs) && count($logs) > 0)
                    @foreach($logs as $log)
                        <pre class="git-log" style="color: {{ $log['level'] === 'error' ? '#ff6b6b' : ($log['level'] === 'warning' ? '#ffa500' : '#f2f2f2') }}; white-space: pre-wrap; word-wrap: break-word;">[{{ strtoupper($log['level']) }}] {{ $log['message'] }}@if(!empty($log['context']))
Context: {{ json_encode($log['context'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}@endif</pre>
                    @endforeach
                @else
                    <pre class="git-log" style="color: #888;">No logs for this request</pre>
                @endif
            </div>
        </footer>
        @endif
        @endauth

        <script>
            document.addEventListener('DOMContentLoaded', function() {
                // Initialize password visibility toggles
                initializePasswordToggles();
            });

            /**
             * Toggle password field visibility between text and password types
             * @param {string} targetId - The ID of the password input field to toggle
             */
            function togglePasswordVisibility(targetId) {
                const field = document.getElementById(targetId);
                const button = document.querySelector(`[data-target="${targetId}"]`);
                
                if (!field || !button) {
                    console.warn(`Password toggle: Could not find field or button for target ID: ${targetId}`);
                    return;
                }

                const icon = button.querySelector('i');
                if (!icon) {
                    console.warn(`Password toggle: Could not find icon in button for target ID: ${targetId}`);
                    return;
                    }
                
                if (field.type === 'password') {
                    // Show password
                    field.type = 'text';
                    icon.classList.remove('fa-eye');
                    icon.classList.add('fa-eye-slash');
                    button.setAttribute('aria-label', 'Hide password');
                } else {
                    // Hide password
                    field.type = 'password';
                    icon.classList.remove('fa-eye-slash');
                    icon.classList.add('fa-eye');
                    button.setAttribute('aria-label', 'Show password');
                }
            }

            /**
             * Initialize password visibility toggle functionality
             * Sets up event listeners for all password toggle buttons
             */
            function initializePasswordToggles() {
                const toggleButtons = document.querySelectorAll('.password-toggle');
                
                toggleButtons.forEach(button => {
                    const targetId = button.getAttribute('data-target');
                    
                    if (!targetId) {
                        console.warn('Password toggle button found without data-target attribute');
                        return;
                    }

                    // Set initial aria-label
                    if (!button.getAttribute('aria-label')) {
                        button.setAttribute('aria-label', 'Show password');
                    }

                    // Add click event listener
                    button.addEventListener('click', function(e) {
                        e.preventDefault();
                        togglePasswordVisibility(targetId);
                    });

                    // Add keyboard support (Enter and Space keys)
                    button.addEventListener('keydown', function(e) {
                        if (e.key === 'Enter' || e.key === ' ') {
                            e.preventDefault();
                            togglePasswordVisibility(targetId);
                        }
                    });

                    // Ensure button is focusable
                    if (!button.hasAttribute('tabindex')) {
                        button.setAttribute('tabindex', '0');
                    }
                });
            }
        </script>
    </body>
</html>
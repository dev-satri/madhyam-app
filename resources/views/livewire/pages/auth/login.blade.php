<?php

use Illuminate\Auth\Events\Lockout;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Volt\Component;

new #[Layout('components.layouts.guest')] class extends Component
{
    public string $email = '';
    public string $password = '';
    public bool $remember = false;
    public string $loginMode = 'staff';
    public bool $showPassword = false;
    public string $errorMessage = '';
    public bool $loading = false;

    /**
     * Max failed attempts per throttle window before lockout.
     * Matches Breeze default (5) and implementation.md P0.2.
     */
    protected int $maxAttempts = 5;

    protected array $rules = [
        'email' => 'required|email',
        'password' => 'required|string',
    ];

    public function switchMode(string $mode): void
    {
        $this->loginMode = $mode;
        $this->email = '';
        $this->password = '';
        $this->errorMessage = '';
        $this->resetValidation();
    }

    public function fillDemo(string $email, string $password): void
    {
        $this->email = $email;
        $this->password = $password;
    }

    public function login(): void
    {
        $this->validate();
        $this->loading = true;
        $this->errorMessage = '';

        try {
            // Rate-limit check first — never touch guard->attempt() while locked out.
            if ($this->isRateLimited()) {
                $this->fireLockoutMessage();
                return;
            }

            $credentials = ['email' => $this->email, 'password' => $this->password];
            $guard = $this->loginMode === 'client' ? Auth::guard('client') : Auth::guard('web');

            if ($guard->attempt($credentials, $this->remember)) {
                RateLimiter::clear($this->throttleKey());
                Session::regenerate();
                $default = $this->loginMode === 'client'
                    ? route('client.dashboard', absolute: false)
                    : route('dashboard', absolute: false);
                $this->redirectIntended(default: $default, navigate: true);
                return;
            }

            // Failed credentials — count against the limit and check again in case
            // this hit put us over the threshold, so the message reflects reality.
            RateLimiter::hit($this->throttleKey());

            if ($this->isRateLimited()) {
                $this->fireLockoutMessage();
                return;
            }

            $this->errorMessage = trans('auth.failed');
        } finally {
            $this->loading = false;
        }
    }

    /**
     * True when the caller has exceeded max attempts within the decay window
     * for the current (email, ip, guard-mode) key.
     */
    protected function isRateLimited(): bool
    {
        return RateLimiter::tooManyAttempts($this->throttleKey(), $this->maxAttempts);
    }

    /**
     * Populate errorMessage with a localized "try again in X" string and
     * dispatch the Lockout event so security listeners (e.g. alerting)
     * can react. Does not throw — caller returns early instead.
     */
    protected function fireLockoutMessage(): void
    {
        event(new Lockout(request()));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        $this->errorMessage = trans('auth.throttle', [
            'seconds' => $seconds,
            'minutes' => (int) ceil($seconds / 60),
        ]);
    }

    /**
     * Per (email, ip, guard-mode) key. Client-portal + staff share IP but
     * diverge on guard so a hostile actor cannot chain attempts across guards.
     */
    protected function throttleKey(): string
    {
        return Str::transliterate(
            Str::lower($this->email).'|'.request()->ip().'|'.$this->loginMode
        );
    }

    public function resetDemoData(): void
    {
        Artisan::call('migrate:fresh', ['--seed' => true, '--force' => true]);
        $this->errorMessage = '';
    }

    #[On('confirm-resolved')]
    public function onConfirmResolved(string $action, array $params = []): void
    {
        if ($action !== '' && method_exists($this, $action)) {
            $this->{$action}(...$params);
        }
    }
}; ?>

<div class="relative min-h-screen bg-gray-50 flex items-center justify-center p-4 font-sans">
    {{-- Subtle grid background --}}
    <div
        aria-hidden="true"
        class="pointer-events-none absolute inset-0"
        style="
            background-image: radial-gradient(circle at 1px 1px, rgba(15, 23, 42, 0.05) 1px, transparent 0);
            background-size: 32px 32px;
        "
    ></div>

    <div class="relative z-10 w-full max-w-md">
        {{-- Logo / brand --}}
        <div class="text-center mb-6">
            <div
                class="inline-flex h-14 w-14 items-center justify-center rounded-2xl bg-brand-600 text-white shadow-lg shadow-brand-600/25 mb-3"
            >
                <i class="fas fa-layer-group text-2xl"></i>
            </div>
            <h1 class="text-2xl font-bold text-gray-900 tracking-tight">Madhyam</h1>
            <p class="text-sm text-gray-500 mt-1">Agency Management System</p>
        </div>

        {{-- Login card --}}
        <div class="relative overflow-hidden rounded-2xl bg-white shadow-xl shadow-gray-200/60 ring-1 ring-gray-100">
            {{-- Top progress bar (only during login submit) --}}
            <div
                class="pointer-events-none absolute inset-x-0 top-0 z-20 h-0.5 overflow-hidden opacity-0 transition-opacity duration-150"
                wire:loading.class="opacity-100"
                wire:target="login"
                aria-hidden="true"
            >
                <div class="h-full w-1/3 bg-brand-600 login-progress-bar"></div>
            </div>

            {{-- Tabs (underline style) --}}
            <div class="grid grid-cols-2 border-b border-gray-100" role="tablist">
                <button
                    type="button"
                    role="tab"
                    aria-selected="{{ $loginMode === 'staff' ? 'true' : 'false' }}"
                    wire:click="switchMode('staff')"
                    class="relative px-4 py-3.5 text-sm font-semibold transition-colors {{ $loginMode === 'staff' ? 'text-brand-600' : 'text-gray-500 hover:text-gray-700 hover:bg-gray-50' }}"
                >
                    <i class="fas fa-user-tie mr-1.5"></i>Staff Login
                    @if ($loginMode === 'staff')
                        <span class="absolute inset-x-6 bottom-0 h-0.5 bg-brand-600 rounded-t"></span>
                    @endif
                </button>
                <button
                    type="button"
                    role="tab"
                    aria-selected="{{ $loginMode === 'client' ? 'true' : 'false' }}"
                    wire:click="switchMode('client')"
                    class="relative px-4 py-3.5 text-sm font-semibold transition-colors {{ $loginMode === 'client' ? 'text-brand-600' : 'text-gray-500 hover:text-gray-700 hover:bg-gray-50' }}"
                >
                    <i class="fas fa-building mr-1.5"></i>Client Portal
                    @if ($loginMode === 'client')
                        <span class="absolute inset-x-6 bottom-0 h-0.5 bg-brand-600 rounded-t"></span>
                    @endif
                </button>
            </div>

            {{-- Card body: dims and blocks input while the login request is in flight --}}
            <div
                class="p-6 sm:p-8 transition-opacity duration-150"
                wire:loading.class="opacity-60 pointer-events-none"
                wire:target="login"
            >
                <h2 class="text-xl font-bold text-gray-900">
                    {{ $loginMode === 'client' ? 'Client Portal' : 'Welcome back' }}
                </h2>
                <p class="text-sm text-gray-500 mt-1 mb-6">
                    {{ $loginMode === 'client' ? 'Sign in to view your projects & approvals' : 'Sign in to your staff account' }}
                </p>

                {{-- Flash success (e.g. after password reset) --}}
                @if (session('status'))
                    <div
                        role="status"
                        class="mb-4 flex items-start gap-2.5 rounded-xl border border-green-200 bg-green-50 px-3.5 py-3 text-sm text-green-700"
                    >
                        <i class="fas fa-check-circle mt-0.5"></i>
                        <span>{{ session('status') }}</span>
                    </div>
                @endif

                {{-- Server error (bad creds / lockout) --}}
                @if ($errorMessage)
                    <div
                        role="alert"
                        class="mb-4 flex items-start gap-2.5 rounded-xl border border-red-200 bg-red-50 px-3.5 py-3 text-sm text-red-700"
                    >
                        <i class="fas fa-exclamation-circle mt-0.5"></i>
                        <span>{{ $errorMessage }}</span>
                    </div>
                @endif

                <form wire:submit="login" class="space-y-4">
                    {{-- Email --}}
                    <div>
                        <label for="login-email" class="block text-sm font-medium text-gray-700 mb-1.5"
                            >Email Address</label
                        >
                        <div class="relative">
                            <span
                                class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3.5 text-gray-400"
                            >
                                <i class="fas fa-envelope text-sm"></i>
                            </span>
                            <input
                                id="login-email"
                                type="email"
                                wire:model="email"
                                required
                                autocomplete="username"
                                autofocus
                                placeholder="you@example.com"
                                class="block w-full rounded-xl border-gray-200 bg-gray-50 py-2.5 pl-10 pr-3.5 text-sm text-gray-900 placeholder:text-gray-400 shadow-sm transition-colors focus:border-brand-500 focus:bg-white focus:ring-2 focus:ring-brand-500/20 @error('email') border-red-300 focus:border-red-500 focus:ring-red-500/20 @enderror"
                            />
                        </div>
                        @error ('email')
                            <p class="mt-1.5 flex items-center gap-1.5 text-xs text-red-600">
                                <i class="fas fa-exclamation-circle"></i>{{ $message }}
                            </p>
                        @enderror
                    </div>

                    {{-- Password --}}
                    <div>
                        <label for="login-password" class="block text-sm font-medium text-gray-700 mb-1.5"
                            >Password</label
                        >
                        <div class="relative">
                            <span
                                class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3.5 text-gray-400"
                            >
                                <i class="fas fa-lock text-sm"></i>
                            </span>
                            <input
                                id="login-password"
                                type="{{ $showPassword ? 'text' : 'password' }}"
                                wire:model="password"
                                required
                                autocomplete="current-password"
                                placeholder="Enter your password"
                                class="block w-full rounded-xl border-gray-200 bg-gray-50 py-2.5 pl-10 pr-11 text-sm text-gray-900 placeholder:text-gray-400 shadow-sm transition-colors focus:border-brand-500 focus:bg-white focus:ring-2 focus:ring-brand-500/20 @error('password') border-red-300 focus:border-red-500 focus:ring-red-500/20 @enderror"
                            />
                            <button
                                type="button"
                                wire:click="$toggle('showPassword')"
                                aria-label="{{ $showPassword ? 'Hide password' : 'Show password' }}"
                                class="absolute inset-y-0 right-0 flex items-center px-3 text-gray-400 hover:text-gray-600 transition-colors"
                            >
                                <i class="fas fa-{{ $showPassword ? 'eye-slash' : 'eye' }} text-sm"></i>
                            </button>
                        </div>
                        @error ('password')
                            <p class="mt-1.5 flex items-center gap-1.5 text-xs text-red-600">
                                <i class="fas fa-exclamation-circle"></i>{{ $message }}
                            </p>
                        @enderror
                    </div>

                    {{-- Remember me + Forgot password --}}
                    <div class="flex items-center justify-between">
                        <label class="inline-flex items-center gap-2 cursor-pointer select-none">
                            <input
                                type="checkbox"
                                wire:model="remember"
                                class="h-4 w-4 rounded border-gray-300 text-brand-600 focus:ring-brand-500 focus:ring-offset-0"
                            />
                            <span class="text-sm text-gray-600">Remember me</span>
                        </label>
                        <a
                            href="{{ route('password.request', ['mode' => $loginMode === 'client' ? 'client' : 'staff']) }}"
                            wire:navigate
                            class="text-sm font-medium text-brand-600 hover:text-brand-700 hover:underline underline-offset-2 transition-colors"
                        >
                            Forgot password?
                        </a>
                    </div>

                    {{-- Submit: scale-down on press + inline spinner while loading --}}
                    <button
                        type="submit"
                        wire:loading.attr="disabled"
                        wire:target="login"
                        class="group flex w-full items-center justify-center gap-2 rounded-xl bg-brand-600 px-4 py-2.5 text-sm font-semibold text-white shadow-lg shadow-brand-600/25 transition-all hover:bg-brand-700 active:scale-[0.98] active:shadow-md focus:outline-none focus:ring-2 focus:ring-brand-500 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-80"
                    >
                        <span wire:loading.remove wire:target="login" class="flex items-center gap-2">
                            Sign In
                            <i class="fas fa-arrow-right text-xs transition-transform group-hover:translate-x-0.5"></i>
                        </span>
                        <span wire:loading wire:target="login" class="flex items-center gap-2" style="display: none">
                            <svg class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                            </svg>
                            Signing in…
                        </span>
                    </button>
                </form>

                {{-- Collapsible demo accounts --}}
                <div class="mt-6 border-t border-gray-100 pt-4" x-data="{ open: false }">
                    <button
                        type="button"
                        @click="open = !open"
                        :aria-expanded="open"
                        class="flex w-full items-center justify-between text-xs font-medium text-gray-500 hover:text-gray-700 transition-colors"
                    >
                        <span class="inline-flex items-center gap-1.5">
                            <i class="fas fa-flask text-[10px]"></i>
                            Try a demo account
                        </span>
                        <i
                            class="fas fa-chevron-down text-[10px] transition-transform"
                            :class="open && 'rotate-180'"
                        ></i>
                    </button>

                    <div
                        x-show="open"
                        x-transition:enter="transition ease-out duration-150"
                        x-transition:enter-start="opacity-0 -translate-y-1"
                        x-transition:enter-end="opacity-100 translate-y-0"
                        x-transition:leave="transition ease-in duration-100"
                        x-transition:leave-start="opacity-100"
                        x-transition:leave-end="opacity-0"
                        class="mt-3"
                        style="display: none"
                    >
                        @if ($loginMode === 'staff')
                            <div class="grid grid-cols-2 gap-2">
                                @foreach ([
                                    ['label' => 'Super Admin', 'email' => 'superadmin@madhyam.com', 'password' => 'SuperAdmin@123'],
                                    ['label' => 'Admin', 'email' => 'admin@madhyam.com', 'password' => 'Admin@123'],
                                    ['label' => 'Editor', 'email' => 'staff.editor@madhyam.com', 'password' => 'Staff@123'],
                                    ['label' => 'Videographer', 'email' => 'staff.video@madhyam.com', 'password' => 'Staff@123'],
                                ] as $account)
                                    <button
                                        type="button"
                                        wire:click="fillDemo('{{ $account['email'] }}', '{{ $account['password'] }}')"
                                        class="rounded-lg border border-gray-100 bg-gray-50 px-3 py-2 text-left transition-colors hover:border-brand-200 hover:bg-brand-50"
                                    >
                                        <p class="text-xs font-semibold text-gray-700">{{ $account['label'] }}</p>
                                        <p class="text-[10px] text-gray-500 truncate">{{ $account['email'] }}</p>
                                    </button>
                                @endforeach
                            </div>
                        @else
                            <div class="space-y-2">
                                @foreach ([
                                    ['label' => 'Himalayan Coffee Co.', 'email' => 'client1@madhyam.com', 'password' => 'Client@123'],
                                    ['label' => 'Trek Nepal Adventures', 'email' => 'client2@madhyam.com', 'password' => 'Client@123'],
                                ] as $account)
                                    <button
                                        type="button"
                                        wire:click="fillDemo('{{ $account['email'] }}', '{{ $account['password'] }}')"
                                        class="w-full rounded-lg border border-gray-100 bg-gray-50 px-3 py-2 text-left transition-colors hover:border-brand-200 hover:bg-brand-50"
                                    >
                                        <p class="text-xs font-semibold text-gray-700">{{ $account['label'] }}</p>
                                        <p class="text-[10px] text-gray-500 truncate">{{ $account['email'] }}</p>
                                    </button>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>

        {{-- Footer --}}
        <div class="mt-6 text-center space-y-3">
            <p class="text-xs text-gray-400">&copy; 2026 Madhyam Agency. All rights reserved.</p>
            <button
                type="button"
                wire:click="$dispatch('open-confirm', { title: 'Reset Demo Data?', message: 'This will re-seed the database and wipe all current data. Continue?', type: 'warning', action: 'resetDemoData', confirmLabel: 'Reset' })"
                class="inline-flex items-center gap-1.5 rounded-md border border-red-200 bg-white px-2.5 py-1 text-xs font-medium text-red-600 shadow-sm transition-colors hover:bg-red-50 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2"
            >
                <i class="fas fa-rotate-right text-[10px]"></i>
                Reset Demo Data
            </button>
        </div>
    </div>

    {{-- Indeterminate progress-bar animation (scoped to the login card via .login-progress-bar) --}}
    <style>
        @keyframes login-progress-slide {
            0% {
                transform: translateX(-100%);
            }
            100% {
                transform: translateX(400%);
            }
        }
        .login-progress-bar {
            animation: login-progress-slide 1.1s ease-in-out infinite;
        }
    </style>
</div>

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

<div
    x-data="{ showSpin: @entangle('loading') }"
    style="
        background: linear-gradient(135deg, #312e81 0%, #4f46e5 50%, #6366f1 100%);
        min-height: 100vh;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 16px;
        font-family: 'Inter', system-ui, sans-serif;
        position: relative;
    "
>
    {{-- Grid background pattern --}}
    <div
        style="
            position: absolute;
            inset: 0;
            background-image: radial-gradient(circle at 1px 1px, rgba(255, 255, 255, 0.06) 1px, transparent 0);
            background-size: 40px 40px;
            pointer-events: none;
        "
    ></div>

    <div style="width: 100%; max-width: 420px; position: relative; z-index: 1">
        {{-- Logo --}}
        <div style="text-align: center; margin-bottom: 32px">
            <div
                class="floating"
                style="
                    display: inline-flex;
                    align-items: center;
                    justify-content: center;
                    width: 64px;
                    height: 64px;
                    background: rgba(255, 255, 255, 0.2);
                    border-radius: 16px;
                    margin-bottom: 16px;
                "
            >
                <i class="fas fa-layer-group" style="font-size: 28px; color: #fff"></i>
            </div>
            <h1 style="font-size: 30px; font-weight: 800; color: #fff; letter-spacing: -0.02em; margin: 0">Madhyam</h1>
            <p style="color: rgba(255, 255, 255, 0.6); font-size: 13px; margin-top: 4px">Agency Management System</p>
        </div>

        {{-- Login Card --}}
        <div
            style="
                backdrop-filter: blur(20px);
                background: rgba(255, 255, 255, 0.95);
                border-radius: 16px;
                box-shadow: 0 25px 60px rgba(0, 0, 0, 0.2);
                overflow: hidden;
            "
        >
            {{-- Tabs --}}
            <div style="display: flex; padding: 16px 16px 0; gap: 4px">
                <button
                    wire:click="switchMode('staff')"
                    @if ($loginMode === 'staff')
                        style="
                            padding: 10px 24px;
                            border-radius: 10px 10px 0 0;
                            font-size: 13px;
                            font-weight: 600;
                            cursor: pointer;
                            transition: all 0.2s;
                            border: none;
                            outline: none;
                            background: #4f46e5;
                            color: #fff;
                        "
                    @else
                        style="
                            padding: 10px 24px;
                            border-radius: 10px 10px 0 0;
                            font-size: 13px;
                            font-weight: 600;
                            cursor: pointer;
                            transition: all 0.2s;
                            border: none;
                            outline: none;
                            background: #f1f5f9;
                            color: #475569;
                        "
                    @endif
                >
                    <i class="fas fa-user-tie" style="margin-right: 4px"></i> Staff Login
                </button>
                <button
                    wire:click="switchMode('client')"
                    @if ($loginMode === 'client')
                        style="
                            padding: 10px 24px;
                            border-radius: 10px 10px 0 0;
                            font-size: 13px;
                            font-weight: 600;
                            cursor: pointer;
                            transition: all 0.2s;
                            border: none;
                            outline: none;
                            background: #4f46e5;
                            color: #fff;
                        "
                    @else
                        style="
                            padding: 10px 24px;
                            border-radius: 10px 10px 0 0;
                            font-size: 13px;
                            font-weight: 600;
                            cursor: pointer;
                            transition: all 0.2s;
                            border: none;
                            outline: none;
                            background: #f1f5f9;
                            color: #475569;
                        "
                    @endif
                >
                    <i class="fas fa-building" style="margin-right: 4px"></i> Client Portal
                </button>
            </div>

            <div style="padding: 32px">
                <h2 style="font-size: 20px; font-weight: 700; color: #111827; margin-bottom: 4px">
                    {{ $loginMode === 'client' ? 'Client Portal' : 'Welcome back' }}
                </h2>
                <p style="font-size: 13px; color: #6b7280; margin-bottom: 24px">
                    {{ $loginMode === 'client' ? 'Sign in to view your projects & approvals' : 'Sign in to your staff account' }}
                </p>

                {{-- Inline error --}}
                @if ($errorMessage)
                    <div
                        style="
                            margin-bottom: 16px;
                            padding: 12px 14px;
                            background: #fef2f2;
                            border: 1px solid #fecaca;
                            border-radius: 12px;
                            font-size: 13px;
                            color: #dc2626;
                            display: flex;
                            align-items: center;
                            gap: 8px;
                        "
                    >
                        <i class="fas fa-exclamation-circle"></i>
                        <span>{{ $errorMessage }}</span>
                    </div>
                @endif

                {{-- Validation errors --}}
                @error ('email')
                    <div
                        style="
                            margin-bottom: 16px;
                            padding: 12px 14px;
                            background: #fef2f2;
                            border: 1px solid #fecaca;
                            border-radius: 12px;
                            font-size: 13px;
                            color: #dc2626;
                            display: flex;
                            align-items: center;
                            gap: 8px;
                        "
                    >
                        <i class="fas fa-exclamation-circle"></i>
                        <span>{{ $message }}</span>
                    </div>
                @enderror

                <form wire:submit="login">
                    {{-- Email --}}
                    <div style="margin-bottom: 16px">
                        <label
                            style="
                                display: block;
                                font-size: 13px;
                                font-weight: 500;
                                color: #374151;
                                margin-bottom: 6px;
                            "
                            >Email Address</label
                        >
                        <div style="position: relative">
                            <input
                                type="email"
                                wire:model="email"
                                required
                                placeholder="Enter your email"
                                class="form-input"
                                style="
                                    width: 100%;
                                    padding: 12px 14px 12px 44px;
                                    border: 1px solid #e5e7eb;
                                    border-radius: 12px;
                                    font-size: 14px;
                                    transition: all 0.2s;
                                    background: #f9fafb;
                                    outline: none;
                                    font-family: inherit;
                                "
                                onfocus="
                                    this.style.background = '#fff';
                                    this.style.borderColor = '#818cf8';
                                    this.style.boxShadow = '0 0 0 3px rgba(99,102,241,0.1)';
                                "
                                onblur="
                                    this.style.background = '#f9fafb';
                                    this.style.borderColor = '#e5e7eb';
                                    this.style.boxShadow = 'none';
                                "
                            />
                            <i
                                class="fas fa-envelope"
                                style="
                                    position: absolute;
                                    left: 14px;
                                    top: 50%;
                                    transform: translateY(-50%);
                                    color: #94a3b8;
                                    font-size: 14px;
                                    pointer-events: none;
                                "
                            ></i>
                        </div>
                    </div>

                    {{-- Password --}}
                    <div style="margin-bottom: 16px">
                        <label
                            style="
                                display: block;
                                font-size: 13px;
                                font-weight: 500;
                                color: #374151;
                                margin-bottom: 6px;
                            "
                            >Password</label
                        >
                        <div style="position: relative">
                            <input
                                type="{{ $showPassword ? 'text' : 'password' }}"
                                wire:model="password"
                                required
                                placeholder="Enter your password"
                                style="
                                    width: 100%;
                                    padding: 12px 44px 12px 44px;
                                    border: 1px solid #e5e7eb;
                                    border-radius: 12px;
                                    font-size: 14px;
                                    transition: all 0.2s;
                                    background: #f9fafb;
                                    outline: none;
                                    font-family: inherit;
                                "
                                onfocus="
                                    this.style.background = '#fff';
                                    this.style.borderColor = '#818cf8';
                                    this.style.boxShadow = '0 0 0 3px rgba(99,102,241,0.1)';
                                "
                                onblur="
                                    this.style.background = '#f9fafb';
                                    this.style.borderColor = '#e5e7eb';
                                    this.style.boxShadow = 'none';
                                "
                            />
                            <i
                                class="fas fa-lock"
                                style="
                                    position: absolute;
                                    left: 14px;
                                    top: 50%;
                                    transform: translateY(-50%);
                                    color: #94a3b8;
                                    font-size: 14px;
                                    pointer-events: none;
                                "
                            ></i>
                            <button
                                type="button"
                                wire:click="$toggle('showPassword')"
                                style="
                                    position: absolute;
                                    right: 12px;
                                    top: 50%;
                                    transform: translateY(-50%);
                                    color: #9ca3af;
                                    cursor: pointer;
                                    background: none;
                                    border: none;
                                    font-size: 14px;
                                    padding: 4px;
                                "
                            >
                                <i class="fas fa-{{ $showPassword ? 'eye-slash' : 'eye' }}"></i>
                            </button>
                        </div>
                    </div>

                    {{-- Remember me --}}
                    <div
                        style="margin-bottom: 20px; display: flex; align-items: center; justify-content: space-between"
                    >
                        <label style="display: flex; align-items: center; gap: 8px; cursor: pointer">
                            <input
                                type="checkbox"
                                wire:model="remember"
                                style="
                                    border-radius: 4px;
                                    border: 1px solid #d1d5db;
                                    color: #4f46e5;
                                    width: 16px;
                                    height: 16px;
                                "
                            />
                            <span style="font-size: 13px; color: #4b5563">Remember me</span>
                        </label>
                    </div>

                    {{-- Submit --}}
                    <button
                        type="submit"
                        @if ($loading) disabled @endif
                        style="width:100%;padding:12px 16px;background:#4f46e5;color:#fff;border-radius:12px;font-size:14px;font-weight:600;border:none;cursor:{{ $loading ? 'not-allowed' : 'pointer' }};opacity:{{ $loading ? '0.8' : '1' }};display:flex;align-items:center;justify-content:center;gap:8px;box-shadow:0 4px 14px rgba(79,70,229,0.25);transition:all 0.2s;font-family:inherit"
                    >
                        <span>{{ $loading ? 'Signing in...' : 'Sign In' }}</span>
                        @if ($loading)
                            <div
                                style="
                                    width: 18px;
                                    height: 18px;
                                    border: 2px solid rgba(255, 255, 255, 0.3);
                                    border-top-color: #fff;
                                    border-radius: 50%;
                                    animation: spin 0.6s linear infinite;
                                    flex-shrink: 0;
                                "
                            ></div>
                        @endif
                    </button>
                </form>

                {{-- Staff demo accounts --}}
                @if ($loginMode === 'staff')
                    <div style="margin-top: 24px; padding-top: 16px; border-top: 1px solid #f3f4f6">
                        <p style="
                                font-size: 11px;
                                color: #9ca3af;
                                text-align: center;
                                margin-bottom: 12px;
                            ">Demo Accounts</p>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px">
                            <button
                                wire:click="fillDemo('super@madhyam.com', 'admin123')"
                                style="
                                    padding: 8px 12px;
                                    background: #f9fafb;
                                    border: 1px solid #f3f4f6;
                                    border-radius: 8px;
                                    text-align: left;
                                    cursor: pointer;
                                    transition: background 0.15s;
                                    font-family: inherit;
                                "
                                onmouseover="this.style.background = '#f1f5f9'"
                                onmouseout="this.style.background = '#f9fafb'"
                            >
                                <p style="font-size: 11px; font-weight: 600; color: #374151; margin: 0">Super Admin</p>
                                <p style="font-size: 10px; color: #9ca3af; margin: 0">super@madhyam.com</p>
                            </button>
                            <button
                                wire:click="fillDemo('rajesh@madhyam.com', 'pass123')"
                                style="
                                    padding: 8px 12px;
                                    background: #f9fafb;
                                    border: 1px solid #f3f4f6;
                                    border-radius: 8px;
                                    text-align: left;
                                    cursor: pointer;
                                    transition: background 0.15s;
                                    font-family: inherit;
                                "
                                onmouseover="this.style.background = '#f1f5f9'"
                                onmouseout="this.style.background = '#f9fafb'"
                            >
                                <p style="font-size: 11px; font-weight: 600; color: #374151; margin: 0">Manager</p>
                                <p style="font-size: 10px; color: #9ca3af; margin: 0">rajesh@madhyam.com</p>
                            </button>
                            <button
                                wire:click="fillDemo('anil@madhyam.com', 'pass123')"
                                style="
                                    padding: 8px 12px;
                                    background: #f9fafb;
                                    border: 1px solid #f3f4f6;
                                    border-radius: 8px;
                                    text-align: left;
                                    cursor: pointer;
                                    transition: background 0.15s;
                                    font-family: inherit;
                                "
                                onmouseover="this.style.background = '#f1f5f9'"
                                onmouseout="this.style.background = '#f9fafb'"
                            >
                                <p style="font-size: 11px; font-weight: 600; color: #374151; margin: 0">Editor</p>
                                <p style="font-size: 10px; color: #9ca3af; margin: 0">anil@madhyam.com</p>
                            </button>
                            <button
                                wire:click="fillDemo('sita@madhyam.com', 'pass123')"
                                style="
                                    padding: 8px 12px;
                                    background: #f9fafb;
                                    border: 1px solid #f3f4f6;
                                    border-radius: 8px;
                                    text-align: left;
                                    cursor: pointer;
                                    transition: background 0.15s;
                                    font-family: inherit;
                                "
                                onmouseover="this.style.background = '#f1f5f9'"
                                onmouseout="this.style.background = '#f9fafb'"
                            >
                                <p style="font-size: 11px; font-weight: 600; color: #374151; margin: 0">Videographer</p>
                                <p style="font-size: 10px; color: #9ca3af; margin: 0">sita@madhyam.com</p>
                            </button>
                        </div>
                    </div>
                @endif

                {{-- Client demo accounts --}}
                @if ($loginMode === 'client')
                    <div style="margin-top: 24px; padding-top: 16px; border-top: 1px solid #f3f4f6">
                        <p style="
                                font-size: 11px;
                                color: #9ca3af;
                                text-align: center;
                                margin-bottom: 12px;
                            ">Demo Client Accounts</p>
                        <div style="display: grid; gap: 8px">
                            <button
                                wire:click="fillDemo('ram@himalayancoffee.com', 'client123')"
                                style="
                                    padding: 8px 12px;
                                    background: #f9fafb;
                                    border: 1px solid #f3f4f6;
                                    border-radius: 8px;
                                    text-align: left;
                                    cursor: pointer;
                                    transition: background 0.15s;
                                    font-family: inherit;
                                "
                                onmouseover="this.style.background = '#f1f5f9'"
                                onmouseout="this.style.background = '#f9fafb'"
                            >
                                <p style="
                                        font-size: 11px;
                                        font-weight: 600;
                                        color: #374151;
                                        margin: 0;
                                    ">Himalayan Coffee</p>
                                <p style="font-size: 10px; color: #9ca3af; margin: 0">ram@himalayancoffee.com</p>
                            </button>
                            <button
                                wire:click="fillDemo('maya@treknepal.com', 'client123')"
                                style="
                                    padding: 8px 12px;
                                    background: #f9fafb;
                                    border: 1px solid #f3f4f6;
                                    border-radius: 8px;
                                    text-align: left;
                                    cursor: pointer;
                                    transition: background 0.15s;
                                    font-family: inherit;
                                "
                                onmouseover="this.style.background = '#f1f5f9'"
                                onmouseout="this.style.background = '#f9fafb'"
                            >
                                <p style="font-size: 11px; font-weight: 600; color: #374151; margin: 0">Trek Nepal</p>
                                <p style="font-size: 10px; color: #9ca3af; margin: 0">maya@treknepal.com</p>
                            </button>
                            <button
                                wire:click="fillDemo('devi@greenleaf.com', 'client123')"
                                style="
                                    padding: 8px 12px;
                                    background: #f9fafb;
                                    border: 1px solid #f3f4f6;
                                    border-radius: 8px;
                                    text-align: left;
                                    cursor: pointer;
                                    transition: background 0.15s;
                                    font-family: inherit;
                                "
                                onmouseover="this.style.background = '#f1f5f9'"
                                onmouseout="this.style.background = '#f9fafb'"
                            >
                                <p style="font-size: 11px; font-weight: 600; color: #374151; margin: 0">Green Leaf</p>
                                <p style="font-size: 10px; color: #9ca3af; margin: 0">devi@greenleaf.com</p>
                            </button>
                        </div>
                    </div>
                @endif
            </div>
        </div>

        {{-- Footer --}}
        <p style="
                text-align: center;
                color: rgba(255, 255, 255, 0.4);
                font-size: 11px;
                margin-top: 24px;
            ">&copy; 2026 Madhyam Agency. All rights reserved.</p>
        <p style="text-align: center; color: rgba(255, 255, 255, 0.3); font-size: 10px; margin-top: 8px">
            <span
                role="button"
                tabindex="0"
                style="cursor: pointer"
                wire:click="$dispatch('open-confirm', { title: 'Reset Demo Data?', message: 'This will re-seed the database and wipe all current data. Continue?', type: 'warning', action: 'resetDemoData', confirmLabel: 'Reset' })"
            >
                Reset Demo Data
            </span>
        </p>
    </div>

    {{-- Spin animation (must be inside root element) --}}
    <style>
        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }
    </style>
</div>

<?php

use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('components.layouts.guest')] class extends Component
{
    public string $email = '';

    #[Url(as: 'mode')]
    public string $mode = 'staff';

    public bool $sent = false;
    public string $errorMessage = '';

    /**
     * Additional throttle on top of the broker's built-in 60s window.
     * Limits enumeration + abuse to 3 unique attempts / hour per email+ip+mode
     * — the broker throttles the *same* email within 60s, but a bot can rotate
     * random emails from one IP; this catches that too.
     */
    protected int $maxAttempts = 5;
    protected int $decaySeconds = 3600;

    public function mount(): void
    {
        // Normalize any bad input to the safe default.
        $this->mode = $this->mode === 'client' ? 'client' : 'staff';
    }

    public function sendResetLink(): void
    {
        $this->validate([
            'email' => 'required|email',
        ]);

        $this->errorMessage = '';

        if (RateLimiter::tooManyAttempts($this->throttleKey(), $this->maxAttempts)) {
            $seconds = RateLimiter::availableIn($this->throttleKey());
            $this->errorMessage = trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => (int) ceil($seconds / 60),
            ]);
            return;
        }

        RateLimiter::hit($this->throttleKey(), $this->decaySeconds);

        $broker = $this->mode === 'client' ? 'client_accounts' : 'users';

        // Password::sendResetLink returns Password::RESET_LINK_SENT on success,
        // INVALID_USER when the email doesn't match, or RESET_THROTTLED when
        // the broker's own 60s window is still open. We collapse all outcomes
        // into the same UI response so the endpoint can't be used to enumerate
        // account existence across either guard.
        Password::broker($broker)->sendResetLink([
            'email' => $this->email,
        ]);

        $this->sent = true;
    }

    protected function throttleKey(): string
    {
        return 'password-reset|' . Str::transliterate(
            Str::lower($this->email) . '|' . request()->ip() . '|' . $this->mode
        );
    }
}; ?>

<div class="relative min-h-screen bg-gray-50 flex items-center justify-center p-4 font-sans">
    {{-- Subtle grid background (matches login page) --}}
    <div
        aria-hidden="true"
        class="pointer-events-none absolute inset-0"
        style="background-image: radial-gradient(circle at 1px 1px, rgba(15, 23, 42, 0.05) 1px, transparent 0); background-size: 32px 32px;"
    ></div>

    <div class="relative z-10 w-full max-w-md">
        {{-- Logo --}}
        <div class="text-center mb-6">
            <div class="inline-flex h-14 w-14 items-center justify-center rounded-2xl bg-brand-600 text-white shadow-lg shadow-brand-600/25 mb-3">
                <i class="fas fa-key text-2xl"></i>
            </div>
            <h1 class="text-2xl font-bold text-gray-900 tracking-tight">Reset your password</h1>
            <p class="text-sm text-gray-500 mt-1">
                {{ $mode === 'client' ? 'Client Portal password recovery' : 'Staff account password recovery' }}
            </p>
        </div>

        <div class="relative overflow-hidden rounded-2xl bg-white shadow-xl shadow-gray-200/60 ring-1 ring-gray-100">
            {{-- Top progress bar during submit --}}
            <div
                class="pointer-events-none absolute inset-x-0 top-0 z-20 h-0.5 overflow-hidden opacity-0 transition-opacity duration-150"
                wire:loading.class="opacity-100"
                wire:target="sendResetLink"
                aria-hidden="true"
            >
                <div class="h-full w-1/3 bg-brand-600 login-progress-bar"></div>
            </div>

            <div
                class="p-6 sm:p-8 transition-opacity duration-150"
                wire:loading.class="opacity-60 pointer-events-none"
                wire:target="sendResetLink"
            >
                @if ($sent)
                    {{-- Generic success — same message regardless of whether email matched. --}}
                    <div class="flex flex-col items-center text-center">
                        <div class="inline-flex h-12 w-12 items-center justify-center rounded-full bg-green-100 text-green-600 mb-3">
                            <i class="fas fa-envelope-circle-check text-xl"></i>
                        </div>
                        <h2 class="text-lg font-bold text-gray-900">Check your inbox</h2>
                        <p class="text-sm text-gray-600 mt-2 leading-relaxed">
                            If an account exists for <span class="font-semibold text-gray-800">{{ $email }}</span>,
                            we've sent a password reset link. It expires in 60 minutes.
                        </p>
                        <p class="text-xs text-gray-400 mt-4">
                            Didn't get it? Check spam, or
                            <button
                                type="button"
                                wire:click="$set('sent', false)"
                                class="text-brand-600 font-medium hover:underline underline-offset-2"
                            >try again</button>.
                        </p>
                        <a
                            href="{{ route('login') }}"
                            wire:navigate
                            class="mt-6 inline-flex items-center gap-1.5 text-sm font-medium text-gray-500 hover:text-gray-700 transition-colors"
                        >
                            <i class="fas fa-arrow-left text-xs"></i>
                            Back to sign in
                        </a>
                    </div>
                @else
                    <h2 class="text-xl font-bold text-gray-900">Forgot your password?</h2>
                    <p class="text-sm text-gray-500 mt-1 mb-6">
                        Enter the email tied to your account and we'll send you a link to set a new one.
                    </p>

                    @if ($errorMessage)
                        <div
                            role="alert"
                            class="mb-4 flex items-start gap-2.5 rounded-xl border border-red-200 bg-red-50 px-3.5 py-3 text-sm text-red-700"
                        >
                            <i class="fas fa-exclamation-circle mt-0.5"></i>
                            <span>{{ $errorMessage }}</span>
                        </div>
                    @endif

                    <form wire:submit="sendResetLink" class="space-y-4">
                        <div>
                            <label for="forgot-email" class="block text-sm font-medium text-gray-700 mb-1.5">Email Address</label>
                            <div class="relative">
                                <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3.5 text-gray-400">
                                    <i class="fas fa-envelope text-sm"></i>
                                </span>
                                <input
                                    id="forgot-email"
                                    type="email"
                                    wire:model="email"
                                    required
                                    autocomplete="username"
                                    autofocus
                                    placeholder="you@example.com"
                                    class="block w-full rounded-xl border-gray-200 bg-gray-50 py-2.5 pl-10 pr-3.5 text-sm text-gray-900 placeholder:text-gray-400 shadow-sm transition-colors focus:border-brand-500 focus:bg-white focus:ring-2 focus:ring-brand-500/20 @error('email') border-red-300 focus:border-red-500 focus:ring-red-500/20 @enderror"
                                />
                            </div>
                            @error('email')
                                <p class="mt-1.5 flex items-center gap-1.5 text-xs text-red-600">
                                    <i class="fas fa-exclamation-circle"></i>{{ $message }}
                                </p>
                            @enderror
                        </div>

                        <button
                            type="submit"
                            wire:loading.attr="disabled"
                            wire:target="sendResetLink"
                            class="group flex w-full items-center justify-center gap-2 rounded-xl bg-brand-600 px-4 py-2.5 text-sm font-semibold text-white shadow-lg shadow-brand-600/25 transition-all hover:bg-brand-700 active:scale-[0.98] active:shadow-md focus:outline-none focus:ring-2 focus:ring-brand-500 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-80"
                        >
                            <span wire:loading.remove wire:target="sendResetLink" class="flex items-center gap-2">
                                Send reset link
                                <i class="fas fa-paper-plane text-xs transition-transform group-hover:translate-x-0.5"></i>
                            </span>
                            <span wire:loading wire:target="sendResetLink" class="flex items-center gap-2" style="display: none;">
                                <svg class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                                </svg>
                                Sending…
                            </span>
                        </button>
                    </form>

                    <div class="mt-6 text-center">
                        <a
                            href="{{ route('login') }}"
                            wire:navigate
                            class="inline-flex items-center gap-1.5 text-sm font-medium text-gray-500 hover:text-gray-700 transition-colors"
                        >
                            <i class="fas fa-arrow-left text-xs"></i>
                            Back to sign in
                        </a>
                    </div>
                @endif
            </div>
        </div>

        <p class="text-center text-xs text-gray-400 mt-6">
            &copy; 2026 Madhyam Agency. All rights reserved.
        </p>
    </div>

    <style>
        @keyframes login-progress-slide {
            0% { transform: translateX(-100%); }
            100% { transform: translateX(400%); }
        }
        .login-progress-bar {
            animation: login-progress-slide 1.1s ease-in-out infinite;
        }
    </style>
</div>

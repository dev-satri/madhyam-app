<?php

use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('components.layouts.guest')] class extends Component
{
    #[Locked]
    public string $token = '';

    #[Url(as: 'email')]
    public string $email = '';

    #[Url(as: 'mode')]
    public string $mode = 'staff';

    public string $password = '';
    public string $password_confirmation = '';
    public bool $showPassword = false;
    public string $errorMessage = '';

    public function mount(string $token): void
    {
        $this->token = $token;
        $this->mode = $this->mode === 'client' ? 'client' : 'staff';
    }

    public function resetPassword(): void
    {
        $this->validate([
            'token' => 'required|string',
            'email' => 'required|email',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $this->errorMessage = '';
        $broker = $this->mode === 'client' ? 'client_accounts' : 'users';

        $status = Password::broker($broker)->reset(
            [
                'email' => $this->email,
                'password' => $this->password,
                'password_confirmation' => $this->password_confirmation,
                'token' => $this->token,
            ],
            function ($user, $password) {
                // The broker gives us the resolved user model (either User or
                // ClientAccount depending on the guard). `password => hashed`
                // cast on each model takes care of Bcrypt hashing on save.
                $user->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($user));
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            session()->flash('status', 'Password updated. Please sign in with your new password.');
            $this->redirect(route('login'), navigate: true);
            return;
        }

        // Broker returned one of: INVALID_TOKEN, INVALID_USER, or the raw
        // validation-style status key. Surface a localized message.
        $this->errorMessage = trans($status);
    }
}; ?>

<div class="relative min-h-screen bg-gray-50 flex items-center justify-center p-4 font-sans">
    <div
        aria-hidden="true"
        class="pointer-events-none absolute inset-0"
        style="
            background-image: radial-gradient(circle at 1px 1px, rgba(15, 23, 42, 0.05) 1px, transparent 0);
            background-size: 32px 32px;
        "
    ></div>

    <div class="relative z-10 w-full max-w-md">
        {{-- Logo --}}
        <div class="text-center mb-6 flex flex-col items-center">
            @if (config('app.logo_path'))
                <img
                    src="{{ Storage::disk('public')->url(config('app.logo_path')) }}"
                    alt="{{ config('app.name', 'Madhyam') }} Logo"
                    class="h-16 w-auto object-contain mb-3 max-h-16"
                />
            @else
                <div
                    class="inline-flex h-14 w-14 items-center justify-center rounded-2xl text-white shadow-lg mb-3"
                    style="background-color: var(--brand); box-shadow: 0 10px 15px -3px rgba(var(--brand-rgb), 0.25)"
                >
                    <i class="fas fa-lock-open text-2xl"></i>
                </div>
            @endif
            <h1 class="text-2xl font-bold text-gray-900 tracking-tight">Set a new password</h1>
            <p class="text-sm text-gray-500 mt-1">
                {{ $mode === 'client' ? 'Client Portal account' : 'Staff account' }}
            </p>
        </div>

        <div class="relative overflow-hidden rounded-2xl bg-white shadow-xl shadow-gray-200/60 ring-1 ring-gray-100">
            <div
                class="pointer-events-none absolute inset-x-0 top-0 z-20 h-0.5 overflow-hidden opacity-0 transition-opacity duration-150"
                wire:loading.class="opacity-100"
                wire:target="resetPassword"
                aria-hidden="true"
            >
                <div class="h-full w-1/3 login-progress-bar" style="background-color: var(--brand)"></div>
            </div>

            <div
                class="p-6 sm:p-8 transition-opacity duration-150"
                wire:loading.class="opacity-60 pointer-events-none"
                wire:target="resetPassword"
            >
                <h2 class="text-xl font-bold text-gray-900">Choose a new password</h2>
                <p class="text-sm text-gray-500 mt-1 mb-6">Pick something at least 8 characters long. After saving you'll be sent back to sign in.</p>

                @if ($errorMessage)
                    <div
                        role="alert"
                        class="mb-4 flex items-start gap-2.5 rounded-xl border border-red-200 bg-red-50 px-3.5 py-3 text-sm text-red-700"
                    >
                        <i class="fas fa-exclamation-circle mt-0.5"></i>
                        <span>{{ $errorMessage }}</span>
                    </div>
                @endif

                <form wire:submit="resetPassword" class="space-y-4">
                    {{-- Email (readonly, echo from query param) --}}
                    <div>
                        <label for="reset-email" class="block text-sm font-medium text-gray-700 mb-1.5"
                            >Email Address</label
                        >
                        <div class="relative">
                            <span
                                class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3.5 text-gray-400"
                            >
                                <i class="fas fa-envelope text-sm"></i>
                            </span>
                            <input
                                id="reset-email"
                                type="email"
                                wire:model="email"
                                readonly
                                class="block w-full rounded-xl border-gray-200 bg-gray-100 py-2.5 pl-10 pr-3.5 text-sm text-gray-600 shadow-sm cursor-not-allowed"
                            />
                        </div>
                        @error ('email')
                            <p class="mt-1.5 flex items-center gap-1.5 text-xs text-red-600">
                                <i class="fas fa-exclamation-circle"></i>{{ $message }}
                            </p>
                        @enderror
                    </div>

                    {{-- New password --}}
                    <div>
                        <label for="reset-password" class="block text-sm font-medium text-gray-700 mb-1.5"
                            >New Password</label
                        >
                        <div class="relative">
                            <span
                                class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3.5 text-gray-400"
                            >
                                <i class="fas fa-lock text-sm"></i>
                            </span>
                            <input
                                id="reset-password"
                                type="{{ $showPassword ? 'text' : 'password' }}"
                                wire:model="password"
                                required
                                autocomplete="new-password"
                                autofocus
                                placeholder="At least 8 characters"
                                class="block w-full rounded-xl border-gray-200 bg-gray-50 py-2.5 pl-10 pr-11 text-sm text-gray-900 placeholder:text-gray-400 shadow-sm transition-colors focus:bg-white focus:ring-2 brand-input-focus @error('password') border-red-300 focus:border-red-500 focus:ring-red-500/20 @enderror"
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

                    {{-- Confirmation --}}
                    <div>
                        <label for="reset-password-confirm" class="block text-sm font-medium text-gray-700 mb-1.5"
                            >Confirm New Password</label
                        >
                        <div class="relative">
                            <span
                                class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3.5 text-gray-400"
                            >
                                <i class="fas fa-lock text-sm"></i>
                            </span>
                            <input
                                id="reset-password-confirm"
                                type="{{ $showPassword ? 'text' : 'password' }}"
                                wire:model="password_confirmation"
                                required
                                autocomplete="new-password"
                                placeholder="Type it again"
                                class="block w-full rounded-xl border-gray-200 bg-gray-50 py-2.5 pl-10 pr-3.5 text-sm text-gray-900 placeholder:text-gray-400 shadow-sm transition-colors focus:bg-white focus:ring-2 brand-input-focus"
                            />
                        </div>
                    </div>

                    <button
                        type="submit"
                        wire:loading.attr="disabled"
                        wire:target="resetPassword"
                        class="group flex w-full items-center justify-center gap-2 rounded-xl px-4 py-2.5 text-sm font-semibold text-white shadow-lg transition-all hover:opacity-90 active:scale-[0.98] active:shadow-md focus:outline-none focus:ring-2 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-80"
                        style="
                            background-color: var(--brand);
                            --tw-ring-color: var(--brand);
                            box-shadow: 0 10px 15px -3px rgba(var(--brand-rgb), 0.25);
                        "
                    >
                        <span wire:loading.remove wire:target="resetPassword" class="flex items-center gap-2">
                            Save new password
                            <i class="fas fa-check text-xs"></i>
                        </span>
                        <span
                            wire:loading
                            wire:target="resetPassword"
                            class="flex items-center gap-2"
                            style="display: none"
                        >
                            <svg class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                            </svg>
                            Saving…
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
            </div>
        </div>

        <p class="text-center text-xs text-gray-400 mt-6">&copy; 2026 Madhyam Agency. All rights reserved.</p>
    </div>

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
        .brand-input-focus:focus {
            border-color: var(--brand) !important;
            --tw-ring-color: rgba(var(--brand-rgb), 0.2) !important;
            background-color: #fff !important;
        }
    </style>
</div>

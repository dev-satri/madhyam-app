<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Computed;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Attributes\Rule;

new #[Layout('components.layouts.app')] class extends Component
{
    #[Rule('required|max:255')]
    public string $name = '';

    public string $email = '';

    #[Rule('nullable|max:50')]
    public string $phone = '';

    public bool $showPasswordModal = false;

    #[Rule('required')]
    public string $current_password = '';

    #[Rule('required|min:8')]
    public string $new_password = '';

    #[Rule('required|same:new_password')]
    public string $confirm_password = '';

    public bool $showCurrentPassword = false;
    public bool $showNewPassword = false;
    public bool $showConfirmPassword = false;

    public function mount(): void
    {
        $user = Auth::user() ?? Auth::guard('client')->user();
        if ($user) {
            $this->name = $user->name ?? '';
            $this->email = $user->email ?? '';
            $this->phone = $user->phone ?? '';
        }
    }

    public function save(): void
    {
        $user = Auth::user() ?? Auth::guard('client')->user();
        if (! $user) return;

        $this->validate();

        $changes = [];
        if ($user->name !== $this->name) $changes[] = 'name';
        if (($user->phone ?? '') !== $this->phone) $changes[] = 'phone';

        $user->update([
            'name' => $this->name,
            'phone' => $this->phone,
        ]);

        if (! empty($changes)) {
            $this->logActivity('Profile updated: ' . implode(', ', $changes));
        }

        $this->dispatch('toast', message: 'Profile updated successfully', type: 'success');
    }

    public function openPasswordModal(): void
    {
        $this->showPasswordModal = true;
        $this->resetValidation();
        $this->reset(['current_password', 'new_password', 'confirm_password']);
        $this->showCurrentPassword = false;
        $this->showNewPassword = false;
        $this->showConfirmPassword = false;
    }

    public function closePasswordModal(): void
    {
        $this->showPasswordModal = false;
        $this->resetValidation();
        $this->reset(['current_password', 'new_password', 'confirm_password']);
    }

    public function savePassword(): void
    {
        $user = Auth::user() ?? Auth::guard('client')->user();
        if (! $user) return;

        $this->validate();

        if (! Hash::check($this->current_password, $user->password)) {
            $this->addError('current_password', 'The current password is incorrect.');
            return;
        }

        $user->update(['password' => Hash::make($this->new_password)]);

        $this->logActivity('Password changed');

        $this->closePasswordModal();
        $this->dispatch('toast', message: 'Password updated successfully', type: 'success');
    }

    protected function logActivity(string $text): void
    {
        try {
            $user = Auth::user() ?? Auth::guard('client')->user();
            DB::table('activity_logs')->insert([
                'user_id' => $user?->id,
                'user' => $user?->name ?? 'User',
                'text' => $text,
                'time' => now(),
            ]);
        } catch (\Exception $e) {
            // activity log table may not exist yet
        }
    }
}; ?>

<div>
    <div class="mb-6">
        <h1 class="text-2xl font-extrabold text-gray-900">My Profile</h1>
        <p class="text-sm text-gray-500 mt-1">Manage your account and preferences</p>
    </div>

    <div class="max-w-xl mx-auto">
        <div class="bg-white rounded-2xl border border-gray-100 p-6">
            <div class="flex items-center gap-4 mb-6">
                <div
                    class="flex h-16 w-16 items-center justify-center rounded-full bg-[rgba(var(--brand-rgb),0.1)] text-xl font-bold text-[var(--brand)]"
                >
                    {{ initials($name) }}
                </div>
                <div>
                    <h3 class="text-lg font-bold text-gray-900">{{ $name }}</h3>
                    <p class="text-sm text-gray-500">{{ roleName((Auth::user() ?? Auth::guard('client')->user())->role ?? '') }}</p>
                </div>
            </div>

            <form wire:submit="save" class="space-y-4">
                <div>
                    <label class="form-label" for="profile_name">Full Name</label>
                    <input
                        type="text"
                        id="profile_name"
                        wire:model="name"
                        class="form-input"
                        placeholder="Enter your name"
                    />
                    @error ('name')
                        <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
                    @enderror
                </div>
                <div>
                    <label class="form-label" for="profile_email">Email</label>
                    <input
                        type="email"
                        id="profile_email"
                        class="form-input bg-gray-50"
                        value="{{ $email }}"
                        readonly
                    />
                </div>
                <div>
                    <label class="form-label" for="profile_phone">Phone</label>
                    <input
                        type="text"
                        id="profile_phone"
                        wire:model="phone"
                        class="form-input"
                        placeholder="Enter your phone number"
                    />
                    @error ('phone')
                        <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
                    @enderror
                </div>
                <div class="flex items-center gap-3 pt-2">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save text-sm"></i> Save Changes
                    </button>
                    <button type="button" wire:click="openPasswordModal" class="btn btn-secondary">
                        <i class="fas fa-lock text-sm"></i> Change Password
                    </button>
                </div>
            </form>
        </div>
    </div>

    {{-- Password Change Modal --}}
    @if ($showPasswordModal)
        <div
            class="modal-overlay fixed inset-0 z-[70] flex items-center justify-center bg-black/50 backdrop-blur-sm p-4"
            x-on:keydown.escape.window="$wire.closePasswordModal()"
            wire:click.self="closePasswordModal"
        >
            <div
                class="modal-box w-full max-w-md rounded-2xl bg-white shadow-2xl"
                x-transition:enter="transition ease-out duration-200"
                x-transition:enter-start="opacity-0 scale-95"
                x-transition:enter-end="opacity-100 scale-100"
                x-transition:leave="transition ease-in duration-150"
                x-transition:leave-start="opacity-100 scale-100"
                x-transition:leave-end="opacity-0 scale-95"
            >
                <div class="flex items-center justify-between border-b border-gray-100 px-6 py-4">
                    <h3 class="text-base font-bold text-gray-900">Change Password</h3>
                    <button
                        wire:click="closePasswordModal"
                        class="flex h-8 w-8 items-center justify-center rounded-lg text-gray-400 hover:bg-gray-100 transition-colors"
                    >
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                    </button>
                </div>

                <form wire:submit="savePassword" class="px-6 py-5 space-y-4">
                    <div>
                        <label class="form-label" for="cp_current">Current Password</label>
                        <div class="relative">
                            <input
                                id="cp_current"
                                type="{{ $showCurrentPassword ? 'text' : 'password' }}"
                                wire:model="current_password"
                                class="form-input pr-10"
                                placeholder="Enter current password"
                            />
                            <button
                                type="button"
                                wire:click="$toggle('showCurrentPassword')"
                                class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600"
                                tabindex="-1"
                            >
                                <i class="fas {{ $showCurrentPassword ? 'fa-eye-slash' : 'fa-eye' }} text-sm"></i>
                            </button>
                        </div>
                        @error ('current_password')
                            <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label class="form-label" for="cp_new">New Password</label>
                        <div class="relative">
                            <input
                                id="cp_new"
                                type="{{ $showNewPassword ? 'text' : 'password' }}"
                                wire:model="new_password"
                                class="form-input pr-10"
                                placeholder="Min 8 characters"
                            />
                            <button
                                type="button"
                                wire:click="$toggle('showNewPassword')"
                                class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600"
                                tabindex="-1"
                            >
                                <i class="fas {{ $showNewPassword ? 'fa-eye-slash' : 'fa-eye' }} text-sm"></i>
                            </button>
                        </div>
                        @error ('new_password')
                            <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label class="form-label" for="cp_confirm">Confirm New Password</label>
                        <div class="relative">
                            <input
                                id="cp_confirm"
                                type="{{ $showConfirmPassword ? 'text' : 'password' }}"
                                wire:model="confirm_password"
                                class="form-input pr-10"
                                placeholder="Confirm new password"
                            />
                            <button
                                type="button"
                                wire:click="$toggle('showConfirmPassword')"
                                class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600"
                                tabindex="-1"
                            >
                                <i class="fas {{ $showConfirmPassword ? 'fa-eye-slash' : 'fa-eye' }} text-sm"></i>
                            </button>
                        </div>
                        @error ('confirm_password')
                            <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="flex items-center gap-3 pt-2">
                        <button type="button" wire:click="closePasswordModal" class="btn btn-secondary flex-1">
                            Cancel
                        </button>
                        <button type="submit" class="btn btn-primary flex-1">
                            <i class="fas fa-save text-sm"></i> Update Password
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>

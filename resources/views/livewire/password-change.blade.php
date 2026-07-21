<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Volt\Component;
use Livewire\Attributes\Rule;

new class extends Component
{
    public bool $show = false;

    #[Rule('required')]
    public string $current_password = '';

    #[Rule('required|min:4')]
    public string $new_password = '';

    #[Rule('required|same:new_password')]
    public string $confirm_password = '';

    public bool $showCurrentPassword = false;
    public bool $showNewPassword = false;
    public bool $showConfirmPassword = false;

    public function open(): void
    {
        $this->show = true;
    }

    public function close(): void
    {
        $this->show = false;
        $this->resetValidation();
        $this->reset(['current_password', 'new_password', 'confirm_password']);
        $this->showCurrentPassword = false;
        $this->showNewPassword = false;
        $this->showConfirmPassword = false;
    }

    public function save(): void
    {
        $user = Auth::user();
        if (!$user) return;

        $this->validate();

        if (!Hash::check($this->current_password, $user->password)) {
            $this->addError('current_password', 'The current password is incorrect.');
            return;
        }

        $user->update(['password' => Hash::make($this->new_password)]);

        try {
            DB::table('activity_logs')->insert([
                'user_id' => $user->id,
                'action' => 'Password changed',
                'created_at' => now(),
            ]);
        } catch (\Exception $e) {
            // activity log table may not exist yet
        }

        $this->close();
        $this->dispatch('toast', message: 'Password updated successfully', type: 'success');
    }
}; ?>

<div x-data>
    @if ($show)
        <div
            class="modal-overlay fixed inset-0 z-[70] flex items-center justify-center bg-black/50 backdrop-blur-sm p-4"
            x-on:keydown.escape.window="$wire.close()"
            wire:click.self="close"
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
                <div class="modal-header flex items-center justify-between border-b border-gray-100 px-6 py-4">
                    <h3 class="text-base font-bold text-gray-900">Change Password</h3>
                    <button
                        wire:click="close"
                        class="flex h-8 w-8 items-center justify-center rounded-lg text-gray-400 hover:bg-gray-100 transition-colors"
                    >
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                    </button>
                </div>

                <form wire:submit="save" class="modal-body px-6 py-5 space-y-4">
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
                                placeholder="Min 4 characters"
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
                        <button type="button" wire:click="close" class="btn btn-secondary flex-1">Cancel</button>
                        <button type="submit" class="btn btn-primary flex-1">
                            <i class="fas fa-save text-sm"></i> Update Password
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>

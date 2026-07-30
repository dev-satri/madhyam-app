<?php

use Livewire\Volt\Component;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

new class extends Component
{
    public bool $show = false;
    public int $unreadCount = 0;
    public $notifications = [];

    public function mount(): void
    {
        $this->loadNotifications();
    }

    public function loadNotifications(): void
    {
        $user = Auth::user() ?? Auth::guard('client')->user();
        if (! $user) {
            $this->notifications = [];
            $this->unreadCount = 0;

            return;
        }

        $isClient = Auth::guard('client')->check();
        $clientId = $isClient ? ($user->client_id ?? null) : null;

        try {
            $query = DB::table('notifications')
                ->where(function ($q) use ($user, $isClient, $clientId) {
                    if ($isClient) {
                        // Client portal: only show notifications scoped to this client
                        $q->where('client_id', $clientId);
                    } else {
                        $q->where('for_role', $user->role)
                          ->orWhere('for_role', 'all')
                          ->orWhere('user_id', $user->id);
                    }
                });

            $all = $query->latest()->limit(20)->get();

            $unread = $all->where('read', false);
            $read = $all->where('read', true)->take(5);

            $this->notifications = $unread->concat($read)->values()->toArray();
            $this->unreadCount = $unread->count();
        } catch (\Exception $e) {
            $this->notifications = [];
            $this->unreadCount = 0;
        }
    }

    public function toggle(): void
    {
        $this->show = !$this->show;
        if ($this->show) {
            $this->loadNotifications();
        }
    }

    public function markAsRead(string $id): void
    {
        $user = Auth::user() ?? Auth::guard('client')->user();
        if (! $user) {
            return;
        }

        $isClient = Auth::guard('client')->check();
        $clientId = $isClient ? ($user->client_id ?? null) : null;

        $query = DB::table('notifications')->where('id', $id);

        if ($isClient) {
            $query->where('client_id', $clientId);
        } else {
            $query->where(function ($q) use ($user) {
                $q->where('for_role', $user->role)
                  ->orWhere('for_role', 'all')
                  ->orWhere('user_id', $user->id);
            });
        }

        $query->update(['read' => true]);
        $this->loadNotifications();
    }

    public function markAllRead(): void
    {
        $user = Auth::user() ?? Auth::guard('client')->user();
        if (! $user) {
            return;
        }

        $isClient = Auth::guard('client')->check();
        $clientId = $isClient ? ($user->client_id ?? null) : null;

        $query = DB::table('notifications')
            ->where('read', false);

        if ($isClient) {
            $query->where('client_id', $clientId);
        } else {
            $query->where(function ($q) use ($user) {
                $q->where('for_role', $user->role)
                  ->orWhere('for_role', 'all')
                  ->orWhere('user_id', $user->id);
            });
        }

        $query->update(['read' => true]);

        $this->loadNotifications();
    }
}; ?>

<div class="relative">
    <button
        wire:click="toggle"
        @class([
            'relative flex h-10 w-10 items-center justify-center rounded-xl transition-all duration-200',
            'text-gray-500 hover:bg-gray-100 hover:text-gray-700' => !$show,
            'bg-gray-100 text-gray-700' => $show,
        ])
        aria-label="Notifications"
        aria-expanded="{{ $show ? 'true' : 'false' }}"
    >
        <i class="fas fa-bell text-lg {{ $unreadCount > 0 ? 'animate-wiggle' : '' }}"></i>
        @if ($unreadCount > 0)
            <span
                class="absolute -top-0.5 -right-0.5 flex h-5 min-w-[20px] items-center justify-center rounded-full bg-red-500 px-1 text-[10px] font-bold text-white shadow-sm ring-2 ring-white"
            >
                {{ $unreadCount > 99 ? '99+' : $unreadCount }}
            </span>
        @endif
    </button>

    @if ($show)
        <div
            x-data="{ isOpen: true }"
            @keydown.escape.window="$wire.set('show', false)"
            @click.away="$wire.set('show', false)"
            class="fixed inset-x-4 top-16 sm:inset-x-auto sm:right-4 sm:top-16 sm:w-96 rounded-2xl border border-gray-200 bg-white shadow-2xl z-50 overflow-hidden"
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0 translate-y-1"
            x-transition:enter-end="opacity-100 translate-y-0"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="opacity-100 translate-y-0"
            x-transition:leave-end="opacity-0 translate-y-1"
        >
            <!-- Header -->
            <div class="flex items-center justify-between border-b border-gray-200 bg-gray-50/50 px-4 py-3.5">
                <h4 class="text-base font-bold text-gray-900">Notifications</h4>
                @if ($unreadCount > 0)
                    <button 
                        wire:click="markAllRead" 
                        class="text-xs font-semibold text-[var(--brand)] hover:text-[var(--brand-dark)] hover:underline transition-colors"
                        title="Mark all as read"
                    >
                        Mark all read
                    </button>
                @endif
            </div>

            <!-- Notification List -->
            <div class="max-h-[60vh] overflow-y-auto overscroll-contain">
                @forelse ($notifications as $notification)
                    <div
                        wire:click="markAsRead('{{ $notification->id ?? '' }}')"
                        @class ([
                            'group flex items-start gap-3 px-4 py-3.5 cursor-pointer transition-all duration-200 border-b border-gray-100 last:border-b-0',
                            'bg-blue-50/60 hover:bg-blue-50/80' => ! ($notification->read ?? true),
                            'hover:bg-gray-50' => ($notification->read ?? true),
                        ])
                    >
                        <!-- Unread indicator -->
                        <div class="flex items-center justify-center shrink-0 w-5 h-5 mt-0.5">
                            @if (! ($notification->read ?? true))
                                <span class="h-2.5 w-2.5 rounded-full bg-[var(--brand)] ring-2 ring-blue-100 animate-pulse"></span>
                            @else
                                <span class="h-2 w-2 rounded-full bg-gray-200 opacity-0 group-hover:opacity-100 transition-opacity"></span>
                            @endif
                        </div>

                        <!-- Content -->
                        <div class="flex-1 min-w-0">
                            <p
                                @class ([
                                'text-sm leading-relaxed mb-1.5',
                                'text-gray-900 font-semibold' => ! ($notification->read ?? true),
                                'text-gray-700' => ($notification->read ?? true),
                            ])
                            >{{ $notification->text ?? $notification->message ?? '' }}</p>
                            <p class="text-[11px] text-gray-500 font-medium">
                                {{ \Carbon\Carbon::parse($notification->created_at ?? now())->diffForHumans() }}
                            </p>
                        </div>

                        <!-- Action indicator on hover -->
                        @if (! ($notification->read ?? true))
                            <div class="shrink-0 opacity-0 group-hover:opacity-100 transition-opacity">
                                <i class="fas fa-check text-xs text-gray-400"></i>
                            </div>
                        @endif
                    </div>
                @empty
                    <div class="px-4 py-12 text-center">
                        <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-gray-100 mb-3">
                            <i class="fas fa-bell-slash text-2xl text-gray-300"></i>
                        </div>
                        <p class="text-sm font-medium text-gray-500">No notifications yet</p>
                        <p class="text-xs text-gray-400 mt-1">We'll notify you when something new arrives</p>
                    </div>
                @endforelse
            </div>

            <!-- Footer (optional - shows if there are notifications) -->
            @if (count($notifications) > 0)
                <div class="border-t border-gray-200 bg-gray-50/50 px-4 py-2.5 text-center">
                    <button class="text-xs font-semibold text-gray-600 hover:text-[var(--brand)] transition-colors">
                        View all notifications
                    </button>
                </div>
            @endif
        </div>
    @endif
</div>

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
        $user = Auth::user();
        if (!$user) {
            $user = Auth::guard('client')->user();
        }
        if (!$user) {
            $this->notifications = [];
            $this->unreadCount = 0;
            return;
        }

        try {
            $all = DB::table('notifications')
                ->where('for_role', $user->role)
                ->orWhere('for_role', 'all')
                ->latest()
                ->limit(20)
                ->get();

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
        DB::table('notifications')->where('id', $id)->update(['read' => true]);
        $this->loadNotifications();
    }

    public function markAllRead(): void
    {
        $user = Auth::user();
        if (!$user) {
            $user = Auth::guard('client')->user();
        }
        if (!$user) return;

        DB::table('notifications')
            ->where(function ($q) use ($user) {
                $q->where('for_role', $user->role)->orWhere('for_role', 'all');
            })
            ->where('read', false)
            ->update(['read' => true]);

        $this->loadNotifications();
    }
}; ?>

<div class="relative" @click.away="if(@js($show)) $wire.set('show', false)">
    <button
        wire:click="toggle"
        class="relative flex h-10 w-10 items-center justify-center rounded-xl text-gray-500 hover:bg-gray-100 hover:text-gray-700 transition-colors"
    >
        <i class="fas fa-bell text-lg"></i>
        @if ($unreadCount > 0)
            <span
                class="absolute -top-0.5 -right-0.5 flex h-5 min-w-[20px] items-center justify-center rounded-full bg-red-500 px-1 text-[10px] font-bold text-white"
            >
                {{ $unreadCount > 99 ? '99+' : $unreadCount }}
            </span>
        @endif
    </button>

    @if ($show)
        <div
            class="absolute right-0 mt-2 w-80 rounded-2xl border border-gray-100 bg-white shadow-xl z-50 overflow-hidden"
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0 scale-95"
            x-transition:enter-end="opacity-100 scale-100"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="opacity-100 scale-100"
            x-transition:leave-end="opacity-0 scale-95"
        >
            <div class="flex items-center justify-between border-b border-gray-100 px-4 py-3">
                <h4 class="text-sm font-bold text-gray-900">Notifications</h4>
                @if ($unreadCount > 0)
                    <button wire:click="markAllRead" class="text-xs font-semibold text-[var(--brand)] hover:underline">
                        Mark all read
                    </button>
                @endif
            </div>

            <div class="max-h-80 overflow-y-auto">
                @forelse ($notifications as $notification)
                    <div
                        wire:click="markAsRead('{{ $notification->id ?? '' }}')"
                        @class ([
                            'flex items-start gap-3 px-4 py-3 cursor-pointer hover:bg-gray-50 transition-colors border-b border-gray-50 last:border-b-0',
                            'bg-blue-50/50' => !($notification->read ?? true),
                        ])
                    >
                        @if (!($notification->read ?? true))
                            <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-[var(--brand)]"></span>
                        @else
                            <span class="mt-1.5 h-2 w-2 shrink-0"></span>
                        @endif
                        <div class="flex-1 min-w-0">
                            <p
                                @class ([
                                'text-sm text-gray-800',
                                'font-semibold' => !($notification->read ?? true),
                            ])
                            >{{ $notification->text ?? $notification->message ?? '' }}</p>
                            <p class="mt-0.5 text-[11px] text-gray-400">{{ $notification->created_at ?? '' }}</p>
                        </div>
                    </div>
                @empty
                    <div class="px-4 py-10 text-center">
                        <i class="fas fa-bell-slash text-3xl text-gray-200 mb-2"></i>
                        <p class="text-sm text-gray-400">No notifications</p>
                    </div>
                @endforelse
            </div>
        </div>
    @endif
</div>

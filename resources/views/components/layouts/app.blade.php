<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />
    <title>{{ config('app.name', 'Madhyam') }}</title>

    @if (config('app.favicon_path'))
        <link rel="icon" type="image/x-icon" href="{{ Storage::disk('public')->url(config('app.favicon_path')) }}" />
    @else
        <link rel="icon" type="image/x-icon" href="/favicon.ico" />
    @endif

    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap"
        rel="stylesheet"
    />

    <style id="brand-color-vars">
        :root {
            --brand: {{ config('app.brand_color', '#4f46e5') }};
            --brand-rgb: {{ config('app.brand_color_rgb', '79, 70, 229') }};
        }
    </style>

    @vite (['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="font-sans antialiased bg-gray-50 text-gray-800">
    <div class="flex h-screen overflow-hidden" x-data="{ sidebarOpen: false, profileOpen: false }">
        <!-- Mobile Sidebar Overlay -->
        <div
            x-show="sidebarOpen"
            x-transition:enter="transition-opacity ease-linear duration-300"
            x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100"
            x-transition:leave="transition-opacity ease-linear duration-300"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
            class="fixed inset-0 z-40 bg-black/50 lg:hidden"
            @click="sidebarOpen = false"
            x-cloak
        ></div>

        <!-- Sidebar -->
        <aside
            :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full lg:translate-x-0'"
            class="sidebar fixed inset-y-0 left-0 z-50 flex w-[260px] flex-col border-r border-gray-100 bg-white transition-transform duration-300 ease-in-out"
        >
            <!-- Logo -->
            <div class="flex items-center gap-3 border-b border-gray-100 px-5 py-4">
                @if (config('app.logo_path'))
                    <img
                        src="{{ Storage::disk('public')->url(config('app.logo_path')) }}"
                        alt="Logo"
                        class="h-9 w-9 rounded-lg object-contain"
                    />
                @else
                    <div
                        class="flex h-9 w-9 items-center justify-center rounded-lg bg-[var(--brand)] text-white shadow-md shadow-[rgba(var(--brand-rgb),0.3)]"
                    >
                        <i class="fas fa-layer-group text-sm"></i>
                    </div>
                @endif
                <div class="flex-1">
                    <h1 class="text-[15px] font-extrabold tracking-tight text-gray-900">Madhyam</h1>
                    <p class="text-[9px] font-semibold uppercase tracking-widest text-gray-400">Agency System</p>
                </div>
                <button
                    @click="sidebarOpen = false"
                    class="flex h-8 w-8 items-center justify-center rounded-lg text-gray-400 hover:bg-gray-100 hover:text-gray-600 lg:hidden"
                    aria-label="Close sidebar"
                >
                    <i class="fas fa-times"></i>
                </button>
            </div>

            <!-- Navigation -->
            <nav class="flex-1 overflow-y-auto px-3 py-3" id="sidebarNav">
                @php
                    $user = Auth::user();
                    $role = $user->role ?? '';
                    $isClient = $user instanceof \App\Models\ClientAccount;
                    $rbac = app(\App\Services\RbacService::class);
                    // Scoped pending-approval count for the sidebar badge.
                    // Clients: only pending on their own client_id (was leaking agency-wide count).
                    // Managers/admins: full pending queue (things they can act on).
                    // Other staff: no badge — nothing actionable at this level.
                    $pendingApprovals = 0;
                    try {
                        if ($isClient) {
                            $pendingApprovals = \Illuminate\Support\Facades\DB::table('approvals')
                                ->where('status', 'pending')
                                ->where('client_id', $user->client_id)
                                ->count();
                        } elseif ($user && in_array($role, ['super-admin', 'admin', 'manager'])) {
                            $pendingApprovals = \Illuminate\Support\Facades\DB::table('approvals')
                                ->where('status', 'pending')
                                ->count();
                        }
                    } catch (\Exception $e) {}

                    $sidebarItems = [
                        ['section' => 'Main', 'items' => [
                            ['feature' => 'dashboard', 'route' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'fa-th-large'],
                            ['feature' => 'clients', 'route' => 'clients', 'label' => 'Clients', 'icon' => 'fa-users'],
                            ['feature' => 'packages', 'route' => 'packages', 'label' => 'Packages', 'icon' => 'fa-box'],
                            ['feature' => 'contentPlanner', 'route' => 'content-planner', 'label' => 'Content Planner', 'icon' => 'fa-calendar-alt'],
                        ]],
                        ['section' => 'Production', 'items' => [
                            ['feature' => 'workflow', 'route' => 'workflow', 'label' => 'Workflow', 'icon' => 'fa-columns'],
                            ['feature' => 'tasks', 'route' => 'tasks', 'label' => 'Videos & Shoots', 'icon' => 'fa-video'],
                            ['feature' => 'approvals', 'route' => 'approvals', 'label' => 'Approvals', 'icon' => 'fa-check-double', 'badge' => $pendingApprovals > 0 ? $pendingApprovals : null],
                        ]],
                        ['section' => 'Management', 'items' => [
                            ['feature' => 'files', 'route' => 'files', 'label' => 'Files & Media', 'icon' => 'fa-folder-open'],
                            ['feature' => 'reports', 'route' => 'reports', 'label' => 'Reports & Finance', 'icon' => 'fa-chart-bar'],
                            ['feature' => 'leaves', 'route' => 'leaves', 'label' => 'Leaves', 'icon' => 'fa-calendar-minus'],
                            ['feature' => 'expenses', 'route' => 'expenses', 'label' => 'Expenses', 'icon' => 'fa-receipt'],
                        ]],
                        ['section' => 'Admin', 'items' => [
                            ['feature' => 'team', 'route' => 'team', 'label' => 'Team', 'icon' => 'fa-users-cog'],
                            ['feature' => 'salary', 'route' => 'salary', 'label' => 'Salary', 'icon' => 'fa-money-bill-wave'],
                            ['feature' => 'overtime', 'route' => 'overtime', 'label' => 'Overtime', 'icon' => 'fa-clock'],
                            ['feature' => 'complaints', 'route' => 'complaints', 'label' => 'Complaints', 'icon' => 'fa-exclamation-circle'],
                            ['feature' => 'settings', 'route' => 'settings', 'label' => 'Settings', 'icon' => 'fa-cog'],
                            ['feature' => 'userGuide', 'route' => 'user-guide', 'label' => 'User Guide', 'icon' => 'fa-book'],
                            ['route' => 'trash', 'label' => 'Trash', 'icon' => 'fa-trash-alt', 'roles' => ['super-admin', 'admin'], 'badge' => \Illuminate\Support\Facades\DB::getSchemaBuilder()->hasTable('trash') ? \App\Models\Trash::count() ?: null : null],
                        ]],
                    ];
                @endphp

                @if ($isClient)
                    {{-- CLIENT PORTAL SIDEBAR --}}
                    <div class="mb-1 px-3 pt-2 pb-1 text-[10px] font-bold uppercase tracking-widest text-gray-400">
                        Client Portal
                    </div>
                    <a
                        href="{{ route('client.dashboard') }}"
                        class="sidebar-link flex items-center gap-3 rounded-xl px-3 py-2.5 text-[13px] font-medium text-gray-500 hover:bg-gray-50 hover:text-gray-900 {{ request()->routeIs('client.dashboard') ? 'active' : '' }}"
                    >
                        <div
                            class="icon-box flex h-8 w-8 items-center justify-center rounded-lg bg-gray-100 text-gray-500 transition-colors"
                        >
                            <i class="fas fa-th-large text-xs"></i>
                        </div>
                        <span>Overview</span>
                    </a>
                    <a
                        href="{{ route('client.approvals') }}"
                        class="sidebar-link flex items-center gap-3 rounded-xl px-3 py-2.5 text-[13px] font-medium text-gray-500 hover:bg-gray-50 hover:text-gray-900 {{ request()->routeIs('client.approvals') ? 'active' : '' }}"
                    >
                        <div
                            class="icon-box flex h-8 w-8 items-center justify-center rounded-lg bg-gray-100 text-gray-500 transition-colors"
                        >
                            <i class="fas fa-check-double text-xs"></i>
                        </div>
                        <span>Approvals</span>
                    </a>
                    <a
                        href="{{ route('client.complaints') }}"
                        class="sidebar-link flex items-center gap-3 rounded-xl px-3 py-2.5 text-[13px] font-medium text-gray-500 hover:bg-gray-50 hover:text-gray-900 {{ request()->routeIs('client.complaints') ? 'active' : '' }}"
                    >
                        <div
                            class="icon-box flex h-8 w-8 items-center justify-center rounded-lg bg-gray-100 text-gray-500 transition-colors"
                        >
                            <i class="fas fa-exclamation-circle text-xs"></i>
                        </div>
                        <span>Complaints</span>
                    </a>
                    <a
                        href="{{ route('client.billing') }}"
                        class="sidebar-link flex items-center gap-3 rounded-xl px-3 py-2.5 text-[13px] font-medium text-gray-500 hover:bg-gray-50 hover:text-gray-900 {{ request()->routeIs('client.billing') ? 'active' : '' }}"
                    >
                        <div
                            class="icon-box flex h-8 w-8 items-center justify-center rounded-lg bg-gray-100 text-gray-500 transition-colors"
                        >
                            <i class="fas fa-receipt text-xs"></i>
                        </div>
                        <span>Billing</span>
                    </a>
                    <a
                        href="{{ route('client.profile') }}"
                        class="sidebar-link flex items-center gap-3 rounded-xl px-3 py-2.5 text-[13px] font-medium text-gray-500 hover:bg-gray-50 hover:text-gray-900 {{ request()->routeIs('client.profile') ? 'active' : '' }}"
                    >
                        <div
                            class="icon-box flex h-8 w-8 items-center justify-center rounded-lg bg-gray-100 text-gray-500 transition-colors"
                        >
                            <i class="fas fa-user-circle text-xs"></i>
                        </div>
                        <span>Profile</span>
                    </a>
                @else
                    {{-- STAFF SIDEBAR --}}
                    @foreach ($sidebarItems as $group)
                        @php
                            $visibleItems = collect($group['items'])->filter(function ($item) use ($rbac, $role) {
                                if (isset($item['roles']) && ! in_array($role, $item['roles'], true)) {
                                    return false;
                                }
                                return ! isset($item['feature']) || $rbac->hasFeature($role, $item['feature']);
                            });
                        @endphp
                        @if ($visibleItems->count())
                            <div
                                class="mb-1 px-3 {{ $loop->first ? 'pt-2' : 'pt-4' }} pb-1 text-[10px] font-bold uppercase tracking-widest text-gray-400"
                            >
                                {{ $group['section'] }}
                            </div>
                            @foreach ($visibleItems as $item)
                                <a
                                    href="{{ route($item['route']) }}"
                                    class="sidebar-link flex items-center gap-3 rounded-xl px-3 py-2.5 text-[13px] font-medium text-gray-500 hover:bg-gray-50 hover:text-gray-900 {{ request()->routeIs($item['route']) ? 'active' : '' }}"
                                >
                                    <div
                                        class="icon-box flex h-8 w-8 items-center justify-center rounded-lg bg-gray-100 text-gray-500 transition-colors"
                                    >
                                        <i class="fas {{ $item['icon'] }} text-xs"></i>
                                    </div>
                                    <span>{{ $item['label'] }}</span>
                                    @if ($item['badge'] ?? null)
                                        <span
                                            class="ml-auto flex h-5 min-w-[20px] items-center justify-center rounded-full bg-red-500 px-1.5 text-[10px] font-bold text-white"
                                            >{{ $item['badge'] }}</span
                                        >
                                    @endif
                                </a>
                            @endforeach
                        @endif
                    @endforeach
                @endif
            </nav>

            <!-- User Card -->
            <div class="border-t border-gray-100 p-3">
                <div
                    class="flex items-center gap-3 rounded-xl p-2 hover:bg-gray-50 cursor-pointer transition-colors"
                    onclick="window.location='{{ $isClient ? route('client.profile') : route('profile') }}'"
                >
                    <div
                        class="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-full bg-[rgba(var(--brand-rgb),0.1)] text-xs font-bold text-[var(--brand)]"
                    >
                        @if ($user)
                            {{ strtoupper(substr($user->name, 0, 1)) }}{{ strtoupper(substr($user->name, strrpos($user->name, ' ') + 1, 1)) }}
                        @endif
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="text-[13px] font-semibold text-gray-900 truncate">{{ $user->name ?? 'User' }}</div>
                        <div class="text-[10px] text-gray-400 truncate">
                            {{ ucfirst(str_replace('-', ' ', $user->role ?? '')) }}
                        </div>
                    </div>
                    <form
                        method="POST"
                        action="{{ $isClient ? route('client.logout') : route('logout') }}"
                        class="hidden"
                        id="logout-form-sidebar"
                    >
                        @csrf
                    </form>
                    <button
                        type="submit"
                        form="logout-form-sidebar"
                        onclick="event.stopPropagation()"
                        class="text-gray-400 hover:text-red-500 transition-colors"
                        title="Logout"
                        aria-label="Logout"
                    >
                        <i class="fas fa-sign-out-alt text-xs"></i>
                    </button>
                </div>
            </div>
        </aside>

        <!-- Main Content -->
        <main class="flex-1 flex flex-col overflow-hidden lg:ml-[260px]">
            <!-- Topbar -->
            <header
                class="sticky top-0 z-30 flex h-16 items-center border-b border-gray-100 bg-white px-4 lg:px-6 gap-3"
            >
                <button
                    @click="sidebarOpen = !sidebarOpen"
                    class="flex h-10 w-10 items-center justify-center rounded-lg text-gray-500 hover:bg-gray-100 hover:text-gray-700 lg:hidden"
                    aria-label="Toggle sidebar"
                >
                    <i class="fas fa-bars text-lg"></i>
                </button>

                <div class="flex-1"></div>

                <div class="flex items-center gap-1">
                    <!-- Notifications -->
                    @livewire ('notification-dropdown')

                    <!-- Profile -->
                    <div class="relative" @click.away="profileOpen = false">
                        <button
                            @click="profileOpen = !profileOpen"
                            class="flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium text-gray-600 hover:bg-gray-100"
                        >
                            <div
                                class="flex h-8 w-8 items-center justify-center rounded-full bg-[rgba(var(--brand-rgb),0.1)] text-xs font-bold text-[var(--brand)]"
                            >
                                @if ($user)
                                    {{ strtoupper(substr($user->name, 0, 1)) }}{{ strtoupper(substr($user->name, strrpos($user->name, ' ') + 1, 1)) }}
                                @endif
                            </div>
                            <span class="hidden sm:inline">{{ $user->name ?? '' }}</span>
                            <i class="fas fa-chevron-down text-[10px] text-gray-400"></i>
                        </button>
                        <div
                            x-show="profileOpen"
                            x-transition
                            class="absolute right-0 mt-2 w-48 rounded-xl border border-gray-100 bg-white py-1 shadow-lg"
                            x-cloak
                        >
                            <a
                                href="{{ $isClient ? route('client.profile') : route('profile') }}"
                                class="flex items-center gap-2 px-4 py-2 text-sm text-gray-600 hover:bg-gray-50"
                            >
                                <i class="fas fa-user-circle w-4 text-gray-400"></i> My Profile
                            </a>
                            <hr class="my-1 border-gray-100" />
                            <form method="POST" action="{{ $isClient ? route('client.logout') : route('logout') }}">
                                @csrf
                                <button
                                    type="submit"
                                    class="flex w-full items-center gap-2 px-4 py-2 text-sm text-red-600 hover:bg-red-50"
                                >
                                    <i class="fas fa-sign-out-alt w-4"></i> Logout
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </header>

            <!-- Page Content -->
            <div class="flex-1 overflow-y-auto p-4 sm:p-6 lg:p-8">{{ $slot }}</div>
        </main>
    </div>

    <!-- Toast Container -->
    <div id="toast-container" class="fixed top-5 right-5 z-[100] flex flex-col gap-2 pointer-events-none"></div>

    <!-- Modal Host -->
    <div id="modal-host"></div>

    <!-- Confirm Dialog Host -->
    @livewire ('confirm-dialog')

    @livewireScripts

    <script>
        function showToast(message, type = 'success') {
            const container = document.getElementById('toast-container');
            if (!container) return;
            const icons = {
                success:
                    '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>',
                error: '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>',
                warning:
                    '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>',
                info: '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>'
            };
            const colors = {
                success: 'bg-green-600',
                error: 'bg-red-600',
                warning: 'bg-yellow-500 text-gray-900',
                info: 'bg-blue-600'
            };
            const el = document.createElement('div');
            el.className = `pointer-events-auto flex items-center gap-3 min-w-[280px] max-w-[380px] px-4 py-3 rounded-xl shadow-lg text-white text-sm font-medium ${colors[type] || colors.success}`;
            el.style.animation = 'slideInRight 0.25s ease-out';
            el.innerHTML = `${icons[type] || icons.success}<span class="flex-1">${message}</span><button onclick="this.parentElement.remove()" class="shrink-0 opacity-70 hover:opacity-100"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg></button>`;
            container.appendChild(el);
            setTimeout(() => {
                el.style.animation = 'slideOutRight 0.3s ease-in forwards';
                setTimeout(() => el.remove(), 300);
            }, 3000);
        }

        document.addEventListener('livewire:initialized', () => {
            Livewire.on('toast', (data) => {
                const msg = typeof data === 'object' ? data.message || data[0]?.message || '' : data;
                const type = typeof data === 'object' ? data.type || data[0]?.type || 'success' : 'success';
                showToast(msg, type);
            });
        });
    </script>
</body>
</html>

<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Computed;
use Livewire\WithPagination;
use App\Models\Client;
use App\Models\ClientAccount;
use App\Models\Package;
use App\Mail\ClientWelcomeMail;
use App\Mail\ClientCredentialsMail;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

new #[Layout('components.layouts.app')] class extends Component
{
    use WithPagination;

    public string $search = '';
    public string $statusFilter = '';
    public string $packageFilter = '';

    public bool $showForm = false;
    public string $formMode = 'create';
    public int $selectedClientId = 0;

    public string $name = '';
    public string $contact = '';
    public string $email = '';
    public string $phone = '';
    public string $package = 'basic';
    public ?int $package_id = null;
    public ?string $contract_start = null;
    public ?string $contract_end = null;
    public float $amount = 0;
    public string $deliverables = '';
    public string $brand_guide = '';
    public string $social_links = '';
    public string $notes = '';
    public string $status = 'active';

    public bool $showDetail = false;
    public ?Client $selectedClient = null;
    public string $detailTab = 'overview';

    public bool $showDeleteConfirm = false;
    public int $deleteId = 0;

    // Generated credentials shown after client creation
    public bool $showCredentialsModal = false;
    public string $generatedEmail = '';
    public string $generatedPassword = '';

    // Status toggle
    public bool $showStatusConfirm = false;
    public int $statusClientId = 0;
    public string $newStatus = '';
    public string $currentStatus = '';

    protected $listeners = ['statusUpdated' => 'render'];

    public function mount(): void {}

    #[Computed]
    public function packages()
    {
        return Package::where('status', 'active')->orderBy('name')->get();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedPackageFilter(): void
    {
        $this->resetPage();
    }

    public function updatedPackageId(?int $value): void
    {
        if ($value) {
            $pkg = Package::find($value);
            if ($pkg) {
                $this->package = $pkg->slug;
                $this->amount = (float) $pkg->monthly_amount;
            }
        }
    }

    #[Computed]
    public function clients()
    {
        $query = Client::with('linkedPackage');

        if ($this->search) {
            $query->where(function ($q) {
                $q->where('name', 'like', "%{$this->search}%")
                  ->orWhere('contact', 'like', "%{$this->search}%")
                  ->orWhere('email', 'like', "%{$this->search}%")
                  ->orWhere('phone', 'like', "%{$this->search}%");
            });
        }

        if ($this->statusFilter) {
            $query->where('status', $this->statusFilter);
        }

        if ($this->packageFilter) {
            $query->where('package', $this->packageFilter);
        }

        return $query->orderBy('name')->paginate(15);
    }

    public function create(): void
    {
        $this->resetForm();
        $this->formMode = 'create';
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $client = Client::find($id);
        if (! $client) return;

        $this->formMode = 'edit';
        $this->selectedClientId = $id;
        $this->name = $client->name;
        $this->contact = $client->contact ?? '';
        $this->email = $client->email ?? '';
        $this->phone = $client->phone ?? '';
        $this->package = $client->package;
        $this->package_id = $client->package_id;
        $this->contract_start = $client->contract_start?->format('Y-m-d');
        $this->contract_end = $client->contract_end?->format('Y-m-d');
        $this->amount = (float) $client->amount;
        $this->deliverables = $client->deliverables ?? '';
        $this->brand_guide = $client->brand_guide ?? '';
        $this->social_links = $client->social_links ?? '';
        $this->notes = $client->notes ?? '';
        $this->status = $client->status;
        $this->showForm = true;
    }

    public function save(): void
    {
        $this->validate([
            'name'          => 'required|string|max:255',
            'email'         => 'nullable|email|max:255',
            'package'       => 'required|string',
            'package_id'    => 'nullable|exists:packages,id',
            'status'        => 'required|string|in:active,inactive,pending',
            'amount'        => 'nullable|numeric|min:0',
            'contract_start'=> 'nullable|date',
            'contract_end'  => 'nullable|date|after_or_equal:contract_start',
        ]);

        $data = [
            'name'           => $this->name,
            'contact'        => $this->contact,
            'email'          => $this->email,
            'phone'          => $this->phone,
            'package'        => $this->package,
            'package_id'     => $this->package_id,
            'contract_start' => $this->contract_start,
            'contract_end'   => $this->contract_end,
            'amount'         => $this->amount,
            'deliverables'   => $this->deliverables,
            'brand_guide'    => $this->brand_guide,
            'social_links'   => $this->social_links,
            'notes'          => $this->notes,
            'status'         => $this->status,
        ];

        if ($this->formMode === 'edit' && $this->selectedClientId) {
            Client::findOrFail($this->selectedClientId)->update($data);
            $this->dispatch('toast', message: 'Client updated successfully', type: 'success');
        } else {
            $client = Client::create($data);

            $this->provisionClientAccount($client);

            $this->dispatch('toast', message: 'Client created successfully', type: 'success');
        }

        $this->showForm = false;
        $this->resetForm();
        $this->dispatch('refreshClients');
    }

    protected function provisionClientAccount(Client $client): void
    {
        if (! $client->email) {
            return;
        }

        $plainPassword = Str::password(12, true, true, true);
        $accountEmail = $client->email;

        $existingAccount = ClientAccount::where('email', $accountEmail)->first();
        if ($existingAccount) {
            $accountEmail = $client->email . '+' . Str::random(4) . '@' . substr(strrchr($client->email, '@'), 1);
        }

        ClientAccount::create([
            'client_id' => $client->id,
            'email'     => $accountEmail,
            'password'  => Hash::make($plainPassword),
            'name'      => $client->contact ?? $client->name,
            'status'    => 'active',
        ]);

        $packageName = $client->linkedPackage?->name ?? ucfirst($client->package);

        try {
            Mail::to($client->email)->queue(new ClientWelcomeMail(
                client: $client,
                packageName: $packageName,
            ));
        } catch (\Exception $e) {
            Log::warning('Failed to queue client welcome email to ' . $client->email . ': ' . $e->getMessage());
        }

        try {
            Mail::to($accountEmail)->queue(new ClientCredentialsMail(
                name: $client->contact ?? $client->name,
                email: $accountEmail,
                password: $plainPassword,
            ));
        } catch (\Exception $e) {
            Log::warning('Failed to queue client credentials email to ' . $accountEmail . ': ' . $e->getMessage());
        }

        $this->generatedEmail = $accountEmail;
        $this->generatedPassword = $plainPassword;
        $this->showCredentialsModal = true;

        try {
            app(\App\Services\NotificationService::class)->notifyNewClient(
                $client->name,
                $accountEmail,
            );
        } catch (\Exception $e) {
            Log::warning('Failed to create client notification: ' . $e->getMessage());
        }
    }

    public function view(int $id): void
    {
        $client = Client::with('linkedPackage')->find($id);
        if (! $client) return;

        $this->selectedClient = $client;
        $this->detailTab = 'overview';
        $this->showDetail = true;
    }

    // ── Status Toggle with Confirmation ──────────────────────

    public function confirmStatus(int $clientId, string $newStatus): void
    {
        $client = Client::find($clientId);
        if (! $client) return;

        $this->statusClientId = $clientId;
        $this->currentStatus = $client->status;
        $this->newStatus = $newStatus;
        $this->showStatusConfirm = true;
    }

    public function performStatusChange(): void
    {
        $client = Client::find($this->statusClientId);
        if ($client) {
            $client->update(['status' => $this->newStatus]);
            $this->dispatch('toast', message: "Client status changed to {$this->newStatus}", type: 'success');
        }
        $this->showStatusConfirm = false;
        $this->statusClientId = 0;
        $this->newStatus = '';
        $this->currentStatus = '';
        $this->dispatch('refreshClients');
    }

    public function cancelStatusChange(): void
    {
        $this->showStatusConfirm = false;
        $this->statusClientId = 0;
        $this->newStatus = '';
        $this->currentStatus = '';
    }

    public function delete(int $id): void
    {
        $this->deleteId = $id;
        $this->showDeleteConfirm = true;
    }

    public function performDelete(): void
    {
        $client = Client::find($this->deleteId);
        if ($client) {
            $client->delete();
            $this->dispatch('toast', message: 'Client deleted successfully', type: 'success');
        }
        $this->deleteId = 0;
        $this->showDeleteConfirm = false;
        $this->dispatch('refreshClients');
    }

    public function cancelDelete(): void
    {
        $this->deleteId = 0;
        $this->showDeleteConfirm = false;
    }

    public function resetForm(): void
    {
        $this->name = '';
        $this->contact = '';
        $this->email = '';
        $this->phone = '';
        $this->package = 'basic';
        $this->package_id = null;
        $this->contract_start = null;
        $this->contract_end = null;
        $this->amount = 0;
        $this->deliverables = '';
        $this->brand_guide = '';
        $this->social_links = '';
        $this->notes = '';
        $this->status = 'active';
        $this->selectedClientId = 0;
        $this->generatedEmail = '';
        $this->generatedPassword = '';
    }

    public function getClientStats(Client $client): array
    {
        return [
            'total_content'     => $client->contents()->count(),
            'active_workflows'  => $client->workflows()->whereNotIn('status', ['completed', 'published'])->count(),
            'pending_approvals' => $client->approvals()->where('status', 'pending')->count(),
            'total_invoices'    => $client->invoices()->count(),
            'paid_amount'       => (float) $client->invoices()->where('status', 'paid')->sum('amount'),
            'outstanding'       => (float) $client->invoices()->where('status', '!=', 'paid')->sum('amount'),
        ];
    }

    public function getClientWorkflows(Client $client)
    {
        return $client->workflows()->latest()->limit(10)->get();
    }

    public function getClientContents(Client $client)
    {
        return $client->contents()->latest()->limit(10)->get();
    }

    public function getClientInvoices(Client $client)
    {
        return $client->invoices()->latest()->limit(10)->get();
    }
}; ?>

<div>
    {{-- ========== HEADER ========== --}}
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-extrabold text-gray-900">Clients</h1>
            <p class="text-sm text-gray-500 mt-1">Manage your clients and their projects</p>
        </div>
        <button wire:click="create" class="btn btn-primary"><i class="fas fa-plus text-xs"></i> Add Client</button>
    </div>

    {{-- ========== FILTERS BAR ========== --}}
    <div class="mb-4 grid grid-cols-1 sm:grid-cols-3 gap-3 items-end">
        <div>
            <label class="form-label">Search</label>
            <div class="relative">
                <input
                    type="text"
                    wire:model.live.debounce.250ms="search"
                    placeholder="Search clients..."
                    class="form-input pl-10 focus:ring-0"
                />
                <i class="fas fa-search absolute left-3.5 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
            </div>
        </div>
        <div>
            <label class="form-label">Status</label
            ><select wire:model.live="statusFilter" class="form-select">
                <option value="">All Status</option>
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
                <option value="pending">Pending</option>
            </select>
        </div>
        <div>
            <label class="form-label">Package</label
            ><select wire:model.live="packageFilter" class="form-select">
                <option value="">All Packages</option>
                @foreach (\App\Models\Package::where('status', 'active')->orderBy('name')->get() as $pkg)
                    <option value="{{ $pkg->slug }}">{{ $pkg->name }}</option>
                @endforeach
            </select>
        </div>
    </div>

    {{-- Skeleton loader --}}
    <div wire:loading.delay class="space-y-4 p-6">
        <div class="h-8 bg-gray-200 rounded animate-pulse w-1/3"></div>
        <div class="h-4 bg-gray-200 rounded animate-pulse w-2/3"></div>
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
            <div class="h-20 bg-gray-200 rounded-xl animate-pulse"></div>
            <div class="h-20 bg-gray-200 rounded-xl animate-pulse"></div>
            <div class="h-20 bg-gray-200 rounded-xl animate-pulse"></div>
            <div class="h-20 bg-gray-200 rounded-xl animate-pulse"></div>
        </div>
        <div class="h-64 bg-gray-200 rounded-2xl animate-pulse"></div>
    </div>

    {{-- ========== CLIENTS TABLE ========== --}}
    <div
        wire:loading.remove.delay
        class="bg-white rounded-2xl border border-gray-100 overflow-hidden"
        wire:loading.target="search,statusFilter"
    >
        <div wire:loading class="p-6 space-y-3">
            @for ($i = 0; $i < 5; $i++)
                <div class="skeleton-row">
                    <div class="skeleton skeleton-avatar"></div>
                    <div class="flex-1">
                        <div class="skeleton skeleton-text"></div>
                        <div class="skeleton skeleton-text-sm"></div>
                    </div>
                </div>
            @endfor
        </div>
        <div wire:loading.remove wire:target="search,statusFilter">
            <div class="overflow-x-auto">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Contact</th>
                            <th>Email</th>
                            <th>Phone</th>
                            <th>Package</th>
                            <th>Status</th>
                            <th>Expiry</th>
                            <th>Contract End</th>
                            <th class="text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->clients as $client)
                            <tr>
                                <td>
                                    <div class="flex items-center gap-3">
                                        <div
                                            class="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-full bg-[rgba(var(--brand-rgb),0.1)] text-xs font-bold text-[var(--brand)]"
                                        >
                                            {{ $client->initials }}
                                        </div>
                                        <span class="font-semibold text-gray-900">{{ $client->name }}</span>
                                    </div>
                                </td>
                                <td class="text-gray-600">{{ $client->contact ?? '-' }}</td>
                                <td class="text-gray-600">{{ $client->email ?? '-' }}</td>
                                <td class="text-gray-600">{{ $client->phone ?? '-' }}</td>
                                <td>
                                    <span
                                        class="badge badge-{{ $client->package }}"
                                        >{{ ucfirst($client->linkedPackage?->name ?? $client->package) }}</span
                                    >
                                </td>
                                <td>
                                    <div class="relative" x-data="{ open: false }" @click.outside="open = false">
                                        <button
                                            @click="open = !open"
                                            class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold cursor-pointer transition-all hover:shadow-sm select-none
                                                {{ match($client->status) {
                                                    'active' => 'bg-green-100 text-green-700',
                                                    'inactive' => 'bg-gray-100 text-gray-600',
                                                    'pending' => 'bg-blue-100 text-blue-700',
                                                    default => 'bg-gray-100 text-gray-600',
                                                } }}"
                                        >
                                            <span>{{ ucfirst($client->status) }}</span>
                                            <svg class="w-3 h-3 opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                                            </svg>
                                        </button>
                                        <div
                                            x-show="open"
                                            x-transition
                                            class="absolute z-10 mt-1 w-36 rounded-xl border border-gray-100 bg-white py-1 shadow-lg"
                                        >
                                            @foreach (['active', 'inactive', 'pending'] as $statusOption)
                                                @if ($statusOption !== $client->status)
                                                    <button
                                                        wire:click="confirmStatus({{ $client->id }}, '{{ $statusOption }}')"
                                                        @click="open = false"
                                                        class="w-full px-3 py-2 text-left text-xs font-medium hover:bg-gray-50
                                                            {{ match($statusOption) {
                                                                'active' => 'text-green-600',
                                                                'inactive' => 'text-gray-600',
                                                                'pending' => 'text-blue-600',
                                                                default => 'text-gray-600',
                                                            } }}"
                                                    >
                                                        {{ ucfirst($statusOption) }}
                                                    </button>
                                                @endif
                                            @endforeach
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    @php
                                        $expiryClass = match($client->expiry_status) {
                                            'expired'     => 'bg-red-100 text-red-700',
                                            'critical'    => 'bg-red-100 text-red-700',
                                            'warning'     => 'bg-amber-100 text-amber-700',
                                            'active'      => 'bg-green-100 text-green-700',
                                            'no-contract' => 'bg-gray-100 text-gray-500',
                                            default       => 'bg-gray-100 text-gray-500',
                                        };
                                    @endphp
                                    <span
                                        class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-semibold {{ $expiryClass }}"
                                    >
                                        {{ $client->expiry_label }}
                                    </span>
                                </td>
                                <td class="text-gray-600">
                                    {{ $client->contract_end ? $client->contract_end->format('M d, Y') : '-' }}
                                </td>
                                <td>
                                    <div class="flex items-center justify-end gap-1">
                                        <button
                                            wire:click="view({{ $client->id }})"
                                            class="btn btn-icon btn-ghost"
                                            title="View Details"
                                        >
                                            <i class="fas fa-eye text-gray-400 hover:text-[var(--brand)]"></i>
                                        </button>
                                        <button
                                            wire:click="edit({{ $client->id }})"
                                            class="btn btn-icon btn-ghost"
                                            title="Edit Client"
                                        >
                                            <i class="fas fa-pen text-gray-400 hover:text-[var(--brand)]"></i>
                                        </button>
                                        <button
                                            wire:click="delete({{ $client->id }})"
                                            class="btn btn-icon btn-ghost"
                                            title="Delete Client"
                                        >
                                            <i class="fas fa-trash text-gray-400 hover:text-red-500"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9">
                                    <div class="py-16 text-center">
                                        <div
                                            class="flex h-14 w-14 mx-auto items-center justify-center rounded-2xl bg-gray-100 mb-4"
                                        >
                                            <i class="fas fa-users text-2xl text-gray-300"></i>
                                        </div>
                                        <p class="text-gray-500 font-medium text-sm">No clients found</p>
                                        <p class="text-gray-400 text-xs mt-1">Try adjusting your filters or add a new client</p>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($this->clients->hasPages())
                <div class="border-t border-gray-100 px-4 py-3">{{ $this->clients->links() }}</div>
            @endif
        </div>
    </div>

    {{-- ========== CLIENT FORM MODAL (Create / Edit) ========== --}}
    @if ($showForm)
        <div class="modal-overlay" x-data x-on:keydown.escape.window="$wire.set('showForm', false)">
            <div class="modal-box max-w-3xl max-h-[90vh]" x-on:click.stop>
                <div class="modal-header">
                    <h3 class="text-base font-bold text-gray-900">
                        <i class="fas fa-{{ $formMode === 'edit' ? 'pen' : 'plus' }} text-[var(--brand)] mr-2"></i>
                        {{ $formMode === 'edit' ? 'Edit Client' : 'Add New Client' }}
                    </h3>
                    <button
                        wire:click="$set('showForm', false)"
                        class="flex h-8 w-8 items-center justify-center rounded-lg text-gray-400 hover:bg-gray-100 hover:text-gray-600 transition-colors"
                    >
                        <i class="fas fa-times text-sm"></i>
                    </button>
                </div>

                <div class="modal-body overflow-y-auto max-h-[calc(90vh-80px)]">
                    <form wire:submit="save">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="form-label">Client Name <span class="text-red-500">*</span></label>
                                <input
                                    type="text"
                                    wire:model="name"
                                    class="form-input"
                                    placeholder="Enter client name"
                                />
                                @error ('name')
                                    <span class="text-xs text-red-500 mt-1 block">{{ $message }}</span>
                                @enderror
                                <span wire:error="name" class="text-red-500 text-xs mt-1 block"></span>
                            </div>
                            <div>
                                <label class="form-label">Contact Person</label>
                                <input
                                    type="text"
                                    wire:model="contact"
                                    class="form-input"
                                    placeholder="Contact person name"
                                />
                            </div>
                            <div>
                                <label class="form-label">Email</label>
                                <input
                                    type="email"
                                    wire:model="email"
                                    class="form-input"
                                    placeholder="client@example.com"
                                />
                                @error ('email')
                                    <span class="text-xs text-red-500 mt-1 block">{{ $message }}</span>
                                @enderror
                                <span wire:error="email" class="text-red-500 text-xs mt-1 block"></span>
                            </div>
                            <div>
                                <label class="form-label">Phone</label>
                                <input type="text" wire:model="phone" class="form-input" placeholder="+977-XXXXXXXXX" />
                            </div>
                            <div>
                                <label class="form-label">Package <span class="text-red-500">*</span></label>
                                <select wire:model="package_id" class="form-select">
                                    <option value="">Select package...</option>
                                    @foreach ($this->packages as $pkg)
                                        <option value="{{ $pkg->id }}">
                                            {{ $pkg->name }} — NPR {{ number_format($pkg->monthly_amount, 0) }}/mo
                                        </option>
                                    @endforeach
                                </select>
                                <span wire:error="package_id" class="text-red-500 text-xs mt-1 block"></span>
                            </div>
                            <div>
                                <label class="form-label">Monthly Amount (NPR)</label>
                                <input
                                    type="number"
                                    wire:model="amount"
                                    step="0.01"
                                    min="0"
                                    class="form-input"
                                    placeholder="0.00"
                                />
                            </div>
                            <div>
                                <label class="form-label">Contract Start</label>
                                <input type="date" wire:model="contract_start" class="form-input" />
                            </div>
                            <div>
                                <label class="form-label">Contract End</label>
                                <input type="date" wire:model="contract_end" class="form-input" />
                                @error ('contract_end')
                                    <span class="text-xs text-red-500 mt-1 block">{{ $message }}</span>
                                @enderror
                            </div>
                            <div>
                                <label class="form-label">Status <span class="text-red-500">*</span></label>
                                <select wire:model="status" class="form-select">
                                    <option value="active">Active</option>
                                    <option value="inactive">Inactive</option>
                                    <option value="pending">Pending</option>
                                </select>
                            </div>
                        </div>

                        <div class="mt-4 space-y-4">
                            <div>
                                <label class="form-label">Deliverables</label>
                                <textarea
                                    wire:model="deliverables"
                                    class="form-textarea"
                                    rows="2"
                                    placeholder="List of deliverables..."
                                ></textarea>
                            </div>
                            <div>
                                <label class="form-label">Brand Guide</label>
                                <textarea
                                    wire:model="brand_guide"
                                    class="form-textarea"
                                    rows="2"
                                    placeholder="Brand guidelines and notes..."
                                ></textarea>
                            </div>
                            <div>
                                <label class="form-label">Social Links</label>
                                <textarea
                                    wire:model="social_links"
                                    class="form-textarea"
                                    rows="2"
                                    placeholder="One URL per line..."
                                ></textarea>
                            </div>
                            <div>
                                <label class="form-label">Notes</label>
                                <textarea
                                    wire:model="notes"
                                    class="form-textarea"
                                    rows="2"
                                    placeholder="Additional notes..."
                                ></textarea>
                            </div>
                        </div>

                        <div class="mt-6 flex items-center justify-end gap-3 border-t border-gray-100 pt-5">
                            <button
                                type="button"
                                wire:click="$set('showForm', false)"
                                class="btn btn-secondary"
                                wire:loading.attr="disabled"
                            >
                                Cancel
                            </button>
                            <button
                                type="submit"
                                class="btn btn-primary"
                                wire:loading.attr="disabled"
                                wire:target="save"
                            >
                                <span wire:loading.remove wire:target="save"
                                    ><i class="fas fa-save text-xs"></i>
                                    {{ $formMode === 'edit' ? 'Update Client' : 'Create Client' }}</span
                                >
                                <span wire:loading wire:target="save"
                                    ><i class="fas fa-spinner fa-spin text-xs"></i> Saving...</span
                                >
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif

    {{-- ========== CLIENT DETAIL MODAL ========== --}}
    @if ($showDetail && $selectedClient)
        <div class="modal-overlay" x-data x-on:keydown.escape.window="$wire.set('showDetail', false)">
            <div class="modal-box max-w-5xl max-h-[90vh]" x-on:click.stop>
                <div class="modal-header">
                    <div class="flex items-center gap-3 flex-1 min-w-0">
                        <div
                            class="flex h-11 w-11 flex-shrink-0 items-center justify-center rounded-full bg-[rgba(var(--brand-rgb),0.1)] text-sm font-bold text-[var(--brand)]"
                        >
                            {{ $selectedClient->initials }}
                        </div>
                        <div class="min-w-0">
                            <h3 class="text-base font-bold text-gray-900 truncate">{{ $selectedClient->name }}</h3>
                            <p class="text-xs text-gray-500 truncate">
                                {{ $selectedClient->contact ?? 'No contact' }}
                                @if ($selectedClient->email) ·{{ $selectedClient->email }} @endif
                            </p>
                        </div>
                        @php
                            $statusBadgeClass = match($selectedClient->status) {
                                'active'   => 'bg-green-100 text-green-700',
                                'inactive' => 'bg-gray-100 text-gray-600',
                                'pending'  => 'bg-blue-100 text-blue-700',
                                default    => 'bg-gray-100 text-gray-600',
                            };
                        @endphp
                        <span
                            @class (['inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold', $statusBadgeClass])
                        >
                            {{ ucfirst($selectedClient->status) }}
                        </span>
                    </div>
                    <button
                        wire:click="$set('showDetail', false)"
                        class="flex h-8 w-8 items-center justify-center rounded-lg text-gray-400 hover:bg-gray-100 hover:text-gray-600 transition-colors"
                    >
                        <i class="fas fa-times text-sm"></i>
                    </button>
                </div>

                <div class="modal-body overflow-y-auto max-h-[calc(90vh-80px)]">
                    {{-- 6-Stat Grid --}}
                    @php $stats = $this->getClientStats($selectedClient); @endphp
                    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3 mb-6">
                        <div class="rounded-xl border border-gray-100 bg-gray-50 p-3 text-center">
                            <p class="text-xl font-extrabold text-gray-900">{{ $stats['total_content'] }}</p>
                            <p class="text-[11px] font-medium text-gray-500 mt-0.5">Total Content</p>
                        </div>
                        <div class="rounded-xl border border-gray-100 bg-gray-50 p-3 text-center">
                            <p class="text-xl font-extrabold text-gray-900">{{ $stats['active_workflows'] }}</p>
                            <p class="text-[11px] font-medium text-gray-500 mt-0.5">Active Workflows</p>
                        </div>
                        <div class="rounded-xl border border-gray-100 bg-gray-50 p-3 text-center">
                            <p class="text-xl font-extrabold text-amber-600">{{ $stats['pending_approvals'] }}</p>
                            <p class="text-[11px] font-medium text-gray-500 mt-0.5">Pending Approvals</p>
                        </div>
                        <div class="rounded-xl border border-gray-100 bg-gray-50 p-3 text-center">
                            <p class="text-xl font-extrabold text-gray-900">{{ $stats['total_invoices'] }}</p>
                            <p class="text-[11px] font-medium text-gray-500 mt-0.5">Total Invoices</p>
                        </div>
                        <div class="rounded-xl border border-gray-100 bg-green-50 p-3 text-center">
                            <p class="text-xl font-extrabold text-green-600">NPR {{ number_format($stats['paid_amount'], 0) }}</p>
                            <p class="text-[11px] font-medium text-gray-500 mt-0.5">Paid Amount</p>
                        </div>
                        <div class="rounded-xl border border-gray-100 bg-red-50 p-3 text-center">
                            <p class="text-xl font-extrabold text-red-600">NPR {{ number_format($stats['outstanding'], 0) }}</p>
                            <p class="text-[11px] font-medium text-gray-500 mt-0.5">Outstanding</p>
                        </div>
                    </div>

                    {{-- Tabs --}}
                    <div class="tab-group mb-5">
                        <button
                            wire:click="$set('detailTab', 'overview')"
                            class="tab-btn {{ $detailTab === 'overview' ? 'active' : '' }}"
                        >
                            <i class="fas fa-info-circle mr-1"></i> Overview
                        </button>
                        <button
                            wire:click="$set('detailTab', 'workflows')"
                            class="tab-btn {{ $detailTab === 'workflows' ? 'active' : '' }}"
                        >
                            <i class="fas fa-project-diagram mr-1"></i> Workflows
                        </button>
                        <button
                            wire:click="$set('detailTab', 'content')"
                            class="tab-btn {{ $detailTab === 'content' ? 'active' : '' }}"
                        >
                            <i class="fas fa-calendar-alt mr-1"></i> Content
                        </button>
                        <button
                            wire:click="$set('detailTab', 'invoices')"
                            class="tab-btn {{ $detailTab === 'invoices' ? 'active' : '' }}"
                        >
                            <i class="fas fa-receipt mr-1"></i> Invoices
                        </button>
                    </div>

                    {{-- Tab: Overview --}}
                    @if ($detailTab === 'overview')
                        <div class="space-y-5">
                            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                                <div>
                                    <p class="text-[11px] font-semibold uppercase tracking-wider text-gray-400 mb-1">Package</p>
                                    <span class="badge badge-{{ $selectedClient->package }}">
                                        {{ ucfirst($selectedClient->linkedPackage?->name ?? $selectedClient->package) }}
                                    </span>
                                </div>
                                <div>
                                    <p class="text-[11px] font-semibold uppercase tracking-wider text-gray-400 mb-1">Monthly Amount</p>
                                    <p class="text-sm font-semibold text-gray-900">NPR {{ number_format($selectedClient->amount, 2) }}</p>
                                </div>
                                <div>
                                    <p class="text-[11px] font-semibold uppercase tracking-wider text-gray-400 mb-1">Contract Start</p>
                                    <p class="text-sm text-gray-700">{{ $selectedClient->contract_start ? $selectedClient->contract_start->format('M d, Y') : '-' }}</p>
                                </div>
                                <div>
                                    <p class="text-[11px] font-semibold uppercase tracking-wider text-gray-400 mb-1">Contract End</p>
                                    <p class="text-sm text-gray-700">{{ $selectedClient->contract_end ? $selectedClient->contract_end->format('M d, Y') : '-' }}</p>
                                </div>
                            </div>

                            {{-- Expiry Status Bar --}}
                            @if ($selectedClient->contract_end)
                                @php
                                    $expiryBarClass = match($selectedClient->expiry_status) {
                                        'expired' => 'border-red-200 bg-red-50',
                                        'critical' => 'border-red-200 bg-red-50',
                                        'warning' => 'border-amber-200 bg-amber-50',
                                        'active' => 'border-green-200 bg-green-50',
                                        default => 'border-gray-200 bg-gray-50',
                                    };
                                    $expiryTextClass = match($selectedClient->expiry_status) {
                                        'expired' => 'text-red-700',
                                        'critical' => 'text-red-700',
                                        'warning' => 'text-amber-700',
                                        'active' => 'text-green-700',
                                        default => 'text-gray-600',
                                    };
                                @endphp
                                <div
                                    class="rounded-xl border {{ $expiryBarClass }} p-3 flex items-center justify-between"
                                >
                                    <div class="flex items-center gap-2">
                                        <i
                                            class="fas fa-{{ $selectedClient->expiry_status === 'expired' ? 'times-circle' : ($selectedClient->expiry_status === 'active' ? 'check-circle' : 'exclamation-triangle') }} {{ $expiryTextClass }}"
                                        ></i>
                                        <span class="text-sm font-semibold {{ $expiryTextClass }}">
                                            @if ($selectedClient->expiry_status === 'expired')
                                                Contract expired {{ $selectedClient->daysUntilExpiry() }} day(s) ago
                                            @elseif ($selectedClient->expiry_status === 'no-contract')
                                                No contract end date set
                                            @else
                                                {{ $selectedClient->daysUntilExpiry() }} day(s) until contract expires
                                            @endif
                                        </span>
                                    </div>
                                    <span
                                        class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $selectedClient->expiry_badge_class }}"
                                    >
                                        {{ $selectedClient->expiry_label }}
                                    </span>
                                </div>
                            @endif

                            {{-- Package Limits (if linked) --}}
                            @if ($selectedClient->linkedPackage)
                                <div class="rounded-xl border border-gray-100 bg-gray-50 p-4">
                                    <p class="text-[11px] font-semibold uppercase tracking-wider text-gray-400 mb-3">Package Limits</p>
                                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-center">
                                        <div>
                                            <p class="text-lg font-bold text-gray-900">{{ $selectedClient->linkedPackage->content_limit }}</p>
                                            <p class="text-[11px] text-gray-500">Content/mo</p>
                                        </div>
                                        <div>
                                            <p class="text-lg font-bold text-gray-900">{{ $selectedClient->linkedPackage->workflow_limit }}</p>
                                            <p class="text-[11px] text-gray-500">Workflows</p>
                                        </div>
                                        <div>
                                            <p class="text-lg font-bold text-gray-900">{{ number_format($selectedClient->linkedPackage->storage_limit_mb) }}MB</p>
                                            <p class="text-[11px] text-gray-500">Storage</p>
                                        </div>
                                        <div>
                                            <p class="text-lg font-bold text-gray-900">{{ $selectedClient->linkedPackage->revision_limit }}</p>
                                            <p class="text-[11px] text-gray-500">Revisions</p>
                                        </div>
                                    </div>
                                </div>
                            @endif

                            <hr class="border-gray-100" />

                            @if ($selectedClient->deliverables)
                                <div>
                                    <p class="text-[11px] font-semibold uppercase tracking-wider text-gray-400 mb-2">Deliverables</p>
                                    <p class="text-sm text-gray-700 whitespace-pre-wrap leading-relaxed">{{ $selectedClient->deliverables }}</p>
                                </div>
                            @endif

                            @if ($selectedClient->brand_guide)
                                <div>
                                    <p class="text-[11px] font-semibold uppercase tracking-wider text-gray-400 mb-2">Brand Guide</p>
                                    <p class="text-sm text-gray-700 whitespace-pre-wrap leading-relaxed">{{ $selectedClient->brand_guide }}</p>
                                </div>
                            @endif

                            @if ($selectedClient->social_links)
                                <div>
                                    <p class="text-[11px] font-semibold uppercase tracking-wider text-gray-400 mb-2">Social Links</p>
                                    <p class="text-sm text-gray-700 whitespace-pre-wrap leading-relaxed">{{ $selectedClient->social_links }}</p>
                                </div>
                            @endif

                            @if ($selectedClient->notes)
                                <div>
                                    <p class="text-[11px] font-semibold uppercase tracking-wider text-gray-400 mb-2">Notes</p>
                                    <p class="text-sm text-gray-700 whitespace-pre-wrap leading-relaxed">{{ $selectedClient->notes }}</p>
                                </div>
                            @endif

                            @if (!$selectedClient->deliverables && !$selectedClient->brand_guide && !$selectedClient->social_links && !$selectedClient->notes)
                                <div class="py-8 text-center">
                                    <i class="fas fa-file-alt text-2xl text-gray-200 mb-2"></i>
                                    <p class="text-gray-400 text-xs">No additional details added yet</p>
                                </div>
                            @endif
                        </div>
                    @endif

                    {{-- Tab: Workflows --}}
                    @if ($detailTab === 'workflows')
                        @php $workflows = $this->getClientWorkflows($selectedClient); @endphp
                        @if ($workflows->isEmpty())
                            <div class="py-12 text-center">
                                <div
                                    class="flex h-12 w-12 mx-auto items-center justify-center rounded-2xl bg-gray-100 mb-3"
                                >
                                    <i class="fas fa-project-diagram text-xl text-gray-300"></i>
                                </div>
                                <p class="text-gray-500 text-sm font-medium">No workflows yet</p>
                                <p class="text-gray-400 text-xs mt-1">Workflows for this client will appear here</p>
                            </div>
                        @else
                            <div class="overflow-x-auto rounded-xl border border-gray-100">
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th>Title</th>
                                            <th>Type</th>
                                            <th>Stage</th>
                                            <th>Deadline</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($workflows as $wf)
                                            <tr>
                                                <td class="font-medium text-gray-900">{{ $wf->title }}</td>
                                                <td class="text-gray-600">{{ $wf->type ?? '-' }}</td>
                                                <td class="text-gray-600">{{ $wf->stage ?? '-' }}</td>
                                                <td class="text-gray-600">
                                                    {{ $wf->deadline ? $wf->deadline->format('M d, Y') : '-' }}
                                                </td>
                                                <td>
                                                    <span
                                                        class="badge badge-{{ $wf->status }}"
                                                        >{{ ucfirst($wf->status) }}</span
                                                    >
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    @endif

                    {{-- Tab: Content --}}
                    @if ($detailTab === 'content')
                        @php $contents = $this->getClientContents($selectedClient); @endphp
                        @if ($contents->isEmpty())
                            <div class="py-12 text-center">
                                <div
                                    class="flex h-12 w-12 mx-auto items-center justify-center rounded-2xl bg-gray-100 mb-3"
                                >
                                    <i class="fas fa-calendar-alt text-xl text-gray-300"></i>
                                </div>
                                <p class="text-gray-500 text-sm font-medium">No content yet</p>
                                <p class="text-gray-400 text-xs mt-1">Content for this client will appear here</p>
                            </div>
                        @else
                            <div class="overflow-x-auto rounded-xl border border-gray-100">
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th>Title</th>
                                            <th>Platform</th>
                                            <th>Type</th>
                                            <th>Date</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($contents as $c)
                                            <tr>
                                                <td class="font-medium text-gray-900">{{ $c->title }}</td>
                                                <td>
                                                    <span
                                                        class="badge badge-{{ $c->platform }}"
                                                        >{{ ucfirst($c->platform) }}</span
                                                    >
                                                </td>
                                                <td class="text-gray-600">{{ $c->type ?? '-' }}</td>
                                                <td class="text-gray-600">
                                                    {{ $c->date ? $c->date->format('M d, Y') : '-' }}
                                                </td>
                                                <td>
                                                    <span
                                                        class="badge badge-{{ $c->status }}"
                                                        >{{ ucfirst($c->status) }}</span
                                                    >
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    @endif

                    {{-- Tab: Invoices --}}
                    @if ($detailTab === 'invoices')
                        @php $invoices = $this->getClientInvoices($selectedClient); @endphp
                        @if ($invoices->isEmpty())
                            <div class="py-12 text-center">
                                <div
                                    class="flex h-12 w-12 mx-auto items-center justify-center rounded-2xl bg-gray-100 mb-3"
                                >
                                    <i class="fas fa-receipt text-xl text-gray-300"></i>
                                </div>
                                <p class="text-gray-500 text-sm font-medium">No invoices yet</p>
                                <p class="text-gray-400 text-xs mt-1">Invoices for this client will appear here</p>
                            </div>
                        @else
                            <div class="overflow-x-auto rounded-xl border border-gray-100">
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th>Amount</th>
                                            <th>Status</th>
                                            <th>Payment Status</th>
                                            <th>Due Date</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($invoices as $inv)
                                            <tr>
                                                <td class="font-semibold text-gray-900">
                                                    NPR {{ number_format($inv->amount, 2) }}
                                                </td>
                                                <td>
                                                    <span
                                                        class="badge badge-{{ $inv->status }}"
                                                        >{{ ucfirst($inv->status) }}</span
                                                    >
                                                </td>
                                                <td>
                                                    <span
                                                        class="badge badge-{{ $inv->payment_status }}"
                                                        >{{ ucfirst($inv->payment_status) }}</span
                                                    >
                                                </td>
                                                <td class="text-gray-600">
                                                    {{ $inv->due_date ? $inv->due_date->format('M d, Y') : '-' }}
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    @endif
                </div>
            </div>
        </div>
    @endif

    {{-- ========== STATUS CHANGE CONFIRMATION DIALOG ========== --}}
    @if ($showStatusConfirm)
        <div class="confirm-overlay" x-data x-on:keydown.escape.window="$wire.cancelStatusChange()">
            <div class="confirm-box">
                <div
                    class="confirm-icon {{ $newStatus === 'active' ? 'bg-green-100 text-green-600' : ($newStatus === 'inactive' ? 'bg-gray-100 text-gray-600' : 'bg-blue-100 text-blue-600') }}"
                >
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4" />
                    </svg>
                </div>
                <h3 class="mb-2 text-lg font-bold text-gray-900">Change Client Status</h3>
                <p class="mb-6 text-sm text-gray-500">Change status from <strong class="text-gray-700">{{ ucfirst($currentStatus) }}</strong> to <strong class="text-gray-700">{{ ucfirst($newStatus) }}</strong>?</p>
                <div class="flex gap-3">
                    <button
                        wire:click="cancelStatusChange"
                        class="flex-1 rounded-xl border border-gray-200 bg-white px-4 py-2.5 text-sm font-semibold text-gray-600 hover:bg-gray-50 transition-colors"
                    >
                        Cancel
                    </button>
                    <button
                        wire:click="performStatusChange"
                        class="flex-1 rounded-xl {{ $newStatus === 'active' ? 'bg-green-600 hover:bg-green-700' : ($newStatus === 'inactive' ? 'bg-gray-600 hover:bg-gray-700' : 'bg-blue-600 hover:bg-blue-700') }} px-4 py-2.5 text-sm font-semibold text-white transition-colors"
                    >
                        <i class="fas fa-check text-xs mr-1"></i> Confirm
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- ========== DELETE CONFIRMATION DIALOG ========== --}}
    @if ($showDeleteConfirm)
        <div class="confirm-overlay" x-data x-on:keydown.escape.window="$wire.cancelDelete()">
            <div class="confirm-box">
                <div class="confirm-icon danger">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                    </svg>
                </div>
                <h3 class="mb-2 text-lg font-bold text-gray-900">Delete Client</h3>
                <p class="mb-6 text-sm text-gray-500">Are you sure you want to delete this client? All associated data will be permanently removed. This action cannot be undone.</p>
                <div class="flex gap-3">
                    <button
                        wire:click="cancelDelete"
                        class="flex-1 rounded-xl border border-gray-200 bg-white px-4 py-2.5 text-sm font-semibold text-gray-600 hover:bg-gray-50 transition-colors"
                    >
                        Cancel
                    </button>
                    <button
                        wire:click="performDelete"
                        class="flex-1 rounded-xl bg-red-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-red-700 transition-colors"
                    >
                        <i class="fas fa-trash text-xs mr-1"></i> Delete
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- ========== CLIENT CREDENTIALS MODAL ========== --}}
    @if ($showCredentialsModal)
        <div class="confirm-overlay" x-data x-on:keydown.escape.window="$wire.set('showCredentialsModal', false)">
            <div class="confirm-box max-w-md">
                <div class="confirm-icon bg-green-100 text-green-600">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z" />
                    </svg>
                </div>
                <h3 class="mb-2 text-lg font-bold text-gray-900">Client Portal Credentials</h3>
                <p class="mb-4 text-sm text-gray-500">A client portal account has been created and login credentials have been sent to the client's email.</p>

                <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 mb-4">
                    <p class="text-xs font-semibold text-amber-700 uppercase tracking-wider mb-2">
                        <i class="fas fa-exclamation-triangle mr-1"></i> Save These Credentials
                    </p>
                    <div class="space-y-2">
                        <div>
                            <p class="text-[11px] font-medium text-gray-500">Login Email</p>
                            <p class="text-sm font-mono font-semibold text-gray-900 select-all">{{ $generatedEmail }}</p>
                        </div>
                        <div>
                            <p class="text-[11px] font-medium text-gray-500">Password</p>
                            <p class="text-sm font-mono font-semibold text-gray-900 select-all">{{ $generatedPassword }}</p>
                        </div>
                    </div>
                </div>

                <button
                    wire:click="$set('showCredentialsModal', false)"
                    class="w-full rounded-xl bg-[var(--brand)] px-4 py-2.5 text-sm font-semibold text-white hover:opacity-90 transition-colors"
                >
                    Got it
                </button>
            </div>
        </div>
    @endif
</div>

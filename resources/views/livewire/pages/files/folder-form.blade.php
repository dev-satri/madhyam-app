<?php

use Livewire\Volt\Component;
use Illuminate\Support\Facades\DB;

new class extends Component
{
    public int $editingId = 0;
    public string $name = '';
    public int $clientId = 0;
    public int $parentId = 0;

    public function mount(int $editingId = 0, int $parentId = 0): void
    {
        $this->parentId = $parentId;
        if ($editingId) {
            $folder = DB::table('folders')->where('id', $editingId)->first();
            if ($folder) {
                $this->editingId = $editingId;
                $this->name = $folder->name;
                $this->clientId = $folder->client_id ?? 0;
            }
        }
    }

    public function save(): void
    {
        $this->validate(['name' => 'required|string|max:255']);

        $data = [
            'name' => $this->name,
            'parent_id' => $this->parentId ?: null,
            'client_id' => $this->clientId ?: null,
        ];

        if ($this->editingId) {
            DB::table('folders')->where('id', $this->editingId)->update($data);
        } else {
            DB::table('folders')->insert(array_merge($data, [
                'created_at' => now(),
                'updated_at' => now(),
            ]));
        }

        $this->dispatch('toast', message: 'Folder saved', type: 'success');
        $this->dispatch('folder-saved');
    }

    public function render(): mixed    {
        $clients = DB::table('clients')->where('status', 'active')->orderBy('name')->get();

        return <<<'blade'
        <div class="space-y-4">
            <div>
                <label class="form-label">Folder Name</label>
                <input type="text" wire:model="name" class="form-input" placeholder="Enter folder name">
                @error('name') <span class="text-red-500 text-xs">{{ $message }}</span> @enderror
            </div>
            <div>
                <label class="form-label">Client (optional)</label>
                <select wire:model="clientId" class="form-select">
                    <option value="">None</option>
                    @foreach($clients as $c)
                        <option value="{{ $c->id }}">{{ $c->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="flex justify-end gap-2">
                <button type="button" class="btn btn-secondary" onclick="Livewire.dispatch('close-modal')">Cancel</button>
                <button wire:click="save" class="btn btn-primary">Save</button>
            </div>
        </div>
        blade;
    }
}

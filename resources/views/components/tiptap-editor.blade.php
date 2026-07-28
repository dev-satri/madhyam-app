@props([
    'name' => 'body',
    'value' => '',
    'placeholder' => 'Write something...',
    'wire' => null,
])

<div
    wire:ignore
    wire:key="tiptap-{{ $name }}"
    x-data="tiptapEditor($refs.{{ $name }}Input)"
    x-on:tiptap-set-content.window="if ($event.detail?.name === '{{ $name }}') setContentSafe($event.detail?.html ?? '')"
    class="tiptap-editor border border-gray-200 rounded-xl overflow-hidden bg-white focus-within:border-[var(--brand)] focus-within:ring-1 focus-within:ring-[var(--brand)]/20 transition-colors"
>
    {{-- Toolbar --}}
    <div class="flex items-center gap-0.5 px-2 py-1.5 border-b border-gray-100 bg-gray-50/50 flex-wrap">
        <button type="button" @click="toggleBold()" class="tiptap-btn" :class="isActive('bold') && 'tiptap-btn-active'" title="Bold">
            <i class="fas fa-bold text-xs"></i>
        </button>
        <button type="button" @click="toggleItalic()" class="tiptap-btn" :class="isActive('italic') && 'tiptap-btn-active'" title="Italic">
            <i class="fas fa-italic text-xs"></i>
        </button>
        <div class="w-px h-4 bg-gray-200 mx-0.5"></div>
        <button type="button" @click="toggleHeading(2)" class="tiptap-btn" :class="isActive('heading', {level:2}) && 'tiptap-btn-active'" title="Heading">
            <i class="fas fa-heading text-xs"></i>
        </button>
        <button type="button" @click="toggleBulletList()" class="tiptap-btn" :class="isActive('bulletList') && 'tiptap-btn-active'" title="Bullet List">
            <i class="fas fa-list-ul text-xs"></i>
        </button>
        <button type="button" @click="toggleOrderedList()" class="tiptap-btn" :class="isActive('orderedList') && 'tiptap-btn-active'" title="Numbered List">
            <i class="fas fa-list-ol text-xs"></i>
        </button>
        <button type="button" @click="toggleBlockquote()" class="tiptap-btn" :class="isActive('blockquote') && 'tiptap-btn-active'" title="Quote">
            <i class="fas fa-quote-right text-xs"></i>
        </button>
        <div class="w-px h-4 bg-gray-200 mx-0.5"></div>
        <button type="button" @click="setLink()" class="tiptap-btn" :class="isActive('link') && 'tiptap-btn-active'" title="Add Link">
            <i class="fas fa-link text-xs"></i>
        </button>
        <button type="button" @click="unsetLink()" class="tiptap-btn" title="Remove Link" x-show="isActive('link')">
            <i class="fas fa-link-slash text-xs"></i>
        </button>
        <button type="button" @click="addImage()" class="tiptap-btn" title="Add Image">
            <i class="fas fa-image text-xs"></i>
        </button>
        <div class="w-px h-4 bg-gray-200 mx-0.5"></div>
        <button type="button" @click="undo()" class="tiptap-btn" title="Undo">
            <i class="fas fa-undo text-xs"></i>
        </button>
        <button type="button" @click="redo()" class="tiptap-btn" title="Redo">
            <i class="fas fa-redo text-xs"></i>
        </button>
    </div>

    {{-- Editor --}}
    <div x-ref="editorContainer" class="tiptap-content px-3 py-2 min-h-[100px] max-h-[300px] overflow-y-auto prose prose-sm max-w-none text-sm"></div>

    {{-- Hidden input for Livewire binding --}}
    @if($wire)
        <textarea x-ref="{{ $name }}Input" wire:model="{{ $wire }}" class="hidden"></textarea>
    @else
        <textarea x-ref="{{ $name }}Input" name="{{ $name }}" class="hidden">{{ $value }}</textarea>
    @endif

    {{-- Media Picker Modal --}}
    <template x-if="_showMediaPicker">
        <div class="fixed inset-0 z-[90] flex items-end sm:items-center justify-center bg-black/50 backdrop-blur-sm p-0 sm:p-4" @click.self="_showMediaPicker = false" x-on:keydown.escape.window="_showMediaPicker = false">
            <div class="bg-white rounded-t-2xl sm:rounded-2xl w-full sm:max-w-lg max-h-[80vh] flex flex-col overflow-hidden">
                <div class="flex items-center justify-between px-4 py-3 border-b shrink-0">
                    <h3 class="font-bold text-gray-900" x-text="_pickMode === 'image' ? 'Insert Image' : 'Insert Link'"></h3>
                    <button @click="_showMediaPicker = false" class="w-8 h-8 flex items-center justify-center rounded-lg hover:bg-gray-100 text-gray-400"><i class="fas fa-times"></i></button>
                </div>

                <div class="px-4 py-3 border-b bg-gray-50 shrink-0">
                    <p class="text-[11px] font-semibold text-gray-500 uppercase mb-2">Paste a URL</p>
                    <div class="flex gap-2">
                        <input type="url" x-model="_manualUrl" :placeholder="_pickMode === 'image' ? 'https://example.com/image.jpg' : 'https://example.com'" class="form-input text-sm flex-1" x-on:keydown.enter.prevent="_submitManualUrl()">
                        <button @click="_submitManualUrl()" class="btn btn-primary btn-sm px-3 shrink-0" :disabled="!_manualUrl"><i class="fas fa-check text-xs"></i></button>
                    </div>
                </div>

                <div class="px-4 py-2 border-b shrink-0">
                    <p class="text-[11px] font-semibold text-gray-500 uppercase">Or pick from library</p>
                </div>

                <div class="flex-1 overflow-y-auto p-4 space-y-1 min-h-0">
                    <template x-if="_mediaLoading">
                        <div class="text-center py-8 text-gray-400 text-sm"><i class="fas fa-spinner fa-spin mr-1"></i> Loading...</div>
                    </template>
                    <template x-if="!_mediaLoading && _mediaFiles.length === 0">
                        <div class="text-center py-8 text-gray-400 text-sm">
                            <i class="fas fa-folder-open text-2xl text-gray-200 mb-2 block"></i>
                            No files found
                        </div>
                    </template>
                    <template x-for="file in _mediaFiles" :key="file.id">
                        <button @click="_selectMediaFile(file)" class="flex items-center gap-3 p-2 rounded-lg hover:bg-gray-50 cursor-pointer transition-colors w-full text-left">
                            <div class="flex-shrink-0 w-8 h-8 rounded flex items-center justify-center"
                                 :class="file.type === 'image' ? 'bg-blue-50' : file.type === 'video' ? 'bg-purple-50' : 'bg-gray-50'">
                                <i class="fas text-xs" :class="file.type === 'image' ? 'fa-image text-blue-500' : file.type === 'video' ? 'fa-video text-purple-500' : 'fa-file text-gray-500'"></i>
                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-medium text-gray-800 truncate" x-text="file.name"></p>
                                <p class="text-[11px] text-gray-400" x-text="file.size_label"></p>
                            </div>
                        </button>
                    </template>
                </div>
            </div>
        </div>
    </template>
</div>

@props ([
    'name' => 'body',
    'value' => '',
    'placeholder' => 'Write something...',
    'wire' => null,
])

@once
    <script>
        (function () {
            if (window.tiptapEditor) return;

            window.tiptapEditor = function (target) {
                var editorInstance = null;
                var initializing = false;
                var syncing = false;

                function alive() {
                    return (
                        editorInstance && editorInstance.view && editorInstance.view.dom && editorInstance.view.dom.isConnected
                    );
                }

                function safe(fn) {
                    if (!alive()) return;
                    try {
                        return fn();
                    } catch (e) {
                        return;
                    }
                }

                var data = {
                    _hasEditor: false,
                    _destroyed: false,
                    _tick: 0,

                    init() {
                        if (editorInstance || initializing) return;
                        initializing = true;
                        this._destroyed = false;

                        var self = this;
                        Promise.all([
                            import('@tiptap/core'),
                            import('@tiptap/starter-kit'),
                            import('@tiptap/extension-placeholder')
                        ])
                            .then(function (mods) {
                                if (self._destroyed) return;
                                var EditorClass = mods[0].Editor;
                                var StarterKitMod = mods[1].default;
                                var PlaceholderMod = mods[2].default;
                                try {
                                    editorInstance = new EditorClass({
                                        element: self.$refs.editorContainer,
                                        extensions: [
                                            StarterKitMod.configure({
                                                heading: { levels: [2, 3] },
                                                link: {
                                                    openOnClick: false,
                                                    HTMLAttributes: { class: 'text-blue-600 underline' }
                                                }
                                            }),
                                            PlaceholderMod.configure({ placeholder: 'Write something...' })
                                        ],
                                        content: target ? target.value : '',
                                        onUpdate: function (params) {
                                            if (self._destroyed || syncing) return;
                                            var html = params.editor.getHTML();
                                            if (target) {
                                                target.value = html;
                                                target.dispatchEvent(new window.Event('input', { bubbles: true }));
                                            }
                                            self.$dispatch('tiptap-change', { html: html });
                                            self._hasEditor = true;
                                            self._tick = (self._tick || 0) + 1;
                                        }
                                    });
                                    self._hasEditor = true;
                                    self._tick = 0;
                                } catch (err) {
                                    editorInstance = null;
                                    console.error('Failed to init editor:', err);
                                }
                            })
                            .catch(function (err) {
                                console.error('Failed to load tiptap:', err);
                            })
                            .finally(function () {
                                initializing = false;
                            });
                    },

                    destroy() {
                        this._destroyed = true;
                        this._hasEditor = false;
                        if (editorInstance) {
                            try {
                                editorInstance.destroy();
                            } catch (_) {}
                            editorInstance = null;
                        }
                    },

                    setContentSafe(html) {
                        if (!alive() || this._destroyed) return;
                        var incoming = html || '';
                        var current;
                        try {
                            current = editorInstance.getHTML();
                        } catch (_) {
                            return;
                        }
                        var norm = function (h) {
                            return h === '<p></p>' ? '' : h;
                        };
                        if (norm(current) === norm(incoming)) return;
                        try {
                            syncing = true;
                            editorInstance.commands.setContent(incoming, false);
                            if (target) target.value = incoming;
                            this._tick = (this._tick || 0) + 1;
                        } catch (_) {
                        } finally {
                            syncing = false;
                        }
                    },

                    toggleBold() {
                        safe(function () {
                            editorInstance.chain().focus().toggleBold().run();
                        });
                        data._tick++;
                    },
                    toggleItalic() {
                        safe(function () {
                            editorInstance.chain().focus().toggleItalic().run();
                        });
                        data._tick++;
                    },
                    toggleHeading(l) {
                        safe(function () {
                            editorInstance.chain().focus().toggleHeading({ level: l }).run();
                        });
                        data._tick++;
                    },
                    toggleBulletList() {
                        safe(function () {
                            editorInstance.chain().focus().toggleBulletList().run();
                        });
                        data._tick++;
                    },
                    toggleOrderedList() {
                        safe(function () {
                            editorInstance.chain().focus().toggleOrderedList().run();
                        });
                        data._tick++;
                    },
                    toggleBlockquote() {
                        safe(function () {
                            editorInstance.chain().focus().toggleBlockquote().run();
                        });
                        data._tick++;
                    },
                    unsetLink() {
                        safe(function () {
                            editorInstance.chain().focus().unsetLink().run();
                        });
                        data._tick++;
                    },
                    undo() {
                        safe(function () {
                            editorInstance.chain().focus().undo().run();
                        });
                        data._tick++;
                    },
                    redo() {
                        safe(function () {
                            editorInstance.chain().focus().redo().run();
                        });
                        data._tick++;
                    },

                    isActive(name, attrs) {
                        void data._tick;
                        if (!alive() || this._destroyed) return false;
                        try {
                            return editorInstance.isActive(name, attrs);
                        } catch (_) {
                            return false;
                        }
                    }
                };

                return data;
            };
        })();
    </script>
@endonce

<div
    wire:ignore
    wire:key="tiptap-{{ $name }}"
    x-data="tiptapEditor($refs.{{ $name }}Input)"
    x-on:tiptap-set-content.window="if ($event.detail?.name === '{{ $name }}') setContentSafe($event.detail?.html ?? '')"
    class="tiptap-editor border border-gray-200 rounded-xl overflow-hidden bg-white focus-within:border-[var(--brand)] focus-within:ring-1 focus-within:ring-[var(--brand)]/20 transition-colors"
>
    {{-- Toolbar --}}
    <div class="flex items-center gap-0.5 px-2 py-1.5 border-b border-gray-100 bg-gray-50/50 flex-wrap">
        <button
            type="button"
            @click="toggleBold()"
            class="tiptap-btn"
            :class="isActive('bold') && 'tiptap-btn-active'"
            title="Bold"
        >
            <i class="fas fa-bold text-xs"></i>
        </button>
        <button
            type="button"
            @click="toggleItalic()"
            class="tiptap-btn"
            :class="isActive('italic') && 'tiptap-btn-active'"
            title="Italic"
        >
            <i class="fas fa-italic text-xs"></i>
        </button>
        <div class="w-px h-4 bg-gray-200 mx-0.5"></div>
        <button
            type="button"
            @click="toggleHeading(2)"
            class="tiptap-btn"
            :class="isActive('heading', { level: 2 }) && 'tiptap-btn-active'"
            title="Heading"
        >
            <i class="fas fa-heading text-xs"></i>
        </button>
        <button
            type="button"
            @click="toggleBulletList()"
            class="tiptap-btn"
            :class="isActive('bulletList') && 'tiptap-btn-active'"
            title="Bullet List"
        >
            <i class="fas fa-list-ul text-xs"></i>
        </button>
        <button
            type="button"
            @click="toggleOrderedList()"
            class="tiptap-btn"
            :class="isActive('orderedList') && 'tiptap-btn-active'"
            title="Numbered List"
        >
            <i class="fas fa-list-ol text-xs"></i>
        </button>
        <button
            type="button"
            @click="toggleBlockquote()"
            class="tiptap-btn"
            :class="isActive('blockquote') && 'tiptap-btn-active'"
            title="Quote"
        >
            <i class="fas fa-quote-right text-xs"></i>
        </button>
        <div class="w-px h-4 bg-gray-200 mx-0.5"></div>
        <button type="button" @click="unsetLink()" class="tiptap-btn" title="Remove Link" x-show="isActive('link')">
            <i class="fas fa-link-slash text-xs"></i>
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
    <div
        x-ref="editorContainer"
        class="tiptap-content px-3 py-2 min-h-[100px] max-h-[300px] overflow-y-auto prose prose-sm max-w-none text-sm"
    ></div>

    {{-- Hidden input for Livewire binding --}}
    @if ($wire)
        <textarea x-ref="{{ $name }}Input" wire:model="{{ $wire }}" class="hidden"></textarea>
    @else
        <textarea x-ref="{{ $name }}Input" name="{{ $name }}" class="hidden">{{ $value }}</textarea>
    @endif
</div>

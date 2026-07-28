/* globals $wire */
import { Editor } from '@tiptap/core';
import StarterKit from '@tiptap/starter-kit';
import Image from '@tiptap/extension-image';
import Placeholder from '@tiptap/extension-placeholder';

document.addEventListener('alpine:init', () => {
    Alpine.data('tiptapEditor', (target) => {
        // NOTE: keep `editor` in the closure, NOT on `this`.
        // Alpine's x-data deep-proxies anything on `this`. ProseMirror's
        // EditorView uses referential identity checks internally (state.doc,
        // view.state, etc). When those pass through Alpine's Proxy, identity
        // drifts and every `view.dispatch(tr)` throws
        //   "RangeError: Applying a mismatched transaction".
        let editorInstance = null;
        let initializing = false;
        let syncing = false;

        const alive = () => {
            return (
                editorInstance && editorInstance.view && editorInstance.view.dom && editorInstance.view.dom.isConnected
            );
        };

        const safe = (fn) => {
            if (!alive()) return;
            try {
                return fn();
            } catch {
                return;
            }
        };

        return {
            _hasEditor: false,
            _destroyed: false,
            _showMediaPicker: false,
            _pickMode: 'image',
            _mediaFiles: [],
            _mediaSearch: '',
            _mediaLoading: false,
            _manualUrl: '',

            init() {
                if (editorInstance || initializing) return;
                initializing = true;
                this._destroyed = false;

                try {
                    editorInstance = new Editor({
                        element: this.$refs.editorContainer,
                        extensions: [
                            StarterKit.configure({
                                heading: { levels: [2, 3] },
                                link: {
                                    openOnClick: false,
                                    HTMLAttributes: { class: 'text-blue-600 underline' }
                                }
                            }),
                            Image.configure({
                                HTMLAttributes: { class: 'rounded-lg max-h-48 my-2' }
                            }),
                            Placeholder.configure({
                                placeholder: 'Write something...'
                            })
                        ],
                        content: target?.value || '',
                        onUpdate: ({ editor: e }) => {
                            if (this._destroyed || syncing) return;
                            const html = e.getHTML();
                            if (target) {
                                target.value = html;
                                target.dispatchEvent(new window.Event('input', { bubbles: true }));
                            }
                            this.$dispatch('tiptap-change', { html });
                            // Bump reactive flag so toolbar :class bindings
                            // re-evaluate isActive() after content changes.
                            this._hasEditor = true;
                            this._tick = (this._tick || 0) + 1;
                        }
                    });
                    this._hasEditor = true;
                    this._tick = 0;
                } catch {
                    editorInstance = null;
                } finally {
                    initializing = false;
                }
            },

            destroy() {
                this._destroyed = true;
                this._hasEditor = false;
                if (editorInstance) {
                    try {
                        editorInstance.destroy();
                    } catch {
                        // ignore destroy errors
                    }
                    editorInstance = null;
                }
            },

            setContentSafe(html) {
                if (!alive() || this._destroyed) return;
                const incoming = html || '';
                let current;
                try {
                    current = editorInstance.getHTML();
                } catch {
                    return;
                }
                const norm = (h) => (h === '<p></p>' ? '' : h);
                if (norm(current) === norm(incoming)) return;
                try {
                    syncing = true;
                    editorInstance.commands.setContent(incoming, false);
                    if (target) target.value = incoming;
                    this._tick = (this._tick || 0) + 1;
                } catch {
                    // stale transaction — swallow
                } finally {
                    syncing = false;
                }
            },

            // Reactive tick — read this in :class bindings so Alpine re-runs
            // them after every editor mutation.
            _tick: 0,

            toggleBold() {
                safe(() => editorInstance.chain().focus().toggleBold().run());
                this._tick++;
            },
            toggleItalic() {
                safe(() => editorInstance.chain().focus().toggleItalic().run());
                this._tick++;
            },
            toggleHeading(l) {
                safe(() => editorInstance.chain().focus().toggleHeading({ level: l }).run());
                this._tick++;
            },
            toggleBulletList() {
                safe(() => editorInstance.chain().focus().toggleBulletList().run());
                this._tick++;
            },
            toggleOrderedList() {
                safe(() => editorInstance.chain().focus().toggleOrderedList().run());
                this._tick++;
            },
            toggleBlockquote() {
                safe(() => editorInstance.chain().focus().toggleBlockquote().run());
                this._tick++;
            },
            setLink() {
                this._pickMode = 'link';
                this._showMediaPicker = true;
                this._loadMediaFiles();
            },
            unsetLink() {
                safe(() => editorInstance.chain().focus().unsetLink().run());
                this._tick++;
            },
            addImage() {
                this._pickMode = 'image';
                this._showMediaPicker = true;
                this._loadMediaFiles();
            },
            undo() {
                safe(() => editorInstance.chain().focus().undo().run());
                this._tick++;
            },
            redo() {
                safe(() => editorInstance.chain().focus().redo().run());
                this._tick++;
            },

            async _loadMediaFiles() {
                this._mediaLoading = true;
                this._mediaSearch = '';
                try {
                    const res = await $wire.getPickableFiles('', null);
                    this._mediaFiles = res || [];
                } catch {
                    // eslint-disable-next-line no-console
                    console.error('Failed to load media files');
                    this._mediaFiles = [];
                }
                this._mediaLoading = false;
            },
            _selectMediaFile(file) {
                const url = file.url;
                if (!url) return;
                safe(() => {
                    if (this._pickMode === 'image') {
                        editorInstance.chain().focus().setImage({ src: url }).run();
                    } else {
                        editorInstance.chain().focus().setLink({ href: url }).run();
                    }
                });
                this._showMediaPicker = false;
                this._tick++;
            },
            _submitManualUrl() {
                const url = this._manualUrl;
                if (!url) return;
                safe(() => {
                    if (this._pickMode === 'image') {
                        editorInstance.chain().focus().setImage({ src: url }).run();
                    } else {
                        editorInstance.chain().focus().setLink({ href: url }).run();
                    }
                });
                this._manualUrl = '';
                this._showMediaPicker = false;
                this._tick++;
            },

            isActive(name, attrs) {
                // touch _tick so this getter re-runs when we bump it
                void this._tick;
                if (!alive() || this._destroyed) return false;
                try {
                    return editorInstance.isActive(name, attrs);
                } catch {
                    return false;
                }
            }
        };
    });
});

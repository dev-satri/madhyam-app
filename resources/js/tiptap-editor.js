// Tipty editor — Alpine data component is defined in a synchronous <script>
// inside the Blade component. This module provides the upgraded version
// with static imports and re-initializes stub elements after loading.

const tiptapEditorFactory = (target) => {
    let editorInstance = null;
    let initializing = false;
    let syncing = false;

    const alive = () =>
        editorInstance && editorInstance.view && editorInstance.view.dom && editorInstance.view.dom.isConnected;

    const safe = (fn) => {
        if (!alive()) return;
        try { return fn(); } catch { return; }
    };

    return {
        _hasEditor: false,
        _destroyed: false,
        _tick: 0,

        init() {
            if (editorInstance || initializing) return;
            initializing = true;
            this._destroyed = false;

            try {
                const Editor = window.__tiptapEditor_Constructor;
                const StarterKit = window.__tiptapEditor_StarterKit;
                const Placeholder = window.__tiptapEditor_Placeholder;

                if (Editor) {
                    this._createEditor(Editor, StarterKit, Placeholder, target);
                } else {
                    Promise.all([
                        import('@tiptap/core'),
                        import('@tiptap/starter-kit'),
                        import('@tiptap/extension-placeholder')
                    ]).then(([core, sk, ph]) => {
                        this._createEditor(core.Editor, sk.default, ph.default, target);
                    }).catch((err) => {
                        console.error('Failed to load tiptap:', err);
                    }).finally(() => {
                        initializing = false;
                    });
                    return;
                }
            } catch (err) {
                console.error('Editor init failed:', err);
                editorInstance = null;
            } finally {
                initializing = false;
            }
        },

        _createEditor(EditorClass, StarterKitMod, PlaceholderMod, tgt) {
            if (editorInstance || this._destroyed) return;
            try {
                editorInstance = new EditorClass({
                    element: this.$refs.editorContainer,
                    extensions: [
                        StarterKitMod.configure({
                            heading: { levels: [2, 3] },
                            link: { openOnClick: false, HTMLAttributes: { class: 'text-blue-600 underline' } }
                        }),
                        PlaceholderMod.configure({ placeholder: 'Write something...' })
                    ],
                    content: tgt?.value || '',
                    onUpdate: ({ editor: e }) => {
                        if (this._destroyed || syncing) return;
                        const html = e.getHTML();
                        if (tgt) {
                            tgt.value = html;
                            tgt.dispatchEvent(new window.Event('input', { bubbles: true }));
                        }
                        this.$dispatch('tiptap-change', { html });
                        this._hasEditor = true;
                        this._tick = (this._tick || 0) + 1;
                    }
                });
                this._hasEditor = true;
                this._tick = 0;
            } catch {
                editorInstance = null;
            }
        },

        destroy() {
            this._destroyed = true;
            this._hasEditor = false;
            if (editorInstance) {
                try { editorInstance.destroy(); } catch {}
                editorInstance = null;
            }
        },

        setContentSafe(html) {
            if (!alive() || this._destroyed) return;
            const incoming = html || '';
            let current;
            try { current = editorInstance.getHTML(); } catch { return; }
            const norm = (h) => (h === '<p></p>' ? '' : h);
            if (norm(current) === norm(incoming)) return;
            try {
                syncing = true;
                editorInstance.commands.setContent(incoming, false);
                if (target) target.value = incoming;
                this._tick = (this._tick || 0) + 1;
            } catch {} finally { syncing = false; }
        },

        toggleBold() { safe(() => editorInstance.chain().focus().toggleBold().run()); this._tick++; },
        toggleItalic() { safe(() => editorInstance.chain().focus().toggleItalic().run()); this._tick++; },
        toggleHeading(l) { safe(() => editorInstance.chain().focus().toggleHeading({ level: l }).run()); this._tick++; },
        toggleBulletList() { safe(() => editorInstance.chain().focus().toggleBulletList().run()); this._tick++; },
        toggleOrderedList() { safe(() => editorInstance.chain().focus().toggleOrderedList().run()); this._tick++; },
        toggleBlockquote() { safe(() => editorInstance.chain().focus().toggleBlockquote().run()); this._tick++; },
        unsetLink() { safe(() => editorInstance.chain().focus().unsetLink().run()); this._tick++; },
        undo() { safe(() => editorInstance.chain().focus().undo().run()); this._tick++; },
        redo() { safe(() => editorInstance.chain().focus().redo().run()); this._tick++; },

        isActive(name, attrs) {
            void this._tick;
            if (!alive() || this._destroyed) return false;
            try { return editorInstance.isActive(name, attrs); } catch { return false; }
        }
    };
};

// Replace the synchronous stub and upgrade in-flight elements
if (window.Alpine) {
    window.tiptapEditor = tiptapEditorFactory;

    requestAnimationFrame(() => {
        document.querySelectorAll('[x-data]').forEach((el) => {
            const attr = el.getAttribute('x-data');
            if (!attr || !attr.startsWith('tiptapEditor(')) return;
            const stack = el._x_dataStack;
            if (stack && stack[0] && !stack[0]._hasEditor) {
                if (typeof stack[0].destroy === 'function') stack[0].destroy();
                el._x_dataStack = null;
                try { Alpine.initTree(el); } catch {}
            }
        });
    });
}

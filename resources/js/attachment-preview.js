document.addEventListener('alpine:init', () => {
    Alpine.data('attachmentPreview', () => ({
        show: false,
        currentFile: { name: '', url: '', type: 'file', ext: '' },
        currentIndex: 0,
        allFiles: [],

        init() {
            const el = this.$el.querySelector('[data-attachment-files]');
            if (el) {
                try { this.allFiles = JSON.parse(el.textContent); } catch (e) { /* ignore */ }
            }
        },

        get isPdf() {
            return (
                this.currentFile.ext === 'pdf' ||
                (this.currentFile.url && this.currentFile.url.toLowerCase().endsWith('.pdf'))
            );
        },

        openPreview(file) {
            this.currentIndex = this.allFiles.findIndex(f => f.url === file.url);
            if (this.currentIndex < 0) this.currentIndex = 0;
            this.currentFile = file;
            this.show = true;
        },

        closePreview() {
            this.show = false;
        }
    }));
});

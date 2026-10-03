export default function organizationWorkspace(nodes, moveUrl) {
    return {
        nodes,
        moveUrl,
        search: '',
        selected: null,
        newParentId: '',
        confirmation: false,
        saving: false,
        draggedId: null,
        dropTarget: null,
        scale: 1,
        offsetX: 0,
        offsetY: 0,
        panning: false,
        panStart: null,
        get moveTargets() {
            if (!this.selected) return [];
            const blocked = new Set(this.descendants(this.selected.id).map((node) => node.id));
            return this.nodes.filter((node) => node.id !== this.selected.id && !blocked.has(node.id));
        },
        get parentName() {
            return this.moveTargets.find((node) => String(node.id) === String(this.newParentId))?.name || 'tanpa parent';
        },
        matches(id) {
            if (!this.search.trim()) return true;
            const node = this.nodes.find((item) => item.id === id);
            return `${node?.name || ''} ${node?.role || ''}`.toLowerCase().includes(this.search.toLowerCase());
        },
        select(id) {
            this.selected = this.nodes.find((node) => node.id === id) || null;
            this.newParentId = this.selected?.parent_id ? String(this.selected.parent_id) : '';
        },
        descendants(id) {
            const result = [];
            const visit = (parentId) => this.nodes.filter((node) => node.parent_id === parentId).forEach((node) => { result.push(node); visit(node.id); });
            visit(id);
            return result;
        },
        startDrag(id) { this.draggedId = id; },
        drop(id) {
            if (id === this.draggedId || this.descendants(this.draggedId).some((node) => node.id === id)) return;
            this.select(this.draggedId);
            this.newParentId = String(id);
            this.openConfirmation();
            this.dropTarget = null;
        },
        openConfirmation() {
            if (!this.selected || String(this.newParentId) === String(this.selected.parent_id || '')) return;
            this.confirmation = true;
        },
        async commitMove() {
            this.saving = true;
            try {
                const response = await fetch(this.moveUrl.replace('__USER__', this.selected.id), {
                    method: 'PATCH',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                    body: JSON.stringify({ parent_user_id: this.newParentId || null, expected_assignment_id: this.selected.assignment_id, expected_version: this.selected.assignment_version }),
                });
                if (response.status === 409) { window.oasisToast?.('Struktur organisasi telah berubah. Muat ulang lalu coba lagi.', 'error'); return; }
                if (!response.ok) { window.oasisToast?.('Perpindahan tidak diizinkan.', 'error'); return; }
                window.oasisToast?.('Struktur organisasi berhasil diperbarui.', 'success');
                window.location.reload();
            } finally { this.saving = false; this.confirmation = false; }
        },
        startPan(event) { if (event.target.closest('button')) return; this.panning = true; this.panStart = { x: event.clientX - this.offsetX, y: event.clientY - this.offsetY }; },
        pan(event) { if (this.panning) { this.offsetX = event.clientX - this.panStart.x; this.offsetY = event.clientY - this.panStart.y; } },
        endPan() { this.panning = false; },
        zoom(event) { this.scale = Math.min(1.5, Math.max(0.6, this.scale - event.deltaY * 0.001)); },
    };
}

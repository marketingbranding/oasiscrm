export default function organizationWorkspace(nodes, moveUrl, unitMoveUrl, removeStructureUrl, movableUnitIds, canMove = false) {
    return {
        nodes,
        moveUrl,
        unitMoveUrl,
        removeStructureUrl,
        movableUnitIds,
        canMove,
        viewMode: 'zones',
        showUnitLinks: false,
        search: '',
        selected: null,
        context: null,
        contextStyle: '',
        newParentId: '',
        confirmation: false,
        unitConfirmation: false,
        removeConfirmation: false,
        unitMoveTarget: null,
        saving: false,
        scale: 1,
        offsetX: 0,
        offsetY: 0,
        panning: false,
        panStart: null,
        visualDrag: null,
        activeConnection: null,
        activeUnitConnection: null,
        connectionTargetId: null,
        connectionTargetSocketType: null,
        unitConnectionTargetId: null,
        suppressClick: false,
        visualOffsets: {},
        layoutPositions: {},
        graphWidth: 960,
        graphHeight: 560,
        connectors: [],
        resizeHandler: null,

        init() {
            this.buildLayout();
            this.resizeHandler = () => this.$nextTick(() => {
                this.fitView();
                this.refreshConnectors();
            });
            window.addEventListener('resize', this.resizeHandler);
            this.$watch('search', () => this.$nextTick(() => {
                this.fitView();
                this.refreshConnectors();
            }));
            this.$watch('scale', () => this.$nextTick(() => this.refreshConnectors()));
            this.$nextTick(() => {
                this.fitView();
                this.refreshConnectors();
            });
        },

        destroy() {
            window.removeEventListener('resize', this.resizeHandler);
        },

        get moveTargets() {
            if (!this.selected || this.selected.kind !== 'user') return [];

            const blocked = new Set(this.descendants(this.selected.id).map((node) => String(node.id)));

            return this.nodes.filter((node) => node.kind === 'user'
                && String(node.id) !== String(this.selected.id)
                && !blocked.has(String(node.id)));
        },

        get parentName() {
            return this.moveTargets.find((node) => String(node.id) === String(this.newParentId))?.name || 'tanpa atasan';
        },

        get unitTargets() {
            return this.nodes.filter((node) => node.kind === 'unit' && this.movableUnitIds.includes(Number(node.unit_id)));
        },

        get zones() {
            return this.nodes
                .filter((node) => node.kind === 'unit')
                .map((unit) => ({
                    ...unit,
                    users: this.nodes.filter((node) => node.kind === 'user'
                        && String(node.unit_id) === String(unit.id)
                        && this.matches(node.id)),
                }))
                .filter((zone) => zone.users.length > 0 || this.matches(zone.id));
        },

        connectionSocketClass(id, type) {
            const isSource = this.activeConnection?.nodeId === id && this.activeConnection?.type === type;
            const isTarget = this.connectionTargetId === id && this.connectionTargetSocketType === type;

            return [
                isSource ? 'org-socket-source' : '',
                isTarget ? 'org-socket-target' : '',
            ].filter(Boolean).join(' ');
        },

        unitSocketClass(id, target = false) {
            const active = target
                ? this.unitConnectionTargetId === id
                : this.activeUnitConnection?.nodeId === id;

            return active ? 'org-unit-socket-active' : '';
        },

        matches(id) {
            if (!this.search.trim()) return true;

            const node = this.nodes.find((item) => String(item.id) === String(id));

            return `${node?.name || ''} ${node?.role || ''}`.toLowerCase().includes(this.search.toLowerCase());
        },

        select(id) {
            this.selected = this.nodes.find((node) => String(node.id) === String(id)) || null;
            this.newParentId = this.selected?.parent_user_id ? String(this.selected.parent_user_id) : '';
        },

        openContext(event, id) {
            this.select(id);
            this.context = this.selected;

            const margin = 12;
            const gap = 12;
            const estimatedWidth = 304;
            const estimatedHeight = 280;
            const left = Math.min(event.clientX + gap, window.innerWidth - estimatedWidth - margin);
            const top = Math.min(event.clientY + gap, window.innerHeight - estimatedHeight - margin);

            this.contextStyle = `left: ${Math.max(margin, left)}px; top: ${Math.max(margin, top)}px;`;
            this.$nextTick(() => this.positionContextCard(event.clientX, event.clientY));
        },

        positionContextCard(clientX, clientY) {
            const card = this.$refs.contextCard;
            if (!card) return;

            const margin = 12;
            const gap = 12;
            const rect = card.getBoundingClientRect();
            const selectedNode = this.selected && this.$refs.graph.querySelector(`[data-org-node="${this.selected.id}"]`);
            const selectedRect = selectedNode?.getBoundingClientRect();
            const candidates = [
                { left: clientX + gap, top: clientY + gap },
                { left: clientX - rect.width - gap, top: clientY + gap },
                { left: clientX + gap, top: clientY - rect.height - gap },
                { left: clientX - rect.width - gap, top: clientY - rect.height - gap },
            ];
            const fitsViewport = (candidate) => candidate.left >= margin
                && candidate.top >= margin
                && candidate.left + rect.width <= window.innerWidth - margin
                && candidate.top + rect.height <= window.innerHeight - margin;
            const overlapsSelected = (candidate) => selectedRect && candidate.left < selectedRect.right + gap
                && candidate.left + rect.width > selectedRect.left - gap
                && candidate.top < selectedRect.bottom + gap
                && candidate.top + rect.height > selectedRect.top - gap;
            const candidate = candidates.find((item) => fitsViewport(item) && !overlapsSelected(item)) || candidates.find(fitsViewport) || candidates[0];

            if (selectedRect && overlapsSelected(candidate)) {
                const safeLeft = [
                    selectedRect.left - rect.width - gap,
                    selectedRect.right + gap,
                ].find((left) => left >= margin && left + rect.width <= window.innerWidth - margin);

                if (safeLeft !== undefined) candidate.left = safeLeft;
            }

            let left = candidate.left;
            let top = candidate.top;

            left = Math.min(Math.max(margin, left), Math.max(margin, window.innerWidth - rect.width - margin));
            top = Math.min(Math.max(margin, top), Math.max(margin, window.innerHeight - rect.height - margin));
            this.contextStyle = `left: ${left}px; top: ${top}px;`;
        },

        closeContext() {
            this.context = null;
        },

        openMove() {
            if (!this.selected || this.selected.kind !== 'user') return;

            this.newParentId = this.selected.parent_user_id ? String(this.selected.parent_user_id) : '';
            this.context = null;
            this.confirmation = true;
        },

        descendants(id) {
            const result = [];
            const visit = (parentId) => this.nodes
                .filter((node) => String(this.treeParent(node)) === String(parentId))
                .forEach((node) => {
                    result.push(node);
                    visit(node.id);
                });

            visit(id);

            return result;
        },

        treeParent(node) {
            return node?.tree_parent_id || null;
        },

        nodeStyle(id) {
            const position = this.layoutPositions[id] || { x: 0, y: 0 };
            const offset = this.visualOffsets[id] || { x: 0, y: 0 };

            return `left: ${position.x}px; top: ${position.y}px; transform: translate(${offset.x}px, ${offset.y}px);`;
        },

        graphStyle() {
            return `width: ${this.graphWidth}px; height: ${this.graphHeight}px; transform: translate(${this.offsetX}px, ${this.offsetY}px) scale(${this.scale});`;
        },

        buildLayout() {
            const byId = new Map(this.nodes.map((node) => [String(node.id), node]));
            const columnWidth = 340;
            const nodeHeight = 132;
            const nodeGap = 64;
            const rowHeight = nodeHeight + nodeGap;
            const positions = {};
            const childrenByParent = new Map();

            this.nodes.forEach((node) => {
                const parentId = String(this.treeParent(node) || '');
                if (!parentId || !byId.has(parentId)) return;
                if (!childrenByParent.has(parentId)) childrenByParent.set(parentId, []);
                childrenByParent.get(parentId).push(node);
            });

            const roots = this.nodes.filter((node) => {
                const parentId = String(this.treeParent(node) || '');

                return !parentId || !byId.has(parentId);
            });
            const connectedRoots = roots.filter((node) => childrenByParent.has(String(node.id)));
            const isolatedRoots = roots.filter((node) => !childrenByParent.has(String(node.id)));
            const subtreeHeights = new Map();
            let maxDepth = 0;

            const subtreeHeight = (node, trail = new Set()) => {
                const id = String(node.id);
                if (subtreeHeights.has(id)) return subtreeHeights.get(id);
                if (trail.has(id)) return nodeHeight;

                const children = childrenByParent.get(id) || [];
                const height = children.length === 0
                    ? nodeHeight
                    : Math.max(
                        nodeHeight,
                        children.reduce((total, child) => total + subtreeHeight(child, new Set(trail).add(id)), 0)
                            + nodeGap * (children.length - 1),
                    );
                subtreeHeights.set(id, height);

                return height;
            };

            const placeTree = (node, depth, top, left = 0, trail = new Set()) => {
                const id = String(node.id);
                if (trail.has(id)) return;
                const children = childrenByParent.get(id) || [];
                const nextTrail = new Set(trail).add(id);

                maxDepth = Math.max(maxDepth, depth);
                if (children.length === 0) {
                    positions[node.id] = { x: left + depth * columnWidth, y: top };
                    return;
                }

                let childTop = top;
                children.forEach((child) => {
                    placeTree(child, depth + 1, childTop, left, nextTrail);
                    childTop += subtreeHeight(child, nextTrail) + nodeGap;
                });

                const firstChild = positions[children[0].id];
                const lastChild = positions[children[children.length - 1].id];
                const childCenter = (firstChild.y + lastChild.y + nodeHeight) / 2;
                positions[node.id] = {
                    x: left + depth * columnWidth,
                    y: Math.max(top, childCenter - nodeHeight / 2),
                };
                maxDepth = Math.max(maxDepth, depth);
            };

            const treeDepth = (node, trail = new Set()) => {
                const id = String(node.id);
                if (trail.has(id)) return 0;

                const children = childrenByParent.get(id) || [];
                if (children.length === 0) return 0;

                const nextTrail = new Set(trail).add(id);

                return 1 + Math.max(...children.map((child) => treeDepth(child, nextTrail)));
            };

            let connectedWidth = 0;
            let connectedHeight = 0;
            connectedRoots.forEach((root) => {
                placeTree(root, 0, 0, connectedWidth);
                connectedWidth += (treeDepth(root) + 1) * columnWidth + nodeGap;
                connectedHeight = Math.max(connectedHeight, subtreeHeight(root));
            });

            const isolatedTop = connectedRoots.length > 0 ? connectedHeight + nodeGap : 0;
            const isolatedColumns = Math.max(1, Math.min(4, isolatedRoots.length));
            isolatedRoots.forEach((root, index) => {
                positions[root.id] = {
                    x: (index % isolatedColumns) * columnWidth,
                    y: isolatedTop + Math.floor(index / isolatedColumns) * rowHeight,
                };
            });

            this.layoutPositions = positions;
            this.graphWidth = Math.max(960, connectedWidth + 80, (Math.max(maxDepth + 1, isolatedColumns) * columnWidth) + 80);
            this.graphHeight = Math.max(
                560,
                isolatedTop + Math.ceil(isolatedRoots.length / isolatedColumns) * rowHeight + 80,
                connectedHeight + 80,
            );
        },

        connectorPath(kind = 'hierarchy') {
            return this.connectors.filter((connector) => connector.kind === kind).map((connector) => {
                if (connector.orientation === 'horizontal') {
                    const middleX = (connector.x1 + connector.x2) / 2;

                    return `M ${connector.x1} ${connector.y1} C ${middleX} ${connector.y1}, ${middleX} ${connector.y2}, ${connector.x2} ${connector.y2}`;
                }

                const middleY = (connector.y1 + connector.y2) / 2;

                return `M ${connector.x1} ${connector.y1} C ${connector.x1} ${middleY}, ${connector.x2} ${middleY}, ${connector.x2} ${connector.y2}`;
            }).join(' ');
        },

        connectionPreviewPath() {
            if (!this.activeConnection?.pointer) return '';

            const socket = this.socketElement(this.activeConnection.nodeId, this.activeConnection.type);
            const graph = this.$refs.graph;

            if (!socket || !graph) return '';

            const graphRect = graph.getBoundingClientRect();
            const socketRect = socket.getBoundingClientRect();
            const startX = (socketRect.left - graphRect.left + socketRect.width / 2) / this.scale;
            const startY = (socketRect.top - graphRect.top + socketRect.height / 2) / this.scale;
            const pointer = this.pointerToGraph(this.activeConnection.pointer);
            const middleX = (startX + pointer.x) / 2;

            return `M ${startX} ${startY} C ${middleX} ${startY}, ${middleX} ${pointer.y}, ${pointer.x} ${pointer.y}`;
        },

        unitConnectionPreviewPath() {
            if (!this.activeUnitConnection?.pointer) return '';

            const socket = this.unitSocketElement(this.activeUnitConnection.nodeId, true);
            const graph = this.$refs.graph;

            if (!socket || !graph) return '';

            const graphRect = graph.getBoundingClientRect();
            const socketRect = socket.getBoundingClientRect();
            const startX = (socketRect.left - graphRect.left + socketRect.width / 2) / this.scale;
            const startY = (socketRect.top - graphRect.top + socketRect.height / 2) / this.scale;
            const pointer = this.pointerToGraph(this.activeUnitConnection.pointer);
            const middleX = (startX + pointer.x) / 2;

            return `M ${startX} ${startY} C ${middleX} ${startY}, ${middleX} ${pointer.y}, ${pointer.x} ${pointer.y}`;
        },

        socketElement(nodeId, type) {
            return this.$refs.graph?.querySelector(`[data-org-socket-node="${nodeId}"][data-org-socket-type="${type}"]`);
        },

        unitSocketElement(nodeId, source) {
            return this.$refs.graph?.querySelector(`[data-org-unit-${source ? 'source' : 'target'}="${nodeId}"]`);
        },

        startConnection(event, nodeId, type) {
            if (!this.canMove || event.button !== 0 || this.saving) return;

            event.preventDefault();
            this.visualDrag = null;
            this.panning = false;
            this.connectionTargetId = null;
            this.connectionTargetSocketType = null;
            this.activeConnection = {
                nodeId,
                type,
                pointer: event,
            };
            event.currentTarget.setPointerCapture?.(event.pointerId);
        },

        startUnitConnection(event, nodeId) {
            if (!this.canMove || event.button !== 0 || this.saving) return;

            event.preventDefault();
            this.visualDrag = null;
            this.panning = false;
            this.unitConnectionTargetId = null;
            this.activeUnitConnection = { nodeId, pointer: event };
            event.currentTarget.setPointerCapture?.(event.pointerId);
        },

        connectionTarget(event) {
            const socket = document.elementFromPoint(event.clientX, event.clientY)?.closest('[data-org-socket]');

            if (!socket || !this.activeConnection) return null;

            const nodeId = Number(socket.dataset.orgSocketNode);
            const type = socket.dataset.orgSocketType;

            if (this.activeConnection.nodeId === nodeId || this.activeConnection.type === type) return null;

            return { nodeId, type };
        },

        unitConnectionTarget(event) {
            const socket = document.elementFromPoint(event.clientX, event.clientY)?.closest('[data-org-unit-target]');
            if (!socket || !this.activeUnitConnection) return null;

            const nodeId = socket.dataset.orgUnitTarget;
            if (nodeId === this.nodes.find((node) => String(node.id) === String(this.activeUnitConnection.nodeId))?.unit_id) return null;

            return nodeId;
        },

        resolveConnection(target) {
            if (!target || !this.activeConnection) return null;

            return this.activeConnection.type === 'output'
                ? { childId: target.nodeId, parentId: this.activeConnection.nodeId }
                : { childId: this.activeConnection.nodeId, parentId: target.nodeId };
        },

        cancelConnection() {
            this.activeConnection = null;
            this.activeUnitConnection = null;
            this.connectionTargetId = null;
            this.connectionTargetSocketType = null;
            this.unitConnectionTargetId = null;
        },

        pointerToGraph(event) {
            const graph = this.$refs.graph;
            const canvas = this.$refs.canvas;
            const canvasRect = canvas.getBoundingClientRect();
            const graphRect = graph.getBoundingClientRect();

            return {
                x: (event.clientX - canvasRect.left - (graphRect.left - canvasRect.left)) / this.scale,
                y: (event.clientY - canvasRect.top - (graphRect.top - canvasRect.top)) / this.scale,
            };
        },

        startVisualDrag(event, id) {
            if (event.button !== 0) return;

            const draggedNode = this.nodes.find((item) => String(item.id) === String(id));
            if (!draggedNode || draggedNode.kind !== 'user') return;

            const node = event.currentTarget;
            const graph = this.$refs.graph;
            const graphRect = graph.getBoundingClientRect();
            const offset = this.visualOffsets[id] || { x: 0, y: 0 };
            const pointer = this.pointerToGraph(event);
            const nodeRect = node.getBoundingClientRect();
            const nodePosition = {
                x: (nodeRect.left - graphRect.left) / this.scale,
                y: (nodeRect.top - graphRect.top) / this.scale,
            };

            this.visualDrag = {
                id,
                startX: event.clientX,
                startY: event.clientY,
                baseX: nodePosition.x - offset.x,
                baseY: nodePosition.y - offset.y,
                grabX: pointer.x - nodePosition.x,
                grabY: pointer.y - nodePosition.y,
                moved: false,
                unitTargetId: null,
            };
            node.setPointerCapture?.(event.pointerId);
        },

        handlePointerMove(event) {
            if (this.activeConnection) {
                this.activeConnection.pointer = event;
                const target = this.connectionTarget(event);
                this.connectionTargetId = target?.nodeId || null;
                this.connectionTargetSocketType = target?.type || null;

                return;
            }

            if (this.activeUnitConnection) {
                this.activeUnitConnection.pointer = event;
                this.unitConnectionTargetId = this.unitConnectionTarget(event);

                return;
            }

            if (this.visualDrag) {
                const drag = this.visualDrag;
                const pointer = this.pointerToGraph(event);
                const x = pointer.x - drag.grabX - drag.baseX;
                const y = pointer.y - drag.grabY - drag.baseY;

                if (Math.abs(event.clientX - drag.startX) > 4 || Math.abs(event.clientY - drag.startY) > 4) {
                    drag.moved = true;
                }

                const unitTarget = document.elementFromPoint(event.clientX, event.clientY)?.closest('[data-org-unit-drop]');
                const targetId = unitTarget?.dataset.orgUnitDrop || null;
                const draggedNode = this.nodes.find((item) => String(item.id) === String(drag.id));
                drag.unitTargetId = targetId && String(targetId) !== String(draggedNode?.unit_id || '') ? targetId : null;

                this.visualOffsets = { ...this.visualOffsets, [drag.id]: { x, y } };
                this.$nextTick(() => this.refreshConnectors());

                return;
            }

            this.pan(event);
        },

        endPointerInteraction(event) {
            if (this.activeConnection) {
                const connection = this.resolveConnection(this.connectionTarget(event));
                this.cancelConnection();

                if (connection) {
                    this.commitConnection(connection.childId, connection.parentId);
                }

                return;
            }

            if (this.activeUnitConnection) {
                const source = this.nodes.find((node) => String(node.id) === String(this.activeUnitConnection.nodeId));
                const target = this.nodes.find((node) => String(node.id) === String(this.unitConnectionTargetId));
                this.cancelConnection();

                if (source && target && target.kind === 'unit' && target.unit_id !== null && this.movableUnitIds.includes(Number(target.unit_id))) {
                    this.unitMoveTarget = { user: source, unitId: target.unit_id };
                    this.unitConfirmation = true;
                }

                return;
            }

            if (this.visualDrag?.moved) {
                const draggedNode = this.nodes.find((item) => String(item.id) === String(this.visualDrag.id));
                const targetUnit = this.nodes.find((item) => String(item.id) === String(this.visualDrag.unitTargetId));
                this.suppressClick = true;
                window.setTimeout(() => { this.suppressClick = false; }, 0);

                if (draggedNode && targetUnit) {
                    this.unitMoveTarget = { user: draggedNode, unitId: targetUnit.unit_id };
                    this.unitConfirmation = true;
                }
            }

            this.visualDrag = null;
            this.endPan();
        },

        startPan(event) {
            if (event.target.closest('button, input, select, a')) return;

            this.panning = true;
            this.panStart = { x: event.clientX - this.offsetX, y: event.clientY - this.offsetY };
        },

        pan(event) {
            if (!this.panning) return;

            this.offsetX = event.clientX - this.panStart.x;
            this.offsetY = event.clientY - this.panStart.y;
            this.refreshConnectors();
        },

        endPan() {
            this.panning = false;
            this.panStart = null;
        },

        zoom(event) {
            const canvas = this.$refs.canvas;
            const rect = canvas.getBoundingClientRect();
            const oldScale = this.scale;
            const nextScale = Math.min(1.8, Math.max(0.35, oldScale - event.deltaY * 0.001));
            const x = event.clientX - rect.left;
            const y = event.clientY - rect.top;

            this.offsetX = x - ((x - this.offsetX) * nextScale) / oldScale;
            this.offsetY = y - ((y - this.offsetY) * nextScale) / oldScale;
            this.scale = nextScale;
        },

        resetView() {
            this.scale = 1;
            this.offsetX = 0;
            this.offsetY = 0;
        },

        fitView() {
            const canvas = this.$refs.canvas;
            const graph = this.$refs.graph;
            const bounds = this.visibleGraphBounds();

            if (!canvas || !graph || !bounds) {
                this.resetView();
                return;
            }

            const padding = 32;
            const availableWidth = Math.max(canvas.clientWidth - padding * 2, 1);
            const availableHeight = Math.max(canvas.clientHeight - padding * 2, 1);
            const contentWidth = Math.max(bounds.maxX - bounds.minX, 1);
            const contentHeight = Math.max(bounds.maxY - bounds.minY, 1);

            this.scale = Math.min(1.25, Math.max(0.35, Math.min(availableWidth / contentWidth, availableHeight / contentHeight)));
            this.offsetX = padding - graph.offsetLeft - bounds.minX * this.scale;
            this.offsetY = padding - graph.offsetTop - bounds.minY * this.scale;
            this.$nextTick(() => this.refreshConnectors());
        },

        visibleGraphBounds() {
            const graph = this.$refs.graph;
            if (!graph) return null;

            const graphRect = graph.getBoundingClientRect();
            const visibleNodes = [...graph.querySelectorAll('[data-org-node]')]
                .map((node) => node.getBoundingClientRect())
                .filter((rect) => rect.width > 0 && rect.height > 0)
                .map((rect) => ({
                    minX: (rect.left - graphRect.left) / this.scale,
                    minY: (rect.top - graphRect.top) / this.scale,
                    maxX: (rect.right - graphRect.left) / this.scale,
                    maxY: (rect.bottom - graphRect.top) / this.scale,
                }));

            if (visibleNodes.length === 0) return null;

            return visibleNodes.reduce((bounds, node) => ({
                minX: Math.min(bounds.minX, node.minX),
                minY: Math.min(bounds.minY, node.minY),
                maxX: Math.max(bounds.maxX, node.maxX),
                maxY: Math.max(bounds.maxY, node.maxY),
            }));
        },

        toggleFullscreen() {
            if (document.fullscreenElement) {
                document.exitFullscreen?.();
            } else {
                this.$refs.canvas.requestFullscreen?.();
            }
        },

        refreshConnectors() {
            const canvas = this.$refs.canvas;
            const graph = this.$refs.graph;
            if (!canvas || !graph) return;

            const graphRect = graph.getBoundingClientRect();
            this.connectors = this.nodes
                .filter((node) => {
                    const parentId = this.treeParent(node);
                    const parent = this.nodes.find((candidate) => String(candidate.id) === String(parentId));

                    return parentId && parent && this.matches(node.id) && this.matches(parentId);
                })
                .map((node) => {
                    const parentId = this.treeParent(node);
                    const parentNode = this.nodes.find((candidate) => String(candidate.id) === String(parentId));
                    const child = graph.querySelector(`[data-org-node="${node.id}"]`);
                    const parent = graph.querySelector(`[data-org-node="${parentId}"]`);
                    const childSocket = this.socketElement(node.id, 'input');
                    const parentSocket = this.socketElement(parentId, 'output');

                    if (!child || !parent || !child.getClientRects().length || !parent.getClientRects().length) return null;

                    const childRect = child.getBoundingClientRect();
                    const parentRect = parent.getBoundingClientRect();
                    const childSocketRect = childSocket?.getBoundingClientRect();
                    const parentSocketRect = parentSocket?.getBoundingClientRect();

                    return {
                        id: `${parentId}-${node.id}`,
                        x1: ((parentSocketRect?.left ?? parentRect.right) - graphRect.left + (parentSocketRect?.width ?? 0) / 2) / this.scale,
                        y1: ((parentSocketRect?.top ?? parentRect.bottom) - graphRect.top + (parentSocketRect?.height ?? 0) / 2) / this.scale,
                        x2: ((childSocketRect?.left ?? childRect.left) - graphRect.left + (childSocketRect?.width ?? 0) / 2) / this.scale,
                        y2: ((childSocketRect?.top ?? childRect.top) - graphRect.top + (childSocketRect?.height ?? 0) / 2) / this.scale,
                        orientation: parentSocketRect && childSocketRect ? 'horizontal' : 'vertical',
                        kind: parentNode?.kind === 'unit' ? 'unit' : 'hierarchy',
                    };
                })
                .filter(Boolean);
        },

        async commitConnection(childId, parentId) {
            const child = this.nodes.find((node) => String(node.id) === String(childId));

            if (!child) return;

            await this.commitParentChange(child, parentId);
        },

        async commitMove() {
            if (!this.selected) return;

            await this.commitParentChange(this.selected, this.newParentId || null);
        },

        async commitUnitMove() {
            if (!this.unitMoveTarget?.user || !this.unitMoveTarget?.unitId) return;

            const targetUnit = this.unitTargets.find((unit) => String(unit.unit_id) === String(this.unitMoveTarget.unitId));
            if (!targetUnit) return;

            this.saving = true;

            try {
                const response = await fetch(this.unitMoveUrl.replace('__USER__', this.unitMoveTarget.user.id), {
                    method: 'PATCH',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                    body: JSON.stringify({
                        organization_unit_id: targetUnit.unit_id,
                        expected_organization_unit_id: this.unitMoveTarget.user.unit_id?.replace?.('unit:', '') || null,
                    }),
                });

                if (response.status === 409) {
                    window.oasisToast?.('Struktur organisasi telah berubah. Muat ulang lalu coba lagi.', 'error');
                    return;
                }

                if (!response.ok) {
                    const payload = await response.json().catch(() => null);
                    window.oasisToast?.(payload?.message || 'Pemindahan unit tidak diizinkan.', 'error');
                    return;
                }

                window.oasisToast?.('Pengguna berhasil dipindahkan ke unit organisasi.', 'success');
                window.setTimeout(() => window.location.reload(), 250);
            } finally {
                this.saving = false;
                this.unitConfirmation = false;
                this.unitMoveTarget = null;
            }
        },

        openUnitMove() {
            if (!this.selected || this.selected.kind !== 'user') return;

            this.unitMoveTarget = { user: this.selected, unitId: null };
            this.unitConfirmation = true;
        },

        startZoneDrag(event, id) {
            const node = this.nodes.find((item) => String(item.id) === String(id));
            if (!node || node.kind !== 'user' || !this.canMove) return;

            event.dataTransfer.effectAllowed = 'move';
            event.dataTransfer.setData('text/plain', String(id));
            this.select(id);
        },

        dropZone(event, unitId) {
            event.preventDefault();
            const id = event.dataTransfer.getData('text/plain');
            const user = this.nodes.find((node) => String(node.id) === String(id) && node.kind === 'user');
            const unit = this.nodes.find((node) => String(node.id) === String(unitId) && node.kind === 'unit');
            if (!user || !unit || !this.movableUnitIds.includes(Number(unit.unit_id)) || String(user.unit_id) === String(unit.id)) return;

            this.unitMoveTarget = { user, unitId: unit.unit_id };
            this.unitConfirmation = true;
        },

        requestRemoveSelected() {
            if (!this.canMove || !this.selected || this.selected.kind !== 'user') return;
            if (['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement?.tagName)) return;

            this.context = null;
            this.removeConfirmation = true;
        },

        async commitRemove() {
            if (!this.selected || this.selected.kind !== 'user') return;

            this.saving = true;

            try {
                const response = await fetch(this.removeStructureUrl.replace('__USER__', this.selected.id), {
                    method: 'DELETE',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                    body: JSON.stringify({
                        expected_organization_unit_id: String(this.selected.unit_id || '').replace('unit:', '') || null,
                        expected_assignment_id: this.selected.assignment_id,
                        expected_version: this.selected.assignment_version,
                    }),
                });

                if (response.status === 409) {
                    window.oasisToast?.('Struktur organisasi telah berubah. Muat ulang lalu coba lagi.', 'error');
                    return;
                }

                if (!response.ok) {
                    const payload = await response.json().catch(() => null);
                    window.oasisToast?.(payload?.message || 'Pengguna tidak dapat dikeluarkan dari struktur.', 'error');
                    return;
                }

                window.oasisToast?.('Pengguna dikeluarkan dari struktur organisasi.', 'success');
                window.setTimeout(() => window.location.reload(), 250);
            } finally {
                this.saving = false;
                this.removeConfirmation = false;
            }
        },

        async commitParentChange(child, parentId) {
            if (!child || child.kind !== 'user' || String(parentId || '') === String(child.parent_user_id || '')) return;

            this.saving = true;

            try {
                const response = await fetch(this.moveUrl.replace('__USER__', child.id), {
                    method: 'PATCH',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                    body: JSON.stringify({
                        parent_user_id: parentId || null,
                        expected_assignment_id: child.assignment_id,
                        expected_version: child.assignment_version,
                    }),
                });

                if (response.status === 409) {
                    window.oasisToast?.('Struktur organisasi telah berubah. Muat ulang lalu coba lagi.', 'error');
                    return;
                }

                if (!response.ok) {
                    const payload = await response.json().catch(() => null);
                    const validationMessage = Object.values(payload?.errors || {}).flat()[0];
                    window.oasisToast?.(validationMessage || payload?.message || 'Perpindahan tidak diizinkan.', 'error');
                    return;
                }

                const payload = await response.json().catch(() => ({}));
                this.applyLocalParentChange(child, parentId, payload.assignment);
                window.oasisToast?.('Struktur organisasi berhasil diperbarui.', 'success');
            } finally {
                this.saving = false;
                this.confirmation = false;
            }
        },

        applyLocalParentChange(child, parentId, assignment = null) {
            const previousParent = this.nodes.find((node) => String(node.id) === String(this.treeParent(child)));
            const nextParent = this.nodes.find((node) => String(node.id) === String(parentId || child.unit_id));

            if (previousParent) previousParent.direct_reports = Math.max(0, previousParent.direct_reports - 1);
            if (nextParent) nextParent.direct_reports += 1;

            child.parent_id = parentId || null;
            child.parent_user_id = parentId || null;
            child.tree_parent_id = parentId || child.unit_id;
            child.assignment_id = assignment?.id || null;
            child.assignment_version = assignment?.lock_version || null;
            this.newParentId = child.parent_user_id ? String(child.parent_user_id) : '';
            this.$nextTick(() => this.refreshConnectors());
        },
    };
}

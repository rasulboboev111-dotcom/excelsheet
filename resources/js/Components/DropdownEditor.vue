<template>
    <div ref="wrapper" class="dde-wrap">
        <input ref="input"
               v-model="value"
               @keydown="onKeydown"
               @input="onInput"
               @focus="open = true"
               class="dde-input"
               autocomplete="off" />
        <button type="button" class="dde-btn" @mousedown.prevent="toggleOpen" tabindex="-1">
            <svg width="10" height="10" viewBox="0 0 10 10"><path d="M2 3.5L5 6.5L8 3.5" stroke="#374151" stroke-width="1.4" fill="none" stroke-linecap="round" stroke-linejoin="round"/></svg>
        </button>
        <Teleport to="body">
            <div v-if="open && filtered.length"
                 class="dde-list"
                 :style="{ top: listPos.top + 'px', left: listPos.left + 'px', width: listPos.width + 'px' }">
                <div v-for="(v, i) in filtered" :key="v"
                     :class="['dde-item', { active: i === highlight }]"
                     @mousedown.prevent="pick(v)"
                     @mouseenter="highlight = i">{{ v }}</div>
            </div>
        </Teleport>
    </div>
</template>

<script>
export default {
    name: 'DropdownEditor',
    data() {
        return {
            value: '',
            allValues: [],
            open: false,
            highlight: 0,
            listPos: { top: 0, left: 0, width: 160 },
            _docHandler: null,
            _scrollHandler: null,
            _resizeHandler: null,
        };
    },
    computed: {
        filtered() {
            const q = String(this.value || '').trim().toLowerCase();
            if (!q) return this.allValues;
            return this.allValues.filter(v => v.toLowerCase().includes(q));
        },
    },
    created() {
        const p = this.params || {};
        this.value = String(p.value ?? '');
        this.allValues = (p.values || []).map(v => String(v));
        if (p.charPress) this.value = p.charPress;
    },
    mounted() {
        this.$nextTick(() => {
            const el = this.$refs.input;
            if (el) {
                el.focus();
                if (this.params?.charPress) {
                    this.open = true;
                    this.updatePos();
                } else {
                    el.select();
                }
            }
        });
        this._docHandler = (e) => {
            if (this.$refs.wrapper && !this.$refs.wrapper.contains(e.target)) this.open = false;
        };
        this._scrollHandler = () => { if (this.open) this.updatePos(); };
        this._resizeHandler = () => { if (this.open) this.updatePos(); };
        document.addEventListener('mousedown', this._docHandler);
        window.addEventListener('scroll', this._scrollHandler, true);
        window.addEventListener('resize', this._resizeHandler);
    },
    beforeUnmount() {
        if (this._docHandler) document.removeEventListener('mousedown', this._docHandler);
        if (this._scrollHandler) window.removeEventListener('scroll', this._scrollHandler, true);
        if (this._resizeHandler) window.removeEventListener('resize', this._resizeHandler);
    },
    watch: {
        open(v) { if (v) this.$nextTick(() => this.updatePos()); },
        filtered() { if (this.open) this.$nextTick(() => this.updatePos()); },
    },
    methods: {
        // === API AG Grid ===
        getValue() { return this.value; },
        isPopup() { return false; },
        isCancelBeforeStart() { return false; },
        isCancelAfterEnd() { return false; },

        // === UI ===
        updatePos() {
            const w = this.$refs.wrapper;
            if (!w) return;
            const r = w.getBoundingClientRect();
            const listH = Math.min(180, this.filtered.length * 24 + 4);
            const spaceBelow = window.innerHeight - r.bottom;
            const top = (spaceBelow < listH + 8 && r.top > listH + 8)
                ? Math.round(r.top - listH - 1)   // открыть вверх
                : Math.round(r.bottom + 1);        // открыть вниз
            this.listPos = {
                top,
                left: Math.round(r.left),
                width: Math.max(120, Math.round(r.width)),
            };
        },
        toggleOpen() { this.open = !this.open; if (this.open) this.highlight = 0; },
        pick(v) {
            this.value = v;
            this.open = false;
            this.$nextTick(() => this.$refs.input?.focus());
        },
        onInput() { this.open = true; this.highlight = 0; },
        onKeydown(e) {
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                if (!this.open) { this.open = true; this.highlight = 0; return; }
                this.highlight = Math.min(this.filtered.length - 1, this.highlight + 1);
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                if (this.open) this.highlight = Math.max(0, this.highlight - 1);
            } else if (e.key === 'Enter') {
                if (this.open && this.filtered[this.highlight]) {
                    e.preventDefault();
                    this.pick(this.filtered[this.highlight]);
                }
            } else if (e.key === 'Escape') {
                if (this.open) { e.stopPropagation(); this.open = false; }
            }
        },
    },
};
</script>

<style scoped>
.dde-wrap {
    position: relative;
    width: 100%;
    height: 100%;
    display: flex;
    align-items: stretch;
    background: #fff;
    box-sizing: border-box;
    overflow: hidden;
}
.dde-input {
    flex: 1;
    min-width: 0;
    border: none;
    outline: none;
    padding: 0 4px;
    font-family: inherit;
    font-size: inherit;
    color: inherit;
    background: transparent;
}
.dde-btn {
    width: 18px;
    flex: 0 0 18px;
    border: none;
    background: #f3f4f6;
    cursor: pointer;
    padding: 0;
    border-left: 1px solid #d1d5db;
    display: flex;
    align-items: center;
    justify-content: center;
}
.dde-btn:hover { background: #e5e7eb; }
</style>

<style>
/* Глобальные стили — список рендерится через Teleport в body, scoped не дойдёт */
.dde-list {
    position: fixed;
    background: #fff;
    border: 1px solid #c8c8c8;
    border-radius: 3px;
    box-shadow: 0 6px 20px rgba(0,0,0,.18);
    max-height: 180px;
    overflow-y: auto;
    z-index: 100000;
    font-family: 'Calibri','Segoe UI',sans-serif;
}
.dde-list .dde-item {
    padding: 5px 10px;
    font-size: 12px;
    cursor: pointer;
    color: #1f2937;
    line-height: 1.35;
    white-space: nowrap;
    text-overflow: ellipsis;
    overflow: hidden;
}
.dde-list .dde-item.active,
.dde-list .dde-item:hover {
    background: #2563eb;
    color: #fff;
}
</style>

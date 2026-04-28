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
            <div v-if="open && (filtered.length || isCustomValue)"
                 ref="listEl"
                 class="dde-list"
                 :style="{ top: listPos.top + 'px', left: listPos.left + 'px', width: listPos.width + 'px' }">
                <div v-if="filtered.length" class="dde-counter">
                    {{ filtered.length === allValues.length
                        ? allValues.length + ' значений'
                        : filtered.length + ' из ' + allValues.length }}
                </div>
                <div v-for="(v, i) in filtered" :key="v"
                     :data-idx="i"
                     :class="['dde-item', { active: i === highlight }]"
                     @mousedown.prevent="pick(v)"
                     @mouseenter="highlight = i"
                     v-html="highlightMatch(v)"></div>
                <div v-if="isCustomValue"
                     :class="['dde-item dde-new', { active: highlight === filtered.length }]"
                     @mousedown.prevent="acceptCustom()"
                     @mouseenter="highlight = filtered.length">
                    <span class="dde-new-icon">＋</span>
                    Добавить «<b>{{ value }}</b>» как новое значение
                </div>
            </div>
        </Teleport>
    </div>
</template>

<script>
const escapeRegExp = (s) => s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
const escapeHtml = (s) => String(s)
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

export default {
    name: 'DropdownEditor',
    // AG Grid Vue 3 v32+ передаёт params через prop. Без явной декларации
    // в Options API доступ через this.params не гарантирован.
    props: ['params'],
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
        // true, если введённого значения нет в списке точно — тогда показываем «+ Добавить»
        isCustomValue() {
            const v = String(this.value || '').trim();
            if (!v) return false;
            return !this.allValues.some(av => av.toLowerCase() === v.toLowerCase());
        },
        // суммарное число пунктов списка (включая «+ Добавить» если применимо)
        totalItems() {
            return this.filtered.length + (this.isCustomValue ? 1 : 0);
        },
    },
    created() {
        const p = this.params || {};
        this.value = String(p.value ?? '');
        this.allValues = (p.values || []).map(v => String(v));
        // charPress (v32-) или eventKey (v33+) — символ, которым пользователь начал ввод.
        const startKey = p.charPress || p.eventKey;
        if (startKey && typeof startKey === 'string' && startKey.length === 1) {
            this.value = startKey;
        }
    },
    mounted() {
        this.$nextTick(() => {
            const el = this.$refs.input;
            if (el) {
                el.focus();
                const startKey = this.params?.charPress || this.params?.eventKey;
                if (startKey && typeof startKey === 'string' && startKey.length === 1) {
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
        // Закрываем поповер заранее — Teleport-контент исчезнет вместе с v-if прежде,
        // чем Vue размонтирует Teleport-якорь.
        this.open = false;
        if (this._docHandler) document.removeEventListener('mousedown', this._docHandler);
        if (this._scrollHandler) window.removeEventListener('scroll', this._scrollHandler, true);
        if (this._resizeHandler) window.removeEventListener('resize', this._resizeHandler);
        this._docHandler = null;
        this._scrollHandler = null;
        this._resizeHandler = null;
    },
    watch: {
        open(v) { if (v) this.$nextTick(() => this.updatePos()); },
        filtered() { if (this.open) this.$nextTick(() => this.updatePos()); },
        highlight() { this.$nextTick(() => this.scrollHighlightIntoView()); },
    },
    methods: {
        // === API AG Grid ===
        getValue() { return this.value; },
        isPopup() { return false; },
        isCancelBeforeStart() { return false; },
        isCancelAfterEnd() { return false; },

        // === UI ===
        highlightMatch(text) {
            const safe = escapeHtml(text);
            const q = String(this.value || '').trim();
            if (!q) return safe;
            const re = new RegExp('(' + escapeRegExp(escapeHtml(q)) + ')', 'gi');
            return safe.replace(re, '<b class="dde-hl">$1</b>');
        },
        updatePos() {
            const w = this.$refs.wrapper;
            if (!w) return;
            const r = w.getBoundingClientRect();
            const itemsH = Math.min(this.totalItems * 26 + (this.filtered.length ? 22 : 0) + 4, 260);
            const listH = Math.max(60, itemsH);
            const spaceBelow = window.innerHeight - r.bottom;
            const top = (spaceBelow < listH + 8 && r.top > listH + 8)
                ? Math.round(r.top - listH - 1)
                : Math.round(r.bottom + 1);
            this.listPos = {
                top,
                left: Math.round(r.left),
                width: Math.max(160, Math.round(r.width)),
            };
        },
        scrollHighlightIntoView() {
            const list = this.$refs.listEl;
            if (!list) return;
            const items = list.querySelectorAll('.dde-item');
            const el = items[this.highlight];
            if (!el) return;
            const lr = list.getBoundingClientRect();
            const er = el.getBoundingClientRect();
            if (er.top < lr.top) list.scrollTop -= (lr.top - er.top);
            else if (er.bottom > lr.bottom) list.scrollTop += (er.bottom - lr.bottom);
        },
        toggleOpen() { this.open = !this.open; if (this.open) this.highlight = 0; },
        pick(v) {
            this.value = v;
            this.open = false;
            this.$nextTick(() => this.$refs.input?.focus());
        },
        acceptCustom() {
            // value уже = что пользователь ввёл; просто закрываем список
            this.open = false;
            this.$nextTick(() => this.$refs.input?.focus());
        },
        onInput() {
            this.open = true;
            this.highlight = 0;
        },
        onKeydown(e) {
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                if (!this.open) { this.open = true; this.highlight = 0; return; }
                this.highlight = Math.min(this.totalItems - 1, this.highlight + 1);
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                if (this.open) this.highlight = Math.max(0, this.highlight - 1);
            } else if (e.key === 'Enter') {
                if (this.open) {
                    e.preventDefault();
                    if (this.highlight < this.filtered.length) {
                        this.pick(this.filtered[this.highlight]);
                    } else if (this.isCustomValue) {
                        this.acceptCustom();
                    }
                }
                // если open=false — пусть AG Grid сам обработает Enter (commit)
            } else if (e.key === 'Escape') {
                if (this.open) { e.stopPropagation(); this.open = false; }
            } else if (e.key === 'Home') {
                if (this.open) { e.preventDefault(); this.highlight = 0; }
            } else if (e.key === 'End') {
                if (this.open) { e.preventDefault(); this.highlight = this.totalItems - 1; }
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
/* Глобальные — список рендерится через Teleport в body */
.dde-list {
    position: fixed;
    background: #fff;
    border: 1px solid #c8c8c8;
    border-radius: 4px;
    box-shadow: 0 6px 20px rgba(0,0,0,.18);
    max-height: 240px;
    overflow-y: auto;
    z-index: 100000;
    font-family: 'Calibri','Segoe UI',sans-serif;
    padding: 2px 0;
}
.dde-list .dde-counter {
    font-size: 10px;
    color: #6b7280;
    padding: 4px 10px 6px;
    border-bottom: 1px solid #f3f4f6;
    background: #fafafa;
    text-transform: uppercase;
    letter-spacing: .3px;
    position: sticky;
    top: 0;
    z-index: 1;
}
.dde-list .dde-item {
    padding: 5px 10px;
    font-size: 12px;
    cursor: pointer;
    color: #1f2937;
    line-height: 1.4;
    white-space: nowrap;
    text-overflow: ellipsis;
    overflow: hidden;
}
.dde-list .dde-item.active,
.dde-list .dde-item:hover {
    background: #2563eb;
    color: #fff;
}
.dde-list .dde-item .dde-hl {
    background: #fef08a;
    color: #1f2937;
    font-weight: 600;
    border-radius: 1px;
    padding: 0 1px;
}
.dde-list .dde-item.active .dde-hl {
    background: #fbbf24;
    color: #fff;
}
.dde-list .dde-item.dde-new {
    border-top: 1px solid #f3f4f6;
    font-style: italic;
    color: #2563eb;
    background: #f9fafb;
}
.dde-list .dde-item.dde-new.active {
    background: #2563eb;
    color: #fff;
}
.dde-list .dde-item.dde-new .dde-new-icon {
    display: inline-block;
    width: 14px;
    height: 14px;
    line-height: 14px;
    text-align: center;
    background: #dbeafe;
    color: #2563eb;
    border-radius: 50%;
    font-weight: bold;
    margin-right: 4px;
    font-size: 10px;
}
.dde-list .dde-item.dde-new.active .dde-new-icon {
    background: #fff;
    color: #2563eb;
}
.dde-list .dde-item.dde-new b {
    font-style: normal;
}
</style>

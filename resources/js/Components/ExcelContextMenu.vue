<script setup>
import { onMounted, onUnmounted, ref, nextTick } from 'vue';

const props = defineProps({
    x: Number,
    y: Number,
    cellData: Object,
    canEdit: { type: Boolean, default: true },
    hasClipboard: { type: Boolean, default: false },
});

const emit = defineEmits(['close', 'action']);

const closeMenu = () => emit('close');
const borderPickerOpen = ref(false);

// Реальные координаты после viewport-coercion. Если меню выходит за нижний/правый
// край экрана, открываем его «вверх» / «влево» от точки клика, чтобы все пункты
// (включая «Σ Сумма выделения» в низу) точно были видны.
const menuRoot = ref(null);
const adjustedTop = ref(0);
const adjustedLeft = ref(0);

const adjustPosition = () => {
    const el = menuRoot.value;
    if (!el) return;
    const rect = el.getBoundingClientRect();
    const W = window.innerWidth;
    const H = window.innerHeight;
    const margin = 4;
    let top = props.y;
    let left = props.x;
    if (top + rect.height > H - margin) {
        top = Math.max(margin, props.y - rect.height);
    }
    if (left + rect.width > W - margin) {
        left = Math.max(margin, props.x - rect.width);
    }
    adjustedTop.value = top;
    adjustedLeft.value = left;
};

// Те же пресеты, что в Ribbon — порядок и type синхронизированы
const borderOptions = [
    { type: 'all',     label: 'Все границы' },
    { type: 'outside', label: 'Внешние' },
    { type: 'thickBox',label: 'Толстые внешние' },
    { type: 'top',     label: 'Верхняя' },
    { type: 'bottom',  label: 'Нижняя' },
    { type: 'left',    label: 'Левая' },
    { type: 'right',   label: 'Правая' },
    { type: 'none',    label: 'Без границ' },
];

const borderIcon = (type) => {
    const f = '#fff', g = '#bbb', b = '#222';
    const box = `<rect x="3" y="3" width="14" height="14" fill="${f}" stroke="${g}" stroke-width="1"/>`;
    const lines = {
        bottom:   `<line x1="3" y1="17" x2="17" y2="17" stroke="${b}" stroke-width="2"/>`,
        top:      `<line x1="3" y1="3"  x2="17" y2="3"  stroke="${b}" stroke-width="2"/>`,
        left:     `<line x1="3" y1="3"  x2="3"  y2="17" stroke="${b}" stroke-width="2"/>`,
        right:    `<line x1="17" y1="3" x2="17" y2="17" stroke="${b}" stroke-width="2"/>`,
        none:     ``,
        all:      `<rect x="3" y="3" width="14" height="14" fill="none" stroke="${b}" stroke-width="1.2"/><line x1="3" y1="10" x2="17" y2="10" stroke="${b}" stroke-width="0.8"/><line x1="10" y1="3" x2="10" y2="17" stroke="${b}" stroke-width="0.8"/>`,
        outside:  `<rect x="3" y="3" width="14" height="14" fill="none" stroke="${b}" stroke-width="1.5"/>`,
        thickBox: `<rect x="3" y="3" width="14" height="14" fill="none" stroke="${b}" stroke-width="2.5"/>`,
    };
    return `<svg width="20" height="20" viewBox="0 0 20 20">${box}${lines[type] || ''}</svg>`;
};

onMounted(() => {
    // Сначала рендерим в исходных x/y, потом измеряем и при нужде «отбиваем»
    // от краёв viewport. nextTick — чтобы DOM уже был с реальной высотой
    // (иначе getBoundingClientRect вернёт 0).
    adjustedTop.value = props.y;
    adjustedLeft.value = props.x;
    nextTick(adjustPosition);
    window.addEventListener('click', closeMenu);
    window.addEventListener('contextmenu', closeMenu);
    window.addEventListener('resize', adjustPosition);
});

onUnmounted(() => {
    window.removeEventListener('click', closeMenu);
    window.removeEventListener('contextmenu', closeMenu);
    window.removeEventListener('resize', adjustPosition);
});

const handleAction = (action, value) => {
    emit('action', action, value);
    closeMenu();
};

const toggleBorderPicker = (e) => {
    e.stopPropagation();
    borderPickerOpen.value = !borderPickerOpen.value;
};

const pickBorder = (type) => {
    handleAction('border', type);
};
</script>

<template>
    <!-- Добавляем .prevent на contextmenu, чтобы браузерное меню не вылезало внутри нашего -->
    <div ref="menuRoot" class="excel-context-menu" :style="{ top: adjustedTop + 'px', left: adjustedLeft + 'px' }" @click.stop @contextmenu.prevent>
        <!-- Top Formatting Bar (Cut/B/I/Color/Fill — только если можно редактировать) -->
        <div class="formatting-bar" v-if="canEdit || hasClipboard">
            <button v-if="canEdit" class="btn-cut" title="Вырезать" @click="handleAction('cut')">
                <svg viewBox="0 0 24 24" width="16" height="16"><path fill="#742774" d="M19,3L13,9L15,11L21,5V3M12,12.5A0.5,0.5 0 0,1 11.5,12A0.5,0.5 0 0,1 12,11.5A0.5,0.5 0 0,1 12.5,12A0.5,0.5 0 0,1 12,12.5M6,3L11,8L9,10L3,4V3M3,20V19L9,13L11,15L5,21H3M19,21H21V20L15,14L13,16L19,22V21Z"/></svg>
            </button>
            <button class="btn-copy" title="Копировать" @click="handleAction('copy')">
                <svg viewBox="0 0 24 24" width="16" height="16"><path fill="#D83B01" d="M19,21H8V7H19M19,5H8A2,2 0 0,0 6,7V21A2,2 0 0,0 8,23H19A2,2 0 0,0 21,21V7A2,2 0 0,0 19,5M16,1H4A2,2 0 0,0 2,3V17H4V3H16V1Z"/></svg>
            </button>
            <button v-if="canEdit && hasClipboard" class="btn-paste" title="Вставить" @click="handleAction('paste')">
                <svg viewBox="0 0 24 24" width="16" height="16"><path fill="#0078D4" d="M19,20H5V4H7V7H17V4H19M12,2A1,1 0 0,1 13,3A1,1 0 0,1 12,4A1,1 0 0,1 11,3A1,1 0 0,1 12,2M19,2H14.82C14.4,0.84 13.3,0 12,0C10.7,0 9.6,0.84 9.18,2H5A2,2 0 0,0 3,4V20A2,2 0 0,0 5,22H19A2,2 0 0,0 21,20V4A2,2 0 0,0 19,2Z"/></svg>
            </button>
            <template v-if="canEdit">
                <div class="divider-v"></div>
                <button class="btn-bold" style="font-weight: bold;" @click="handleAction('bold')">B</button>
                <button class="btn-italic" style="font-style: italic;" @click="handleAction('italic')">I</button>
                <button class="btn-border" title="Границы" @click="toggleBorderPicker">
                    <svg width="16" height="16" viewBox="0 0 20 20">
                        <rect x="3" y="3" width="14" height="14" fill="none" stroke="#222" stroke-width="1.2"/>
                        <line x1="3" y1="10" x2="17" y2="10" stroke="#222" stroke-width="0.8"/>
                        <line x1="10" y1="3" x2="10" y2="17" stroke="#222" stroke-width="0.8"/>
                    </svg>
                    <span class="caret">▾</span>
                </button>
            </template>
        </div>

        <!-- Поповер выбора границы -->
        <div v-if="borderPickerOpen && canEdit" class="border-popover" @click.stop>
            <div class="popover-title">Границы</div>
            <div class="border-grid">
                <button v-for="opt in borderOptions" :key="opt.type" class="border-cell"
                        :title="opt.label" @click="pickBorder(opt.type)">
                    <span v-html="borderIcon(opt.type)"></span>
                    <span class="border-cell-label">{{ opt.label }}</span>
                </button>
            </div>
        </div>

        <div class="menu-list">
            <div v-if="canEdit" class="menu-item" @click="handleAction('cut')">
                <span class="icon"><svg viewBox="0 0 24 24" width="16" height="16"><path fill="#742774" d="M19,3L13,9L15,11L21,5V3M12,12.5A0.5,0.5 0 0,1 11.5,12A0.5,0.5 0 0,1 12,11.5A0.5,0.5 0 0,1 12.5,12A0.5,0.5 0 0,1 12,12.5M6,3L11,8L9,10L3,4V3M3,20V19L9,13L11,15L5,21H3M19,21H21V20L15,14L13,16L19,22V21Z"/></svg></span>
                Вырезать <span class="shortcut">Ctrl+X</span>
            </div>
            <div class="menu-item" @click="handleAction('copy')">
                <span class="icon"><svg viewBox="0 0 24 24" width="16" height="16"><path fill="#D83B01" d="M19,21H8V7H19M19,5H8A2,2 0 0,0 6,7V21A2,2 0 0,0 8,23H19A2,2 0 0,0 21,21V7A2,2 0 0,0 19,5M16,1H4A2,2 0 0,0 2,3V17H4V3H16V1Z"/></svg></span>
                Копировать <span class="shortcut">Ctrl+C</span>
            </div>
            <div v-if="canEdit && hasClipboard" class="menu-item" @click="handleAction('paste')">
                <span class="icon"></span> Вставить <span class="shortcut">Ctrl+V</span>
            </div>
            <div v-if="canEdit && hasClipboard" class="menu-item" @click="handleAction('paste-special')">
                <span class="icon"></span> Специальная вставка...
            </div>

            <div v-if="canEdit" class="divider"></div>

            <div v-if="canEdit" class="menu-item" @click="handleAction('insert')">
                <span class="icon blue">➕</span> Вставить строку
            </div>
            <div v-if="canEdit" class="menu-item" @click="handleAction('delete')">
                <span class="icon red">❌</span> Удалить...
            </div>
            <div v-if="canEdit" class="menu-item" @click="handleAction('clear')">
                <span class="icon orange">🧹</span> Очистить содержимое
            </div>

            <div v-if="canEdit" class="divider"></div>

            <div v-if="canEdit" class="menu-item" @click="handleAction('hyperlink')">
                <span class="icon blue">🔗</span> Гиперссылка...
            </div>

            <div class="divider"></div>

            <div class="menu-item" @click="handleAction('sum-selection')">
                <span class="icon" style="color:#107c10; font-weight:bold;">Σ</span> Сумма выделения
            </div>
        </div>
    </div>
</template>

<style scoped>
.excel-context-menu {
    position: fixed;
    background: white;
    border: 1px solid #c8c8c8;
    box-shadow: 0 2px 10px rgba(0,0,0,0.2);
    z-index: 10000;
    min-width: 220px;
    font-family: 'Segoe UI', Tahoma, sans-serif;
    font-size: 13px;
    color: #323130;
    border-radius: 2px;
    padding: 1px 0;
}

.formatting-bar {
    display: flex;
    padding: 2px;
    background: #ffffff;
    border-bottom: 1px solid #edebe9;
    gap: 1px;
    align-items: center;
}

.formatting-bar button {
    background: transparent;
    border: 1px solid transparent;
    padding: 6px;
    cursor: pointer;
    border-radius: 2px;
    display: flex;
    flex-direction: column;
    align-items: center;
    position: relative;
    min-width: 32px;
}

.formatting-bar button:hover {
    background: #f3f2f1;
}

.btn-border {
    flex-direction: row !important;
    gap: 2px;
    padding: 6px 6px 6px 8px !important;
}
.btn-border .caret {
    font-size: 9px;
    color: #605e5c;
    line-height: 1;
}

.border-popover {
    position: absolute;
    top: 42px;
    left: 8px;
    background: #fff;
    border: 1px solid #c8c8c8;
    border-radius: 4px;
    box-shadow: 0 4px 14px rgba(0,0,0,.15);
    padding: 8px;
    z-index: 10001;
    min-width: 220px;
}
.popover-title {
    font-size: 11px;
    color: #605e5c;
    font-weight: 600;
    margin-bottom: 6px;
    padding-left: 4px;
}
.border-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 2px;
}
.border-cell {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 6px 8px;
    background: transparent;
    border: 1px solid transparent;
    border-radius: 3px;
    cursor: pointer;
    font-size: 12px;
    text-align: left;
    color: #323130;
}
.border-cell:hover {
    background: #f3f2f1;
    border-color: #e1e1e1;
}
.border-cell-label {
    flex: 1;
    white-space: nowrap;
}

.divider-v {
    width: 1px;
    height: 20px;
    background: #edebe9;
    margin: 0 2px;
}

.menu-list {
    padding: 2px 0;
}

.menu-item {
    padding: 5px 12px 5px 36px;
    position: relative;
    cursor: pointer;
    display: flex;
    justify-content: space-between;
    line-height: 1.5;
}

.menu-item:hover {
    background: #f3f2f1;
}

.menu-item.disabled {
    color: #a19f9d;
    cursor: default;
}

.menu-item .icon {
    position: absolute;
    left: 10px;
    display: flex;
    align-items: center;
    height: 100%;
    top: 0;
}

.icon.red { color: #d83b01; }
.icon.blue { color: #0078d4; }
.icon.orange { color: #d83b01; }
.icon.gray { color: #605e5c; }

.menu-item .shortcut {
    color: #605e5c;
    font-size: 11px;
    margin-left: 20px;
}

.divider {
    height: 1px;
    background: #edebe9;
    margin: 4px 0;
}
</style>

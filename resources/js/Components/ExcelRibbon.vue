<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';

const props = defineProps({
    activeCell: Object
});

const emit = defineEmits(['action']);

const handleAction = (type, value = null) => {
    emit('action', { type, value });
};

const fonts = ['Arial', 'Calibri', 'Times New Roman', 'Segoe UI', 'Roboto'];

const themeColors = [
    ['#FFFFFF', '#000000', '#E7E6E6', '#44546A', '#5B9BD5', '#ED7D31', '#A5A5A5', '#FFC000', '#4472C4', '#70AD47'],
    ['#F2F2F2', '#7F7F7F', '#D0CECE', '#D6DCE4', '#DDEBF7', '#FCE4D6', '#EDEDED', '#FFF2CC', '#D9E1F2', '#E2EFDA'],
    ['#D8D8D8', '#595959', '#AEAAAA', '#ADB9CA', '#BDD7EE', '#F8CBAD', '#DBDBDB', '#FFE699', '#B4C6E7', '#C6E0B4'],
    ['#BFBFBF', '#3F3F3F', '#757171', '#8497B0', '#9BC2E6', '#F4B084', '#C9C9C9', '#FFD966', '#8EA9DB', '#A9D08E'],
    ['#A5A5A5', '#262626', '#3A3838', '#333F4F', '#2E75B6', '#C55A11', '#7B7B7B', '#BF8F00', '#2F5597', '#548235'],
    ['#7F7F7F', '#0C0C0C', '#161616', '#222A35', '#1F4E78', '#833C0C', '#525252', '#7F6000', '#1F3864', '#375623']
];
const standardColors = ['#C00000', '#FF0000', '#FFC000', '#FFFF00', '#92D050', '#00B050', '#00B0F0', '#0070C0', '#002060', '#7030A0'];

const activePicker = ref(null);
const currentBgColor = ref('#ffff00');
const currentTextColor = ref('#ff0000');
const currentBorderType = ref('all');
const dropdownPos = ref({ top: 0, left: 0 });

const borderOptions = [
    { type: 'bottom',          label: 'Нижняя граница' },
    { type: 'top',             label: 'Верхняя граница' },
    { type: 'left',            label: 'Левая граница' },
    { type: 'right',           label: 'Правая граница' },
    { type: 'none',            label: 'Нет границы' },
    { type: 'all',             label: 'Все границы' },
    { type: 'outside',         label: 'Внешние границы' },
    { type: 'thickBox',        label: 'Толстые внешние границы' },
    { type: 'bottomDouble',    label: 'Двойная нижняя граница' },
    { type: 'bottomThick',     label: 'Толстая нижняя граница' },
    { type: 'topBottom',       label: 'Верхняя и нижняя границы' },
    { type: 'topThickBottom',  label: 'Верхняя и толстая нижняя' },
    { type: 'topDoubleBottom', label: 'Верхняя и двойная нижняя' }
];

const borderIcon = (type) => {
    const f = '#fff', g = '#bbb', b = '#000';
    const box = `<rect x="3" y="3" width="14" height="14" fill="${f}" stroke="${g}" stroke-width="1"/>`;
    const grid = `<line x1="3" y1="10" x2="17" y2="10" stroke="${g}" stroke-width="0.5"/><line x1="10" y1="3" x2="10" y2="17" stroke="${g}" stroke-width="0.5"/>`;
    const lines = {
        bottom:          `<line x1="3" y1="17" x2="17" y2="17" stroke="${b}" stroke-width="2"/>`,
        top:             `<line x1="3" y1="3"  x2="17" y2="3"  stroke="${b}" stroke-width="2"/>`,
        left:            `<line x1="3" y1="3"  x2="3"  y2="17" stroke="${b}" stroke-width="2"/>`,
        right:           `<line x1="17" y1="3" x2="17" y2="17" stroke="${b}" stroke-width="2"/>`,
        none:            ``,
        all:             `<rect x="3" y="3" width="14" height="14" fill="none" stroke="${b}" stroke-width="1.5"/><line x1="3" y1="10" x2="17" y2="10" stroke="${b}" stroke-width="1"/><line x1="10" y1="3" x2="10" y2="17" stroke="${b}" stroke-width="1"/>`,
        outside:         `<rect x="3" y="3" width="14" height="14" fill="none" stroke="${b}" stroke-width="1.5"/>`,
        thickBox:        `<rect x="3" y="3" width="14" height="14" fill="none" stroke="${b}" stroke-width="2.5"/>`,
        bottomDouble:    `<line x1="3" y1="15" x2="17" y2="15" stroke="${b}" stroke-width="1"/><line x1="3" y1="17" x2="17" y2="17" stroke="${b}" stroke-width="1"/>`,
        bottomThick:     `<line x1="3" y1="17" x2="17" y2="17" stroke="${b}" stroke-width="3"/>`,
        topBottom:       `<line x1="3" y1="3" x2="17" y2="3" stroke="${b}" stroke-width="1.5"/><line x1="3" y1="17" x2="17" y2="17" stroke="${b}" stroke-width="1.5"/>`,
        topThickBottom:  `<line x1="3" y1="3" x2="17" y2="3" stroke="${b}" stroke-width="1"/><line x1="3" y1="17" x2="17" y2="17" stroke="${b}" stroke-width="3"/>`,
        topDoubleBottom: `<line x1="3" y1="3" x2="17" y2="3" stroke="${b}" stroke-width="1"/><line x1="3" y1="15" x2="17" y2="15" stroke="${b}" stroke-width="1"/><line x1="3" y1="17" x2="17" y2="17" stroke="${b}" stroke-width="1"/>`
    };
    return `<svg width="20" height="20" viewBox="0 0 20 20">${box}${grid}${lines[type] || ''}</svg>`;
};

const selectBorder = (type) => {
    currentBorderType.value = type;
    handleAction('border', type);
    activePicker.value = null;
};

const activeFontSize = computed(() => {
    const fs = props.activeCell?.style?.fontSize;
    const n = parseInt((fs || '11px').replace('px', ''));
    return isNaN(n) ? 11 : n;
});
const activeFontFamily = computed(() => props.activeCell?.style?.fontFamily || 'Calibri');

const bgNativePicker = ref(null);
const textNativePicker = ref(null);

const togglePicker = (type, event) => {
    if (activePicker.value === type) { activePicker.value = null; return; }
    if (event && event.currentTarget) {
        const rect = event.currentTarget.getBoundingClientRect();
        const dropdownWidth = type === 'cellStyles' ? 760 : (type === 'border' ? 240 : 200);
        let left = rect.left;
        if (left + dropdownWidth > window.innerWidth - 10) {
            left = Math.max(10, window.innerWidth - dropdownWidth - 10);
        }
        const top = rect.bottom + 2;
        dropdownPos.value = { top, left };
    }
    activePicker.value = type;
};

const triggerNativePicker = (type) => {
    if (type === 'bg' && bgNativePicker.value) bgNativePicker.value.click();
    if (type === 'text' && textNativePicker.value) textNativePicker.value.click();
};

const selectColor = (color) => {
    if (activePicker.value === 'bgColor') {
        currentBgColor.value = color || 'transparent';
        handleAction('bgColor', color);
    } else if (activePicker.value === 'color') {
        currentTextColor.value = color || '#000000';
        handleAction('color', color);
    }
    activePicker.value = null;
};

const applyCellStyle = (styleName) => {
    handleAction('applyCellStyle', styleName);
    activePicker.value = null;
};

const closePickers = (e) => {
    if (!e.target.closest('.color-picker-container')) {
        activePicker.value = null;
    }
};

const closeOnScroll = () => { activePicker.value = null; };
onMounted(() => {
    document.addEventListener('click', closePickers);
    window.addEventListener('scroll', closeOnScroll, true);
    window.addEventListener('resize', closeOnScroll);
});
onUnmounted(() => {
    document.removeEventListener('click', closePickers);
    window.removeEventListener('scroll', closeOnScroll, true);
    window.removeEventListener('resize', closeOnScroll);
});

// SVG Icons as components or strings
const icons = {
    paste: `<svg width="32" height="32" viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg"><rect x="8" y="6" width="16" height="20" rx="1" fill="#F3F2F1" stroke="#333" stroke-width="1.5"/><rect x="11" y="4" width="10" height="4" rx="1" fill="#F3F2F1" stroke="#333" stroke-width="1.5"/><path d="M12 12H20M12 16H20M12 20H17" stroke="#333" stroke-width="1.5" stroke-linecap="round"/></svg>`,
    cut: `<svg width="16" height="16" viewBox="0 0 16 16" fill="none"><path d="M4 4L12 12M12 4L4 12" stroke="#333" stroke-width="1.5"/></svg>`,
    copy: `<svg width="16" height="16" viewBox="0 0 16 16" fill="none"><rect x="3" y="3" width="7" height="9" stroke="#333" stroke-width="1.2"/><rect x="6" y="5" width="7" height="9" fill="#F3F2F1" stroke="#333" stroke-width="1.2"/></svg>`,
    brush: `<svg width="16" height="16" viewBox="0 0 16 16" fill="none"><path d="M3 13L10 6L13 3M3 13L2 14M3 13L4 12" stroke="#8B4513" stroke-width="2"/><rect x="9" y="3" width="4" height="4" fill="#FFD700"/></svg>`,
    border: `<svg width="18" height="18" viewBox="0 0 18 18" fill="none"><rect x="3" y="3" width="12" height="12" stroke="#333" stroke-width="1.5"/><path d="M3 9H15M9 3V15" stroke="#333" stroke-width="1"/></svg>`,
    bucket: `<svg width="18" height="18" viewBox="0 0 18 18" fill="none"><path d="M4 10L9 5L14 10L9 15L4 10Z" fill="#ffff00" stroke="#333"/><path d="M14 10L16 12L15 13L13 11" stroke="#333"/></svg>`,
    sum: `<svg width="16" height="16" viewBox="0 0 16 16" fill="none"><path d="M4 4H12L7 8L12 12H4" stroke="#333" stroke-width="2" stroke-linejoin="round"/></svg>`,
    clear: `<svg width="16" height="16" viewBox="0 0 16 16"><path d="M3 6h10l-1 8H4L3 6z" fill="#fde7e9" stroke="#a4262c"/><path d="M2 4h12M6 4v2M10 4v2" stroke="#a4262c" stroke-width="1.2"/></svg>`,
    sort: `<svg width="16" height="16" viewBox="0 0 16 16" fill="none"><path d="M4 4L2 6M4 4L6 6M4 4V12M10 4H14M10 8H13M10 12H12" stroke="#333" stroke-width="1.5"/></svg>`,
    find: `<svg width="16" height="16" viewBox="0 0 16 16" fill="none"><circle cx="7" cy="7" r="4" stroke="#333" stroke-width="1.5"/><path d="M10 10L13 13" stroke="#333" stroke-width="2"/></svg>`,
    cond: `<svg width="28" height="28" viewBox="0 0 32 32"><rect x="4" y="4" width="24" height="24" fill="#fff" stroke="#ccc"/><rect x="6" y="6" width="8" height="8" fill="#ff4d4d"/><rect x="18" y="18" width="8" height="8" fill="#2ecc71"/></svg>`,
    tableStyle: `<svg width="28" height="28" viewBox="0 0 32 32"><rect x="4" y="4" width="24" height="24" fill="#fff" stroke="#217346" stroke-width="2"/><path d="M4 12H28M4 20H28M12 4V28M20 4V28" stroke="#217346" stroke-width="1.5"/></svg>`,
    cellStyle: `<svg width="28" height="28" viewBox="0 0 32 32"><rect x="4" y="4" width="24" height="24" fill="#f3f2f1" stroke="#333"/><rect x="10" y="10" width="12" height="12" fill="#fff" stroke="#0078d4"/></svg>`,
    insertRow: `<svg width="24" height="24" viewBox="0 0 24 24"><path d="M4 4h16v16H4z" fill="#dff6dd"/><path d="M12 7v10M7 12h10" stroke="#107c10" stroke-width="2" stroke-linecap="round"/></svg>`,
    deleteRow: `<svg width="24" height="24" viewBox="0 0 24 24"><path d="M4 4h16v16H4z" fill="#fde7e9"/><path d="M8 8l8 8M16 8l-8 8" stroke="#a4262c" stroke-width="2" stroke-linecap="round"/></svg>`,
    format: `<svg width="24" height="24" viewBox="0 0 24 24"><rect x="4" y="4" width="16" height="16" rx="2" fill="#333" fill-opacity="0.1"/><path d="M6 8h12M6 12h12M6 16h8" stroke="#333" stroke-width="2"/></svg>`,
    fill: `<svg width="16" height="16" viewBox="0 0 16 16" fill="none"><rect x="4" y="8" width="8" height="6" fill="#0078d4" stroke="#005a9e"/><path d="M8 3V9M6 7L8 9L10 7" stroke="#0078d4" stroke-width="1.5"/></svg>`,
    currency: `<svg width="16" height="16" viewBox="0 0 16 16" fill="none"><path d="M5 4V12M5 6H10C11 6 12 7 12 8C12 9 11 10 10 10H5M8 10L12 14" stroke="#333" stroke-width="1.5"/></svg>`,
    percent: `<svg width="16" height="16" viewBox="0 0 16 16" fill="none"><circle cx="5" cy="5" r="2" stroke="#333"/><circle cx="11" cy="11" r="2" stroke="#333"/><path d="M12 4L4 12" stroke="#333" stroke-width="1.5"/></svg>`,
    comma: `<svg width="16" height="16" viewBox="0 0 16 16" fill="none"><text x="0" y="12" font-size="10" font-weight="bold" fill="#333">,000</text></svg>`,
    decInc: `<svg width="16" height="16" viewBox="0 0 16 16" fill="none"><text x="1" y="12" font-size="9" fill="#333">.0</text><path d="M12 4L14 2L16 4" stroke="#217346" stroke-width="1.5"/></svg>`,
    decDec: `<svg width="16" height="16" viewBox="0 0 16 16" fill="none"><text x="1" y="12" font-size="9" fill="#333">.0</text><path d="M12 10L14 12L16 10" stroke="#a4262c" stroke-width="1.5"/></svg>`,
    vTop: `<svg width="16" height="16" viewBox="0 0 16 16"><path d="M2 3H14M2 7H10M2 11H14" stroke="#333" stroke-width="1.5"/></svg>`,
    vMiddle: `<svg width="16" height="16" viewBox="0 0 16 16"><path d="M2 5H14M2 9H10M2 13H14" stroke="#333" stroke-width="1.5"/></svg>`,
    vBottom: `<svg width="16" height="16" viewBox="0 0 16 16"><path d="M2 7H14M2 11H10M2 15H14" stroke="#333" stroke-width="1.5"/></svg>`,
    left: `<svg width="16" height="16" viewBox="0 0 16 16"><path d="M3 4H13M3 8H10M3 12H13" stroke="#333" stroke-width="1.5"/></svg>`,
    center: `<svg width="16" height="16" viewBox="0 0 16 16"><path d="M3 4H13M5 8H11M3 12H13" stroke="#333" stroke-width="1.5"/></svg>`,
    right: `<svg width="16" height="16" viewBox="0 0 16 16"><path d="M3 4H13M6 8H13M3 12H13" stroke="#333" stroke-width="1.5"/></svg>`,
    wrap: `<svg width="16" height="16" viewBox="0 0 16 16"><path d="M3 4H13M3 8H9C11 8 11 12 9 12H3M3 12L5 10M3 12L5 14" stroke="#333" stroke-width="1.5"/></svg>`,
    merge: `<svg width="16" height="16" viewBox="0 0 16 16"><rect x="2" y="4" width="12" height="8" stroke="#333"/><path d="M5 8H11M5 8L7 6M5 8L7 10M11 8L9 6M11 8L9 10" stroke="#333"/></svg>`
};
</script>

<template>
    <div class="excel-ribbon">
        <div class="ribbon-tabs">
            <div class="tab file-tab" @click="handleAction('import')" title="Открыть .xlsx">Файл</div>
            <div class="tab active">Главная</div>
            <div class="tab">Вставка</div>
            <div class="tab">Разметка страницы</div>
            <div class="tab">Формулы</div>
            <div class="tab">Данные</div>
            <div class="tab">Рецензирование</div>
            <div class="tab">Вид</div>
            <div class="tab">Справка</div>
        </div>

        <div class="ribbon-content">
            <!-- Группа: Файл (Импорт/Экспорт) -->
            <div class="ribbon-group">
                <div class="group-inner" style="flex-direction: column; gap: 2px;">
                    <button class="btn-mini" @click="handleAction('import')" title="Открыть .xlsx">
                        <span style="font-size:14px;">📂</span> Открыть
                    </button>
                    <button class="btn-mini" @click="handleAction('export')" title="Сохранить как .xlsx">
                        <span style="font-size:14px;">💾</span> Скачать .xlsx
                    </button>
                    <button class="btn-mini" @click="handleAction('mergeCells')" title="Объединить выделенные ячейки">
                        <span style="font-size:14px;">⬌</span> Объединить
                    </button>
                    <button class="btn-mini" @click="handleAction('unmergeCells')" title="Разъединить ячейки">
                        <span style="font-size:14px;">⬍</span> Разъединить
                    </button>
                    <button class="btn-mini" @click="handleAction('setValidation')" title="Список значений для колонки">
                        <span style="font-size:14px;">▼</span> Проверка данных
                    </button>
                    <button class="btn-mini" @click="handleAction('freezeRow')" title="Закрепить первую строку">
                        <span style="font-size:14px;">▤</span> Закрепить строку
                    </button>
                    <button class="btn-mini" @click="handleAction('freezeCol')" title="Закрепить первую колонку">
                        <span style="font-size:14px;">▥</span> Закрепить колонку
                    </button>
                    <button class="btn-mini" @click="handleAction('findReplace')" title="Найти и заменить (Ctrl+H)">
                        <span style="font-size:14px;">🔍</span> Найти и заменить
                    </button>
                </div>
                <div class="group-label">Файл / Данные</div>
            </div>

            <!-- Группа: Буфер обмена -->
            <div class="ribbon-group">
                <div class="group-inner">
                    <button class="btn-large" @click="handleAction('paste')">
                        <div class="icon-v" v-html="icons.paste"></div>
                        <span>Вставить</span>
                    </button>
                    <div class="btn-stack">
                        <button class="btn-mini" @click="handleAction('cut')"><span v-html="icons.cut"></span> Вырезать</button>
                        <button class="btn-mini" @click="handleAction('copy')"><span v-html="icons.copy"></span> Копировать</button>
                        <button class="btn-mini" @click="handleAction('formatPainter')"><span v-html="icons.brush"></span> Формат по образцу</button>
                    </div>
                </div>
                <div class="group-label">Буфер обмена</div>
            </div>

            <!-- Группа: Шрифт -->
            <div class="ribbon-group">
                <div class="group-inner font-group">
                    <div class="row">
                        <select class="font-select" :value="activeFontFamily" @change="handleAction('fontFamily', $event.target.value)">
                            <option v-for="f in fonts" :key="f" :value="f">{{ f }}</option>
                        </select>
                        <select class="size-select" :value="activeFontSize" @change="handleAction('fontSize', $event.target.value)">
                            <option v-for="s in [8,9,10,11,12,14,16,18,20,22,24,26,28,36,48,72]" :key="s" :value="s">{{ s }}</option>
                        </select>
                        <button class="btn-tool" @click="handleAction('fontSizeInc')">A<sup>+</sup></button>
                        <button class="btn-tool" @click="handleAction('fontSizeDec')">A<sup>-</sup></button>
                    </div>
                    <div class="row style-tools">
                        <button class="btn-tool bold" :class="{ active: activeCell?.style?.fontWeight === 'bold' }" @click="handleAction('bold')">Ж</button>
                        <button class="btn-tool italic" :class="{ active: activeCell?.style?.fontStyle === 'italic' }" @click="handleAction('italic')">К</button>
                        <button class="btn-tool underline" :class="{ active: activeCell?.style?.textDecoration === 'underline' }" @click="handleAction('underline')"><u>Ч</u></button>
                        <div class="color-picker-container">
                            <div class="split-btn">
                                <button class="btn-tool" @click="handleAction('border', currentBorderType)" v-html="borderIcon(currentBorderType)"></button>
                                <button class="btn-tool drop-btn" @click.stop="togglePicker('border', $event)">
                                    <svg width="8" height="8" viewBox="0 0 10 10"><path d="M2 3L5 6L8 3" stroke="#333" fill="none"/></svg>
                                </button>
                            </div>
                            <div class="color-dropdown border-dropdown" v-if="activePicker === 'border'" :style="{ top: dropdownPos.top + 'px', left: dropdownPos.left + 'px' }" @click.stop>
                                <div class="palette-title">Границы</div>
                                <div class="border-list">
                                    <div v-for="opt in borderOptions" :key="opt.type"
                                         class="border-option" @click="selectBorder(opt.type)">
                                        <span class="border-icon" v-html="borderIcon(opt.type)"></span>
                                        <span class="border-label">{{ opt.label }}</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="color-picker-container">
                            <div class="split-btn">
                                <button class="btn-tool" @click="handleAction('bgColor', currentBgColor)">
                                    <div v-html="icons.bucket"></div>
                                    <div class="c-bar" :style="{ backgroundColor: currentBgColor === 'transparent' ? '#fff' : currentBgColor }"></div>
                                </button>
                                <button class="btn-tool drop-btn" @click.stop="togglePicker('bgColor', $event)">
                                    <svg width="8" height="8" viewBox="0 0 10 10"><path d="M2 3L5 6L8 3" stroke="#333" fill="none"/></svg>
                                </button>
                            </div>
                            <div class="color-dropdown" v-if="activePicker === 'bgColor'" :style="{ top: dropdownPos.top + 'px', left: dropdownPos.left + 'px' }" @click.stop>
                                <div class="palette-title">Цвета темы</div>
                                <div class="palette-grid">
                                    <div v-for="(col, cIdx) in themeColors[0]" :key="'bg-theme-col-'+cIdx" class="palette-col">
                                        <div v-for="(row, rIdx) in themeColors" :key="'bg-theme-'+cIdx+'-'+rIdx" 
                                             class="color-swatch" :style="{ backgroundColor: themeColors[rIdx][cIdx] }"
                                             @click="selectColor(themeColors[rIdx][cIdx])"></div>
                                    </div>
                                </div>
                                <div class="palette-title">Стандартные цвета</div>
                                <div class="palette-row">
                                    <div v-for="color in standardColors" :key="'bg-std-'+color" 
                                         class="color-swatch" :style="{ backgroundColor: color }"
                                         @click="selectColor(color)"></div>
                                </div>
                                <div class="palette-option" @click="selectColor('transparent')">Нет заливки</div>
                                <div class="palette-option flex-center" @click="triggerNativePicker('bg')">
                                    <svg width="14" height="14" viewBox="0 0 16 16" fill="none"><circle cx="8" cy="8" r="6" stroke="#333" stroke-dasharray="2 2"/><path d="M8 2V14M2 8H14M4 4L12 12M4 12L12 4" stroke="#f29900" stroke-width="1.5"/></svg>
                                    <span style="margin-left: 6px;">Другие цвета...</span>
                                </div>
                                <input type="color" ref="bgNativePicker" class="hidden-picker" @input="selectColor($event.target.value)" />
                            </div>
                        </div>

                        <div class="color-picker-container">
                            <div class="split-btn">
                                <button class="btn-tool" @click="handleAction('color', currentTextColor)">
                                    <span class="a-text">A</span>
                                    <div class="c-bar" :style="{ backgroundColor: currentTextColor }"></div>
                                </button>
                                <button class="btn-tool drop-btn" @click.stop="togglePicker('color', $event)">
                                    <svg width="8" height="8" viewBox="0 0 10 10"><path d="M2 3L5 6L8 3" stroke="#333" fill="none"/></svg>
                                </button>
                            </div>
                            <div class="color-dropdown" v-if="activePicker === 'color'" :style="{ top: dropdownPos.top + 'px', left: dropdownPos.left + 'px' }" @click.stop>
                                <div class="palette-title">Цвета темы</div>
                                <div class="palette-grid">
                                    <div v-for="(col, cIdx) in themeColors[0]" :key="'text-theme-col-'+cIdx" class="palette-col">
                                        <div v-for="(row, rIdx) in themeColors" :key="'text-theme-'+cIdx+'-'+rIdx" 
                                             class="color-swatch" :style="{ backgroundColor: themeColors[rIdx][cIdx] }"
                                             @click="selectColor(themeColors[rIdx][cIdx])"></div>
                                    </div>
                                </div>
                                <div class="palette-title">Стандартные цвета</div>
                                <div class="palette-row">
                                    <div v-for="color in standardColors" :key="'text-std-'+color" 
                                         class="color-swatch" :style="{ backgroundColor: color }"
                                         @click="selectColor(color)"></div>
                                </div>
                                <div class="palette-option" @click="selectColor('#000000')">Авто</div>
                                <div class="palette-option flex-center" @click="triggerNativePicker('text')">
                                    <svg width="14" height="14" viewBox="0 0 16 16" fill="none"><circle cx="8" cy="8" r="6" stroke="#333" stroke-dasharray="2 2"/><path d="M8 2V14M2 8H14M4 4L12 12M4 12L12 4" stroke="#f29900" stroke-width="1.5"/></svg>
                                    <span style="margin-left: 6px;">Другие цвета...</span>
                                </div>
                                <input type="color" ref="textNativePicker" class="hidden-picker" @input="selectColor($event.target.value)" />
                            </div>
                        </div>

                    </div>
                </div>
                <div class="group-label">Шрифт</div>
            </div>

            <!-- Группа: Выравнивание -->
            <div class="ribbon-group">
                <div class="group-inner align-group">
                    <div class="align-grid">
                        <div class="row">
                            <button class="btn-tool" @click="handleAction('valign', 'top')" v-html="icons.vTop"></button>
                            <button class="btn-tool" @click="handleAction('valign', 'middle')" v-html="icons.vMiddle"></button>
                            <button class="btn-tool" @click="handleAction('valign', 'bottom')" v-html="icons.vBottom"></button>
                        </div>
                        <div class="row">
                            <button class="btn-tool" @click="handleAction('textAlign', 'left')" v-html="icons.left"></button>
                            <button class="btn-tool" @click="handleAction('textAlign', 'center')" v-html="icons.center"></button>
                            <button class="btn-tool" @click="handleAction('textAlign', 'right')" v-html="icons.right"></button>
                        </div>
                    </div>
                    <div class="text-tools">
                        <button class="btn-text-action" @click="handleAction('wrapText')">
                            <span v-html="icons.wrap"></span> Перенести текст
                        </button>
                        <button class="btn-text-action" @click="handleAction('mergeCenter')">
                            <span v-html="icons.merge"></span> Объединить в центре
                        </button>
                    </div>
                </div>
                <div class="group-label">Выравнивание</div>
            </div>

            <!-- Группа: Число -->
            <div class="ribbon-group">
                <div class="group-inner number-group">
                    <div class="number-stack">
                        <select class="format-dropdown" @change="handleAction('format', $event.target.value)" style="width: 120px;">
                            <option value="general">Общий</option>
                            <option value="number">Числовой</option>
                            <option value="currency">Денежный</option>
                            <option value="shortDate">Краткая дата</option>
                            <option value="percent">Процентный</option>
                        </select>
                        <div class="row number-tools">
                            <button class="btn-tool" @click="handleAction('format', 'currency')" v-html="icons.currency"></button>
                            <button class="btn-tool" @click="handleAction('format', 'percent')" v-html="icons.percent"></button>
                            <button class="btn-tool" @click="handleAction('format', 'comma')" v-html="icons.comma"></button>
                            <button class="btn-tool" @click="handleAction('precisionInc')" v-html="icons.decInc"></button>
                            <button class="btn-tool" @click="handleAction('precisionDec')" v-html="icons.decDec"></button>
                        </div>
                    </div>
                </div>
                <div class="group-label">Число</div>
            </div>

            <!-- Группа: Стили -->
            <div class="ribbon-group">
                <div class="group-inner styles-group">
                    <button class="btn-style" @click="handleAction('conditional')">
                        <div v-html="icons.cond"></div>
                        <span>Условное форматирование</span>
                    </button>
                    <button class="btn-style" @click="handleAction('formatTable')">
                        <div v-html="icons.tableStyle"></div>
                        <span>Форматировать как таблицу</span>
                    </button>
                    <div class="color-picker-container cell-styles-container">
                        <button class="btn-style drop-btn-down" @click.stop="togglePicker('cellStyles', $event)">
                            <div v-html="icons.cellStyle"></div>
                            <span>Стили ячеек <svg width="8" height="8" viewBox="0 0 10 10"><path d="M2 3L5 6L8 3" stroke="#333" fill="none"/></svg></span>
                        </button>
                        
                        <div class="color-dropdown cell-styles-dropdown" v-if="activePicker === 'cellStyles'" :style="{ top: dropdownPos.top + 'px', left: dropdownPos.left + 'px' }" @click.stop>
                            <div class="palette-title">Хороший, плохой и нейтральный</div>
                            <div class="style-grid-4">
                                <div class="cell-style-item" style="color:#000; border:1px solid #c8c8c8" @click="applyCellStyle('normal')">Обычный</div>
                                <div class="cell-style-item" style="background:#ffeb9c; color:#9c6500" @click="applyCellStyle('neutral')">Нейтральный</div>
                                <div class="cell-style-item" style="background:#ffc7ce; color:#9c0006" @click="applyCellStyle('bad')">Плохой</div>
                                <div class="cell-style-item" style="background:#c6efce; color:#006100" @click="applyCellStyle('good')">Хороший</div>
                            </div>

                            <div class="palette-title">Данные и модель</div>
                            <div class="style-grid-4">
                                <div class="cell-style-item" style="background:#f2dddc; color:#fa7d00; border:1px solid #7f7f7f" @click="applyCellStyle('input')">Ввод</div>
                                <div class="cell-style-item" style="background:#f2f2f2; color:#3f3f3f; border:1px solid #3f3f3f; font-weight:bold" @click="applyCellStyle('output')">Вывод</div>
                                <div class="cell-style-item" style="color:#fa7d00; border:1px solid #7f7f7f; font-weight:bold" @click="applyCellStyle('calc')">Вычисление</div>
                                <div class="cell-style-item" style="background:#a5a5a5; color:#fff; border:2px solid #3f3f3f; font-weight:bold" @click="applyCellStyle('check')">Контрольная...</div>
                                <div class="cell-style-item" style="background:#ffffcc; color:#000; border:1px solid #b2b2b2" @click="applyCellStyle('note')">Примечание</div>
                                <div class="cell-style-item" style="color:#fa7d00; border-bottom:2px solid #ff8001; padding-bottom: 2px;" @click="applyCellStyle('linked')">Связанная ячейка</div>
                                <div class="cell-style-item" style="color:#ff0000" @click="applyCellStyle('warning')">Текст преду...</div>
                                <div class="cell-style-item" style="color:#7f7f7f; font-style:italic" @click="applyCellStyle('explain')">Пояснение</div>
                            </div>

                            <div class="palette-title">Названия и заголовки</div>
                            <div class="style-grid-4" style="align-items: end;">
                                <div class="cell-style-item" style="font-size:20px; font-weight:bold; color:#000;" @click="applyCellStyle('heading1')">Заголовок 1</div>
                                <div class="cell-style-item" style="font-size:16px; font-weight:bold; color:#000; border-bottom:2px solid #4f81bd; padding-bottom:2px;" @click="applyCellStyle('heading2')">Заголовок 2</div>
                                <div class="cell-style-item" style="font-size:14px; font-weight:bold; color:#000; border-bottom:2px solid #a5b592; padding-bottom:2px;" @click="applyCellStyle('heading3')">Заголовок 3</div>
                                <div class="cell-style-item" style="font-size:12px; font-weight:bold; color:#000;" @click="applyCellStyle('heading4')">Заголовок 4</div>
                                <div class="cell-style-item" style="font-weight:bold; color:#000; border-bottom:3px double #4f81bd; border-top:1px solid #4f81bd; padding: 2px 8px;" @click="applyCellStyle('total')">Итог</div>
                                <div class="cell-style-item" style="font-size:24px; font-weight:bold; color:#000;" @click="applyCellStyle('title')">Название</div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="group-label">Стили</div>
            </div>

            <!-- Группа: Ячейки -->
            <div class="ribbon-group">
                <div class="group-inner cells-group">
                    <button class="btn-medium" @click="handleAction('insertRow')">
                        <div class="icon-m" v-html="icons.insertRow"></div>
                        <span>Вставить</span>
                    </button>
                    <button class="btn-medium" @click="handleAction('deleteRow')">
                        <div class="icon-m" v-html="icons.deleteRow"></div>
                        <span>Удалить</span>
                    </button>
                    <button class="btn-medium" @click="handleAction('format')">
                        <div class="icon-m" v-html="icons.format"></div>
                        <span>Формат</span>
                    </button>
                </div>
                <div class="group-label">Ячейки</div>
            </div>

            <!-- Группа: Редактирование -->
            <div class="ribbon-group">
                <div class="group-inner editing-group">
                    <div class="btn-stack">
                        <button class="btn-mini" @click="handleAction('autosum')">
                            <span v-html="icons.sum"></span> Автосумма
                        </button>
                        <button class="btn-mini" @click="handleAction('fill')">
                            <span v-html="icons.fill"></span> Заполнить
                        </button>
                        <button class="btn-mini" @click="handleAction('clear')">
                            <span v-html="icons.clear"></span> Очистить
                        </button>
                    </div>
                    <div class="btn-stack">
                        <button class="btn-mini" @click="handleAction('sort')">
                            <span v-html="icons.sort"></span> Сортировка
                        </button>
                        <button class="btn-mini" @click="handleAction('find')">
                            <span v-html="icons.find"></span> Найти
                        </button>
                    </div>
                </div>
                <div class="group-label">Редактирование</div>
            </div>
        </div>
    </div>
</template>

<style scoped>
.excel-ribbon {
    background: #f3f2f1;
    border-bottom: 1px solid #d2d0ce;
    display: flex;
    flex-direction: column;
    z-index: 100;
}

.ribbon-tabs {
    display: flex;
    background: #fff;
    padding-left: 10px;
    height: 32px;
}

.tab {
    padding: 6px 14px;
    font-size: 13px;
    cursor: pointer;
    border-bottom: 2px solid transparent;
    transition: all 0.1s;
    display: flex;
    align-items: center;
}
.tab:hover { background: #f3f2f1; }
.tab.active { 
    color: #217346; 
    border-bottom: 3px solid #217346; 
    font-weight: 600; 
    background: #f3f2f1;
}
.file-tab { 
    background: #185c37; 
    color: #fff; 
    font-weight: bold; 
    padding: 0 20px !important;
}
.file-tab:hover { background: #104a2c; }

.ribbon-content {
    display: flex;
    height: 98px;
    padding: 2px;
    background: #f3f2f1;
    overflow-x: auto;
    overflow-y: hidden;
    scrollbar-width: thin;
    scrollbar-color: #c8c6c4 transparent;
}
.ribbon-content::-webkit-scrollbar { height: 6px; }
.ribbon-content::-webkit-scrollbar-track { background: transparent; }
.ribbon-content::-webkit-scrollbar-thumb {
    background: #c8c6c4;
    border-radius: 3px;
}
.ribbon-content::-webkit-scrollbar-thumb:hover { background: #a19f9d; }

.ribbon-tabs {
    overflow-x: auto;
    overflow-y: hidden;
    scrollbar-width: none;
}
.ribbon-tabs::-webkit-scrollbar { display: none; }

.ribbon-group {
    display: flex;
    flex-direction: column;
    padding: 4px 10px;
    border-right: 1px solid #e1dfdd;
    min-width: fit-content;
    align-items: center;
    position: relative;
}

.ribbon-group:last-child {
    border-right: none;
}

.group-inner {
    flex: 1;
    display: flex;
    gap: 6px;
    align-items: center;
    justify-content: center;
}

.group-label {
    font-size: 10px;
    color: #828282;
    text-align: center;
    margin-top: auto;
    padding-bottom: 2px;
}

.btn-large {
    display: flex;
    flex-direction: column;
    align-items: center;
    width: 60px;
    height: 72px;
    background: transparent;
    border: 1px solid transparent;
    cursor: pointer;
    font-size: 11px;
}
.btn-large:hover { background: #edebe9; border-color: #d2d0ce; }

.btn-mini { 
    background: transparent; border: 1px solid transparent; 
    text-align: left; font-size: 11px; padding: 1px 4px; cursor: pointer;
    display: flex; align-items: center; gap: 4px; white-space: nowrap;
}
.btn-mini:hover { background: #edebe9; }

.btn-medium {
    display: flex;
    flex-direction: column;
    align-items: center;
    width: 60px;
    height: 72px;
    background: transparent;
    border: 1px solid transparent;
    cursor: pointer;
    font-size: 11px;
    color: #333;
    padding-top: 4px;
}

.btn-medium:hover {
    background: #edebe9;
}

.icon-m {
    margin-bottom: 4px;
}

.label-tiny {
    font-size: 10px;
    line-height: 1.1;
    text-align: center;
}

.btn-tool {
    min-width: 24px; height: 22px;
    background: transparent; border: 1px solid transparent;
    cursor: pointer; font-size: 12px;
    display: flex; flex-direction: column; align-items: center; justify-content: center;
}
.btn-tool:hover { background: #edebe9; border-color: #d2d0ce; }
.btn-tool.active { background: #d2d0ce; }

.row {
    display: flex;
    align-items: center;
    gap: 2px;
}

.font-select { width: 100px; font-size: 11px; height: 22px; }
.size-select { width: 42px; font-size: 11px; height: 22px; }

.btn-text-action {
    font-size: 10px;
    background: transparent;
    border: 1px solid transparent;
    padding: 2px 4px;
    width: 140px;
    display: flex; align-items: center; gap: 6px;
}
.align-grid {
    display: flex;
    flex-direction: column;
    gap: 2px;
}
.align-grid .row {
    display: flex;
    gap: 2px;
}
.text-tools {
    display: flex;
    flex-direction: column;
    gap: 2px;
}

.icon-c { width: 24px; height: 24px; border: 1px solid #d2d0ce; border-radius: 2px; }
.icon-c.insert { background: #dff6dd; position: relative; }
.icon-c.insert::after { content: '+'; color: green; font-weight: bold; position: absolute; top: 0; left: 6px; }
.icon-c.delete { background: #fde7e9; position: relative; }
.icon-c.delete::after { content: '×'; color: red; font-weight: bold; position: absolute; top: -2px; left: 6px; font-size: 18px; }
.icon-c.format { background: #fff; border: 1px solid #0078d4; }

.btn-cell { 
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    font-size: 10px; 
    width: 60px; 
    height: 60px;
    background: transparent; 
    border: 1px solid transparent; 
    cursor: pointer; 
    gap: 4px;
}
.btn-cell:hover { background: #edebe9; }
.btn-cell span { line-height: 1; }

.btn-style {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    font-size: 10px;
    width: 90px;
    height: 60px;
    background: transparent;
    border: 1px solid transparent;
    cursor: pointer;
    gap: 4px;
}
.btn-style:hover { background: #edebe9; }
.btn-style span { line-height: 1.1; text-align: center; }

.btn-edit { font-size: 11px; display: flex; align-items: center; gap: 4px; padding: 2px 6px; background: transparent; border: 1px solid transparent; cursor: pointer; width: 100%; }
.btn-edit:hover { background: #edebe9; }

.number-stack {
    display: flex;
    flex-direction: column;
    gap: 4px;
    align-items: flex-start;
}
.number-tools {
    display: flex;
    gap: 2px;
}
.format-dropdown {
    width: 120px;
    height: 22px;
    font-size: 12px;
    border: 1px solid #d2d0ce;
}
.mini-icon { display: flex; align-items: center; }
.mini-icon :deep(svg) { width: 16px; height: 16px; }

/* Color Picker Styles */
.color-picker-container {
    position: relative;
    display: inline-flex;
}
.split-btn {
    display: flex;
    align-items: stretch;
}
.split-btn .drop-btn {
    padding: 0 2px;
    min-width: 14px;
}
.color-dropdown {
    position: fixed;
    background: #fff;
    border: 1px solid #ccc;
    box-shadow: 0 4px 8px rgba(0,0,0,0.15);
    padding: 8px;
    z-index: 10000;
    width: 190px;
}
.palette-title {
    font-size: 11px;
    color: #333;
    margin: 4px 0;
    font-weight: 500;
}
.palette-grid {
    display: flex;
    gap: 3px;
    margin-bottom: 8px;
}
.palette-col {
    display: flex;
    flex-direction: column;
    gap: 2px;
}
.palette-row {
    display: flex;
    gap: 3px;
    margin-bottom: 8px;
}
.color-swatch {
    width: 15px;
    height: 15px;
    border: 1px solid transparent;
    cursor: pointer;
}
.color-swatch:hover {
    border-color: #f29900;
    outline: 1px solid #f29900;
}
.palette-col .color-swatch:first-child {
    margin-bottom: 5px; /* Gap between main color and shades */
}
.palette-option {
    font-size: 12px;
    padding: 4px;
    cursor: pointer;
}
.palette-option:hover {
    background: #f3f2f1;
}
.flex-center {
    display: flex;
    align-items: center;
}
.hidden-picker {
    position: absolute;
    opacity: 0;
    width: 0;
    height: 0;
    border: none;
    padding: 0;
    pointer-events: none;
}
/* Border Dropdown */
.border-dropdown {
    width: 240px;
    padding: 8px;
}
.border-list {
    display: flex;
    flex-direction: column;
}
.border-option {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 4px 6px;
    cursor: pointer;
    font-size: 12px;
}
.border-option:hover {
    background: #f3f2f1;
}
.border-icon {
    display: inline-flex;
    width: 20px;
    height: 20px;
    flex-shrink: 0;
}
.border-label {
    flex: 1;
    color: #323130;
}

/* Cell Styles Dropdown */
.cell-styles-dropdown {
    width: 760px;
    padding: 16px;
}
.style-grid-4 {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 8px;
    margin-bottom: 16px;
}
.cell-style-item {
    padding: 6px 12px;
    cursor: pointer;
    border: 1px solid transparent;
    display: flex;
    align-items: center;
    justify-content: flex-start;
    height: 100%;
    min-height: 36px;
    font-size: 13px;
    background: transparent;
    white-space: nowrap;
}
.cell-style-item:hover {
    outline: 2px solid #f29900;
}
.palette-title {
    font-size: 13px;
    color: #444;
    margin: 0 0 8px 0;
    font-weight: 600;
    border-bottom: 1px solid #d2d0ce;
    padding-bottom: 4px;
}
</style>

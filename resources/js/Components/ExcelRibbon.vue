<script setup>
import { ref } from 'vue';

const props = defineProps({
    activeCell: Object
});

const emit = defineEmits(['action']);

const handleAction = (type, value = null) => {
    emit('action', { type, value });
};

const fonts = ['Arial', 'Calibri', 'Times New Roman', 'Segoe UI', 'Roboto'];

// SVG Icons as components or strings
const icons = {
    paste: `<svg width="32" height="32" viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg"><rect x="8" y="6" width="16" height="20" rx="1" fill="#F3F2F1" stroke="#333" stroke-width="1.5"/><rect x="11" y="4" width="10" height="4" rx="1" fill="#F3F2F1" stroke="#333" stroke-width="1.5"/><path d="M12 12H20M12 16H20M12 20H17" stroke="#333" stroke-width="1.5" stroke-linecap="round"/></svg>`,
    cut: `<svg width="16" height="16" viewBox="0 0 16 16" fill="none"><path d="M4 4L12 12M12 4L4 12" stroke="#333" stroke-width="1.5"/></svg>`,
    copy: `<svg width="16" height="16" viewBox="0 0 16 16" fill="none"><rect x="3" y="3" width="7" height="9" stroke="#333" stroke-width="1.2"/><rect x="6" y="5" width="7" height="9" fill="#F3F2F1" stroke="#333" stroke-width="1.2"/></svg>`,
    brush: `<svg width="16" height="16" viewBox="0 0 16 16" fill="none"><path d="M3 13L10 6L13 3M3 13L2 14M3 13L4 12" stroke="#8B4513" stroke-width="2"/><rect x="9" y="3" width="4" height="4" fill="#FFD700"/></svg>`,
    border: `<svg width="18" height="18" viewBox="0 0 18 18" fill="none"><rect x="3" y="3" width="12" height="12" stroke="#333" stroke-width="1.5"/><path d="M3 9H15M9 3V15" stroke="#333" stroke-width="1"/></svg>`,
    bucket: `<svg width="18" height="18" viewBox="0 0 18 18" fill="none"><path d="M4 10L9 5L14 10L9 15L4 10Z" fill="#ffff00" stroke="#333"/><path d="M14 10L16 12L15 13L13 11" stroke="#333"/></svg>`,
    wrap: `<svg width="24" height="24" viewBox="0 0 24 24" fill="none"><path d="M4 6H20M4 12H14M4 18H10" stroke="#333" stroke-width="2"/><path d="M16 12L18 14L20 12" stroke="#217346" stroke-width="2"/></svg>`,
    merge: `<svg width="24" height="24" viewBox="0 0 24 24" fill="none"><rect x="4" y="8" width="16" height="8" stroke="#333" stroke-width="1.5"/><path d="M8 12H16M8 12L10 10M8 12L10 14M16 12L14 10M16 12L14 14" stroke="#217346" stroke-width="1.5"/></svg>`,
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
            <div class="tab file-tab">Файл</div>
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
                        <select class="font-select" @change="handleAction('fontFamily', $event.target.value)">
                            <option v-for="f in fonts" :key="f" :value="f">{{ f }}</option>
                        </select>
                        <select class="size-select" @change="handleAction('fontSize', $event.target.value)">
                            <option v-for="s in [8,9,10,11,12,14,16,18,20,22,24,26,28,36,48,72]" :key="s" :value="s" :selected="s==11">{{ s }}</option>
                        </select>
                        <button class="btn-tool" @click="handleAction('fontSizeInc')">A<sup>+</sup></button>
                        <button class="btn-tool" @click="handleAction('fontSizeDec')">A<sup>-</sup></button>
                    </div>
                    <div class="row style-tools">
                        <button class="btn-tool bold" :class="{ active: activeCell?.style?.fontWeight === 'bold' }" @click="handleAction('bold')">Ж</button>
                        <button class="btn-tool italic" :class="{ active: activeCell?.style?.fontStyle === 'italic' }" @click="handleAction('italic')">К</button>
                        <button class="btn-tool underline" :class="{ active: activeCell?.style?.textDecoration === 'underline' }" @click="handleAction('underline')"><u>Ч</u></button>
                        <button class="btn-tool border" @click="handleAction('border')" v-html="icons.border"></button>
                        <button class="btn-tool" @click="handleAction('bgColor', '#ffff00')">
                            <div v-html="icons.bucket"></div>
                            <div class="c-bar yellow"></div>
                        </button>
                        <button class="btn-tool" @click="handleAction('color', '#ff0000')">
                            <span class="a-text">A</span>
                            <div class="c-bar red"></div>
                        </button>
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
                    <button class="btn-style" @click="handleAction('cellStyles')">
                        <div v-html="icons.cellStyle"></div>
                        <span>Стили ячеек</span>
                    </button>
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
}

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
    display: flex; align-items: center; gap: 4px;
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
</style>
",Description:

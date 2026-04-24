<script setup>
import { onMounted, onUnmounted } from 'vue';

const props = defineProps({
    x: Number,
    y: Number,
    cellData: Object,
});

const emit = defineEmits(['close', 'action']);

const closeMenu = () => emit('close');

onMounted(() => {
    window.addEventListener('click', closeMenu);
    window.addEventListener('contextmenu', closeMenu); // Закрываем при повторном клике правой кнопкой
});

onUnmounted(() => {
    window.removeEventListener('click', closeMenu);
    window.removeEventListener('contextmenu', closeMenu);
});

const handleAction = (action) => {
    emit('action', action);
    closeMenu();
};
</script>

<template>
    <!-- Добавляем .prevent на contextmenu, чтобы браузерное меню не вылезало внутри нашего -->
    <div class="excel-context-menu" :style="{ top: y + 'px', left: x + 'px' }" @click.stop @contextmenu.prevent>
        <!-- Top Formatting Bar -->
        <div class="formatting-bar">
            <button class="btn-cut" title="Вырезать" @click="handleAction('cut')">
                <svg viewBox="0 0 24 24" width="16" height="16"><path fill="#742774" d="M19,3L13,9L15,11L21,5V3M12,12.5A0.5,0.5 0 0,1 11.5,12A0.5,0.5 0 0,1 12,11.5A0.5,0.5 0 0,1 12.5,12A0.5,0.5 0 0,1 12,12.5M6,3L11,8L9,10L3,4V3M3,20V19L9,13L11,15L5,21H3M19,21H21V20L15,14L13,16L19,22V21Z"/></svg>
            </button>
            <button class="btn-copy" title="Копировать" @click="handleAction('copy')">
                <svg viewBox="0 0 24 24" width="16" height="16"><path fill="#D83B01" d="M19,21H8V7H19M19,5H8A2,2 0 0,0 6,7V21A2,2 0 0,0 8,23H19A2,2 0 0,0 21,21V7A2,2 0 0,0 19,5M16,1H4A2,2 0 0,0 2,3V17H4V3H16V1Z"/></svg>
            </button>
            <button class="btn-paste" title="Вставить" @click="handleAction('paste')">
                <svg viewBox="0 0 24 24" width="16" height="16"><path fill="#0078D4" d="M19,20H5V4H7V7H17V4H19M12,2A1,1 0 0,1 13,3A1,1 0 0,1 12,4A1,1 0 0,1 11,3A1,1 0 0,1 12,2M19,2H14.82C14.4,0.84 13.3,0 12,0C10.7,0 9.6,0.84 9.18,2H5A2,2 0 0,0 3,4V20A2,2 0 0,0 5,22H19A2,2 0 0,0 21,20V4A2,2 0 0,0 19,2Z"/></svg>
            </button>
            <div class="divider-v"></div>
            <button class="btn-bold" style="font-weight: bold;" @click="handleAction('bold')">B</button>
            <button class="btn-italic" style="font-style: italic;" @click="handleAction('italic')">I</button>
            <button class="btn-color" @click="handleAction('color')">A<div class="color-underline red"></div></button>
            <button class="btn-fill" @click="handleAction('fill')">
                <svg viewBox="0 0 24 24" width="14" height="14"><path fill="#0078D4" d="M19.22,10.63L13.37,4.78C12.91,4.32 12.14,4.32 11.68,4.78L10.37,6.09L11.53,7.25L13.1,5.69L18.41,11H15.08L14.08,12H18.25L19.25,11L19.22,10.63M10.25,12H6.08L5.08,13L6.08,14H9.25L10.25,13M10,18H14V16H10V18M11,2H13V0H11V2M3,18V20H21V18H3Z"/></svg>
                <div class="color-underline yellow"></div>
            </button>
        </div>

        <div class="menu-list">
            <div class="menu-item" @click="handleAction('cut')">
                <span class="icon"><svg viewBox="0 0 24 24" width="16" height="16"><path fill="#742774" d="M19,3L13,9L15,11L21,5V3M12,12.5A0.5,0.5 0 0,1 11.5,12A0.5,0.5 0 0,1 12,11.5A0.5,0.5 0 0,1 12.5,12A0.5,0.5 0 0,1 12,12.5M6,3L11,8L9,10L3,4V3M3,20V19L9,13L11,15L5,21H3M19,21H21V20L15,14L13,16L19,22V21Z"/></svg></span> 
                Вырезать <span class="shortcut">Ctrl+X</span>
            </div>
            <div class="menu-item" @click="handleAction('copy')">
                <span class="icon"><svg viewBox="0 0 24 24" width="16" height="16"><path fill="#D83B01" d="M19,21H8V7H19M19,5H8A2,2 0 0,0 6,7V21A2,2 0 0,0 8,23H19A2,2 0 0,0 21,21V7A2,2 0 0,0 19,5M16,1H4A2,2 0 0,0 2,3V17H4V3H16V1Z"/></svg></span> 
                Копировать <span class="shortcut">Ctrl+C</span>
            </div>
            <div class="menu-item disabled">
                Параметры вставки:
            </div>
            <div class="menu-item" @click="handleAction('paste-special')">
                <span class="icon"></span> Специальная вставка...
            </div>
            
            <div class="divider"></div>
            
            <div class="menu-item" @click="handleAction('insert')">
                <span class="icon blue">➕</span> Вставить...
            </div>
            <div class="menu-item" @click="handleAction('delete')">
                <span class="icon red">❌</span> Удалить...
            </div>
            <div class="menu-item" @click="handleAction('clear')">
                <span class="icon orange">🧹</span> Очистить содержимое
            </div>
            
            <div class="divider"></div>
            
            <div class="menu-item" @click="handleAction('format')">
                <span class="icon gray">📏</span> Формат ячеек...
            </div>
            <div class="menu-item" @click="handleAction('hyperlink')">
                <span class="icon blue">🔗</span> Гиперссылка...
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

.color-underline {
    position: absolute;
    bottom: 4px;
    width: 14px;
    height: 3px;
}
.color-underline.red { background: #d83b01; }
.color-underline.yellow { background: #ffeb3b; }

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

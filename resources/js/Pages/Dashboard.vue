<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import ExcelTable from '@/Components/ExcelTable.vue';
import ExcelRibbon from '@/Components/ExcelRibbon.vue';
import { ref, computed, onMounted, watch } from 'vue';
import axios from 'axios';

const vFocus = {
    mounted: (el) => el.focus()
};

const props = defineProps({
    sheets: Array,
    activeSheet: Object,
    initialData: Array,
});

const showPermissionsModal = ref(false);
const users = ref([]);
const assignedUsers = ref([]);

const openPermissionsModal = async () => {
    if (!props.activeSheet) return;
    const response = await axios.get(route('sheets.permissions', props.activeSheet.id));
    users.value = response.data.allUsers;
    assignedUsers.value = response.data.assignedUsers;
    showPermissionsModal.value = true;
};

const updateRole = async (userId, role) => {
    await axios.post(route('sheets.permissions.update', props.activeSheet.id), {
        user_id: userId,
        role: role
    });
    // Update local state
    const index = assignedUsers.value.findIndex(u => u.id === userId);
    if (role === 'none') {
        if (index > -1) assignedUsers.value.splice(index, 1);
    } else {
        if (index > -1) assignedUsers.value[index].role = role;
        else assignedUsers.value.push({ id: userId, role: role });
    }
};

const getUserRole = (userId) => {
    return assignedUsers.value.find(u => u.id === userId)?.role || 'none';
};

const currentCellData = ref({ value: '', position: '', rowIndex: null, colId: null, isUpdating: false });

// Состояние активной ячейки для ленты
const activeCellInfo = ref({
    rowIndex: null,
    colId: null,
    style: {}
});

const currentSelection = ref(null);
const tableApi = ref(null);

const handleTableReady = (api) => {
    tableApi.value = api;
};

const handleSelectionChanged = (selection) => {
    currentSelection.value = selection;
};

const handleCellFocused = (event) => {
    const { field, rowIndex, rowData, position, rawValue } = event;
    if (!field || rowIndex === null) return;
    
    activeCellInfo.value = {
        rowIndex,
        colId: field,
        rowData: rowData, // Сохраняем ссылку на объект
        style: rowData ? (rowData[field + '_style'] || {}) : {}
    };

    currentCellData.value.isUpdating = true;
    currentCellData.value = {
        value: rawValue || '',
        position: position || '',
        rowIndex: rowIndex,
        colId: field,
        isUpdating: false
    };
};

watch(() => currentCellData.value.value, (newValue) => {
    if (currentCellData.value.isUpdating) return;
    if (currentCellData.value.rowIndex !== null && currentCellData.value.colId !== null) {
        const row = props.initialData[currentCellData.value.rowIndex];
        if (row && row[currentCellData.value.colId] !== newValue) {
            row[currentCellData.value.colId] = newValue;
            
            // Debounced or immediate update
            handleCellValueChanged({ 
                data: row, 
                node: { rowIndex: currentCellData.value.rowIndex } 
            });
        }
    }
});

const columnDefs = computed(() => {
    if (props.activeSheet?.columns) {
        return props.activeSheet.columns;
    }
    return [
        { field: 'A', headerName: 'A' },
        { field: 'B', headerName: 'B' },
        { field: 'C', headerName: 'C' },
    ];
});

const editingSheetId = ref(null);
const newSheetName = ref('');
const tabContextMenu = ref({ show: false, x: 0, y: 0, sheet: null });

const startEditing = (sheet) => {
    editingSheetId.value = sheet.id;
    newSheetName.value = sheet.name;
};

const saveSheetName = (sheet) => {
    if (newSheetName.value.trim() && newSheetName.value !== sheet.name) {
        router.patch(route('sheets.update', sheet.id), { name: newSheetName.value });
    }
    editingSheetId.value = null;
};

const openTabContextMenu = (event, sheet) => {
    tabContextMenu.value = {
        show: true,
        x: event.clientX,
        y: event.clientY - 100, // Adjust for ribbon height
        sheet: sheet
    };
};

const closeContextMenu = () => {
    tabContextMenu.value.show = false;
};

const deleteSheet = (sheet) => {
    if (confirm(`Вы уверены, что хотите удалить лист "${sheet.name}"?`)) {
        router.delete(route('sheets.destroy', sheet.id));
    }
    closeContextMenu();
};

window.addEventListener('click', closeContextMenu);

const searchQuery = ref('');

const onSearch = () => {
    // This will be passed to ExcelTable via prop or ref
};

const handleCellValueChanged = (event) => {
    const { data, node, oldValue, column } = event;
    
    // Сохраняем старое значение для Undo, если это ручной ввод
    if (column && oldValue !== undefined) {
        const field = column.getColId();
        saveUndoState([{
            rowIndex: node.rowIndex,
            field,
            oldValue,
            oldStyle: data[field + '_style'] ? JSON.parse(JSON.stringify(data[field + '_style'])) : null
        }]);
    }
    
    // Проверка на корректность индекса строки
    const rowIndex = node?.rowIndex ?? node?.row_index;
    if (rowIndex === null || rowIndex === undefined) {
        console.error('Не удалось определить индекс строки для сохранения');
        return;
    }
    
    // Save data via Inertia
    router.post(route('sheets.updateData', props.activeSheet.id), {
        rows: [{
            row_index: rowIndex,
            data: data
        }]
    }, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => console.log('Данные сохранены'),
        onError: (errors) => console.error('Ошибка сохранения:', errors)
    });
};

const switchSheet = (id) => {
    router.get(route('dashboard'), { sheet_id: id }, {
        preserveState: true,
        preserveScroll: true
    });
};

import ExcelContextMenu from '@/Components/ExcelContextMenu.vue';
const cellContextMenu = ref({ show: false, x: 0, y: 0, params: null });

const handleCellContextMenu = (params) => {
    cellContextMenu.value = {
        show: true,
        x: params.event.clientX,
        y: params.event.clientY,
        params: params
    };
};

// Система Undo (Ctrl+Z)
const undoStack = ref([]);

const saveUndoState = (changes) => {
    // changes - это массив объектов { rowIndex, field, oldValue, oldStyle }
    undoStack.value.push(changes);
    if (undoStack.value.length > 50) undoStack.value.shift();
};

const undo = async () => {
    const changes = undoStack.value.pop();
    if (!changes) return;

    changes.forEach(action => {
        const row = props.initialData[action.rowIndex];
        if (row) {
            row[action.field] = action.oldValue;
            row[action.field + '_style'] = action.oldStyle;
            handleCellValueChanged({ data: row, node: { rowIndex: action.rowIndex } });
        }
    });
};

onMounted(() => {
    window.addEventListener('keydown', (e) => {
        if (e.ctrlKey && (e.key === 'z' || e.key === 'я')) {
            e.preventDefault();
            undo();
        }
    });
});

const handleMenuAction = async (action) => {
    const params = cellContextMenu.value.params;
    if (!params) return;

    const field = params.column.getColId();
    const rowIndex = params.node.rowIndex;
    const row = props.initialData[rowIndex];
    if (!row) return;

    // Сохраняем состояние ПЕРЕД изменением для Undo
    saveUndoState([{
        rowIndex,
        field,
        oldValue: row[field],
        oldStyle: row[field + '_style'] ? JSON.parse(JSON.stringify(row[field + '_style'])) : null
    }]);

    // Initialize style object if missing
    if (!row[field + '_style']) row[field + '_style'] = {};

    try {
        switch (action) {
            case 'copy':
                await navigator.clipboard.writeText(row[field] || '');
                break;
            case 'cut':
                await navigator.clipboard.writeText(row[field] || '');
                row[field] = '';
                handleCellValueChanged({ data: row, node: params.node });
                break;
            case 'paste':
                const text = await navigator.clipboard.readText();
                row[field] = text;
                handleCellValueChanged({ data: row, node: params.node });
                break;
            case 'bold':
                row[field + '_style'].fontWeight = row[field + '_style'].fontWeight === 'bold' ? 'normal' : 'bold';
                handleCellValueChanged({ data: row, node: params.node });
                break;
            case 'italic':
                row[field + '_style'].fontStyle = row[field + '_style'].fontStyle === 'italic' ? 'normal' : 'italic';
                handleCellValueChanged({ data: row, node: params.node });
                break;
            case 'clear':
                row[field] = '';
                handleCellValueChanged({ data: row, node: params.node });
                break;
            case 'delete':
                if (confirm('Удалить содержимое ячейки?')) {
                    handleMenuAction('clear');
                }
                break;
        }
        // Sync activeCellInfo if we changed style
        activeCellInfo.value.style = { ...row[field + '_style'] };
    } catch (err) {
        console.error('Ошибка буфера обмена:', err);
    }
};

const handleRibbonAction = ({ type, value }) => {
    let { rowIndex: activeRow, colId: activeCol, rowData: activeRowData } = activeCellInfo.value;
    
    if (!activeRowData && activeRow !== null) {
        activeRowData = props.initialData[activeRow];
    }

    if (!activeRowData || activeCol === null) return;

    // Определяем диапазон ячеек
    let targetRowsData = [activeRowData];
    let cols = [activeCol];

    if (currentSelection.value) {
        const { start, end } = currentSelection.value;
        const startRow = Math.min(start.row, end.row);
        const endRow = Math.max(start.row, end.row);
        const startCol = Math.min(start.col, end.col);
        const endCol = Math.max(start.col, end.col);
        
        targetRowsData = [];
        for (let r = startRow; r <= endRow; r++) {
            const node = tableApi.value?.getDisplayedRowAtIndex(r);
            if (node?.data) targetRowsData.push(node.data);
        }
        
        cols = [];
        const allCols = columnDefs.value;
        for (let c = startCol; c <= endCol; c++) {
            if (allCols[c]) cols.push(allCols[c].field);
        }
    }

    const getNumericSize = (s) => parseInt(s?.replace('px', '') || '11');
    const changes = [];
    
    // Сохраняем состояние для Undo
    targetRowsData.forEach(row => {
        cols.forEach(cId => {
            changes.push({
                dataRef: row, // Сохраняем ссылку на объект
                field: cId,
                oldValue: row[cId],
                oldStyle: row[cId + '_style'] ? JSON.parse(JSON.stringify(row[cId + '_style'])) : null
            });
        });
    });
    saveUndoState(changes);

    const updatedRows = [];
    const targetState = {
        bold: activeRowData[activeCol + '_style']?.fontWeight !== 'bold' ? 'bold' : 'normal',
        italic: activeRowData[activeCol + '_style']?.fontStyle !== 'italic' ? 'italic' : 'normal',
        underline: activeRowData[activeCol + '_style']?.textDecoration !== 'underline' ? 'underline' : 'none'
    };

    targetRowsData.forEach(row => {
        cols.forEach(cId => {
            if (!row[cId + '_style']) row[cId + '_style'] = {};
            const style = row[cId + '_style'];

            switch (type) {
                case 'bold': style.fontWeight = targetState.bold; break;
                case 'italic': style.fontStyle = targetState.italic; break;
                case 'underline': style.textDecoration = targetState.underline; break;
                case 'fontFamily': style.fontFamily = value; break;
                case 'fontSize': style.fontSize = value + 'px'; break;
                case 'color': style.color = value; break;
                case 'bgColor': style.backgroundColor = value; break;
                case 'textAlign': style.textAlign = value; break;
                case 'valign': style.verticalAlign = value; break;
                case 'fontSizeInc': style.fontSize = (getNumericSize(style.fontSize) + 1) + 'px'; break;
                case 'fontSizeDec': style.fontSize = Math.max(8, getNumericSize(style.fontSize) - 1) + 'px'; break;
                case 'clear': row[cId] = ''; break;
            }
        });
        
        let originalIndex = props.initialData.indexOf(row);
        if (originalIndex === -1) {
            originalIndex = props.initialData.findIndex(r => r.id === row.id);
        }

        if (originalIndex !== -1) {
            updatedRows.push({
                row_index: originalIndex,
                data: row
            });
        }
    });

    if (updatedRows.length > 0) {
        router.post(route('sheets.updateData', props.activeSheet.id), {
            rows: updatedRows
        }, { 
            preserveScroll: true, 
            preserveState: true,
            onSuccess: () => {
                tableApi.value?.redrawRows();
            }
        });
    }

    // Немедленная визуальная перерисовка
    setTimeout(() => {
        tableApi.value?.redrawRows();
    }, 0);
    
    // Обновляем стиль активной ячейки в ленте
    activeCellInfo.value.style = { ...(activeRowData[activeCol + '_style'] || {}) };
};
</script>

<template>
    <Head title="Excel Online" />

    <div class="h-screen w-screen flex flex-col overflow-hidden bg-[#f3f2f1] text-[#323130] font-sans">
        <!-- 1. Excel Top Brand Bar (Replaces Layout Header) -->
        <div class="bg-[#217346] text-white px-4 py-1 flex items-center justify-between text-xs h-9 shrink-0">
            <div class="flex items-center gap-4">
                <div class="flex items-center gap-1 font-bold">
                    <svg class="w-5 h-5" viewBox="0 0 24 24" fill="white"><path d="M16.2,2H7.8C6.8,2,6,2.8,6,3.8v16.4C6,21.2,6.8,22,7.8,22h8.4c1,0,1.8-0.8,1.8-1.8V3.8C18,2.8,17.2,2,16.2,2z M12,19 c-0.6,0-1-0.4-1-1s0.4-1,1-1s1,0.4,1,1S12.6,19,12,19z M15,16H9v-2h6V16z M15,12H9v-2h6V12z M15,8H9V6h6V8z"/></svg>
                    <span class="text-[14px]">Excel Online</span>
                </div>
                <div class="h-4 w-[1px] bg-white/30 mx-2"></div>
                <div class="hover:bg-white/10 px-2 py-1 rounded cursor-pointer text-sm font-medium">
                    {{ activeSheet?.name }} - Сохранено
                </div>
            </div>
            <div class="flex items-center gap-3">
                <button @click="openPermissionsModal" class="bg-white/10 hover:bg-white/20 px-3 py-1 rounded transition-colors">Права доступа</button>
                <div class="flex items-center gap-2">
                    <span class="text-sm font-medium">Admin</span>
                    <div class="w-8 h-8 bg-blue-600 rounded-full flex items-center justify-center font-bold border border-white/20">A</div>
                </div>
            </div>
        </div>
        
        <!-- New Excel Ribbon -->
        <ExcelRibbon @action="handleRibbonAction" :activeCell="activeCellInfo" />

        <div class="flex-1 flex flex-col bg-white overflow-hidden min-h-0">
            <!-- Modal for Permissions -->
            <div v-if="showPermissionsModal" class="fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center">
                <div class="bg-white rounded-lg shadow-xl w-[500px] max-h-[80vh] flex flex-col">
                    <div class="p-4 border-b flex justify-between items-center">
                        <h3 class="font-bold">Права доступа: {{ activeSheet.name }}</h3>
                        <button @click="showPermissionsModal = false" class="text-gray-500 hover:text-black">&times;</button>
                    </div>
                    <div class="p-4 overflow-y-auto flex-1">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="text-left border-b">
                                    <th class="pb-2">Пользователь</th>
                                    <th class="pb-2">Права</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="user in users" :key="user.id" class="border-b last:border-0">
                                    <td class="py-3">
                                        <div class="font-medium">{{ user.name }}</div>
                                        <div class="text-xs text-gray-500">{{ user.email }}</div>
                                    </td>
                                    <td class="py-3">
                                        <select 
                                            :value="getUserRole(user.id)" 
                                            @change="updateRole(user.id, $event.target.value)"
                                            class="text-xs border-gray-300 rounded p-1"
                                        >
                                            <option value="none">Нет доступа</option>
                                            <option value="viewer">Просмотр</option>
                                            <option value="editor">Редактирование</option>
                                        </select>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="p-4 border-t text-right">
                        <button @click="showPermissionsModal = false" class="px-4 py-2 bg-gray-200 rounded text-sm font-semibold">Закрыть</button>
                    </div>
                </div>
            </div>

                <!-- Formula Bar -->
                <div class="bg-white border-b border-gray-300 px-1 py-1 flex items-center gap-0 text-sm shrink-0">
                    <!-- Name Box -->
                    <div class="border-r border-gray-300 px-4 py-0.5 min-w-[80px] text-sm focus:outline-none" contenteditable="false">
                        {{ currentCellData.position }}
                    </div>
                    <!-- FX Buttons -->
                    <div class="flex items-center px-2 gap-2 border-r border-gray-300 h-full">
                        <button class="text-gray-400 hover:text-red-600 text-xs">✕</button>
                        <button class="text-gray-400 hover:text-green-600 text-xs">✓</button>
                        <div class="text-[#217346] font-serif italic font-bold px-1 cursor-pointer">fx</div>
                    </div>
                    <input 
                        v-model="currentCellData.value"
                        type="text" 
                        class="flex-1 border-none focus:ring-0 py-0.5 text-sm px-2"
                        placeholder=""
                    />
                </div>

            <!-- Main Grid Area -->
            <div class="overflow-hidden relative bg-white" style="height: calc(100vh - 200px);">
                <ExcelTable 
                    v-if="activeSheet"
                    :rowData="initialData" 
                    :columnDefs="columnDefs"
                    :quickFilter="searchQuery"
                    @cell-value-changed="handleCellValueChanged"
                    @cell-focused="handleCellFocused"
                    @cell-context-menu="handleCellContextMenu"
                    @selection-changed="handleSelectionChanged"
                    @ready="handleTableReady"
                />

                <ExcelContextMenu 
                    v-if="cellContextMenu.show"
                    :x="cellContextMenu.x"
                    :y="cellContextMenu.y"
                    :cellData="cellContextMenu.params"
                    @close="cellContextMenu.show = false"
                    @action="handleMenuAction"
                />
            </div>

            <!-- Excel Bottom Section -->
            <div class="flex flex-col select-none shrink-0 bg-[#f3f2f1]">
                <!-- 1. Tabs Bar -->
                <div class="bg-[#f3f2f1] border-t border-gray-300 flex items-center h-[32px] overflow-hidden">
                    <!-- Tab Navigation -->
                    <div class="flex items-center px-1 border-r border-gray-300 h-full">
                        <button class="hover:bg-gray-200 p-1 rounded text-gray-600 transition-colors">
                            <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><path d="M15.41 7.41L14 6l-6 6 6 6 1.41-1.41L10.83 12z"/></svg>
                        </button>
                        <button class="hover:bg-gray-200 p-1 rounded text-gray-600 transition-colors">
                            <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><path d="M10 6L8.59 7.41 13.17 12l-4.58 4.59L10 18l6-6z"/></svg>
                        </button>
                        <div class="text-gray-400 px-1 font-bold">...</div>
                    </div>

                    <!-- Tabs List -->
                    <div class="flex-1 flex items-center overflow-x-auto no-scrollbar h-full">
                        <div 
                            v-for="(sheet, index) in sheets" 
                            :key="sheet.id"
                            class="h-full flex items-center"
                        >
                            <div 
                                class="h-full flex items-center relative transition-colors"
                                :class="sheet.id === activeSheet?.id ? 'bg-white' : 'bg-transparent hover:bg-gray-200'"
                                @contextmenu.prevent="openTabContextMenu($event, sheet)"
                            >
                                <template v-if="editingSheetId === sheet.id">
                                    <input 
                                        v-model="newSheetName" 
                                        @blur="saveSheetName(sheet)"
                                        @keyup.enter="saveSheetName(sheet)"
                                        class="border-none focus:ring-0 outline-none px-4 py-0 w-24 text-[11px] font-bold bg-white h-full"
                                        v-focus
                                    />
                                </template>
                                <template v-else>
                                    <button 
                                        @click="router.visit(route('dashboard', { sheet_id: sheet.id }))"
                                        @dblclick="startEditing(sheet)"
                                        class="px-5 h-full text-[11px] whitespace-nowrap"
                                        :class="sheet.id === activeSheet?.id ? 'text-[#217346] font-bold shadow-[0_-2px_0_0_#217346_inset]' : 'text-gray-700'"
                                    >
                                        {{ sheet.name }}
                                    </button>
                                </template>
                            </div>
                            <!-- Separator Line -->
                            <div v-if="index < sheets.length - 1 && sheet.id !== activeSheet?.id && sheets[index+1].id !== activeSheet?.id" 
                                 class="h-4 w-[1px] bg-gray-400"></div>
                        </div>

                        <!-- Add Button -->
                        <button 
                            @click="router.post(route('sheets.store'))" 
                            class="ml-2 w-6 h-6 flex items-center justify-center rounded-full hover:bg-gray-300 text-gray-500 transition-colors"
                            title="Новый лист"
                        >
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" /></svg>
                        </button>
                    </div>

                    <!-- Right Side Controls (Status Icons) -->
                    <div class="px-4 flex items-center h-full border-l border-gray-300 gap-3">
                         <div class="text-[10px] text-gray-500 cursor-pointer hover:text-black">⋮</div>
                    </div>
                </div>

                <!-- 2. Status Bar (Real Bottom) -->
                <div class="bg-[#f3f2f1] border-t border-gray-200 flex items-center justify-between h-[22px] px-3 text-[11px] text-gray-600">
                    <div class="flex items-center gap-4">
                        <span class="hover:bg-gray-200 px-1 rounded cursor-pointer">Ввод</span>
                        <div class="w-[1px] h-3 bg-gray-300"></div>
                        <span class="hover:bg-gray-200 px-1 rounded cursor-pointer flex items-center gap-1">
                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            Доступность: проверка
                        </span>
                    </div>

                    <div class="flex items-center gap-4">
                        <!-- View Modes Icons -->
                        <div class="flex items-center gap-2">
                            <button class="p-0.5 hover:bg-gray-200 rounded" title="Обычный">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 10h18M3 6h18M3 14h18M3 18h18"/></svg>
                            </button>
                            <button class="p-0.5 hover:bg-gray-200 rounded" title="Разметка страницы">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 6h16M4 10h16M4 14h16M4 18h16"/></svg>
                            </button>
                        </div>
                        
                        <!-- Zoom Slider -->
                        <div class="flex items-center gap-2">
                            <span class="w-8 text-right">70%</span>
                            <button class="hover:text-black font-bold">－</button>
                            <div class="w-24 h-[1px] bg-gray-400 relative">
                                <div class="absolute left-[70%] top-[-5px] w-[2px] h-[11px] bg-gray-600"></div>
                            </div>
                            <button class="hover:text-black font-bold">＋</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<style>
body {
    overflow: hidden;
}

.no-scrollbar::-webkit-scrollbar {
    display: none;
}
.no-scrollbar {
    -ms-overflow-style: none;
    scrollbar-width: none;
}

/* Formula Bar Styles */
.formula-bar {
    display: flex;
    align-items: center;
    background: white;
    border-bottom: 1px solid #d2d0ce;
    height: 30px;
    font-size: 13px;
    flex-shrink: 0;
}
.address-box {
    width: 60px;
    text-align: center;
    border-right: 1px solid #d2d0ce;
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 500;
}
.formula-actions {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 0 10px;
    border-right: 1px solid #d2d0ce;
    height: 100%;
}
.btn-formula {
    background: transparent;
    border: none;
    cursor: pointer;
    font-size: 14px;
    color: #a19f9d;
    display: flex;
    align-items: center;
}
.btn-formula.cancel:hover { color: #d13438; }
.btn-formula.confirm:hover { color: #107c10; }
.btn-formula.fx { color: #107c10; font-style: italic; font-family: serif; font-weight: bold; }

.formula-input-wrapper {
    flex: 1;
    height: 100%;
}
.formula-input {
    width: 100%;
    height: 100%;
    border: none;
    padding: 0 10px;
    outline: none;
    font-size: 13px;
}
</style>

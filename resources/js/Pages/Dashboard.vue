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
const activeCellInfo = ref({ rowIndex: null, colId: null, rowData: null, style: {} });
const tableApi = ref(null);
const tableData = ref(JSON.parse(JSON.stringify(props.initialData))); // Глубокая копия для полной изоляции
const currentSelection = ref(null);

// Предохранитель для Undo/Redo
const isUndoing = ref(false);

// Внутренний буфер для копирования со стилями
const internalClipboard = ref(null);

// --- UNDO / REDO SYSTEM ---
const undoStack = ref([]);
const redoStack = ref([]);
const maxStackSize = 50;

const saveUndoState = (changes) => {
    redoStack.value = [];
    undoStack.value.push(changes);
    if (undoStack.value.length > maxStackSize) undoStack.value.shift();
};

const undo = () => {
    if (undoStack.value.length === 0 || isUndoing.value) return;
    
    isUndoing.value = true;
    const changes = undoStack.value.pop();
    const redoChanges = [];
    const affectedRowRefs = new Set();
    
    try {
        changes.forEach(change => {
            const { dataRef, field, oldValue, oldStyle } = change;
            redoChanges.push({
                dataRef, field,
                oldValue: dataRef[field],
                oldStyle: dataRef[field + '_style'] ? JSON.parse(JSON.stringify(dataRef[field + '_style'])) : null
            });
            dataRef[field] = oldValue;
            if (oldStyle !== undefined) {
                dataRef[field + '_style'] = oldStyle ? JSON.parse(JSON.stringify(oldStyle)) : null;
            }
            affectedRowRefs.add(dataRef);
        });
        redoStack.value.push(redoChanges);
        syncChangesToServer(Array.from(affectedRowRefs));
    } finally {
        setTimeout(() => { isUndoing.value = false; }, 100);
    }
};

const redo = () => {
    if (redoStack.value.length === 0 || isUndoing.value) return;
    
    isUndoing.value = true;
    const changes = redoStack.value.pop();
    const undoChanges = [];
    const affectedRowRefs = new Set();
    
    try {
        changes.forEach(change => {
            const { dataRef, field, oldValue, oldStyle } = change;
            undoChanges.push({
                dataRef, field,
                oldValue: dataRef[field],
                oldStyle: dataRef[field + '_style'] ? JSON.parse(JSON.stringify(dataRef[field + '_style'])) : null
            });
            dataRef[field] = oldValue;
            if (oldStyle !== undefined) {
                dataRef[field + '_style'] = oldStyle ? JSON.parse(JSON.stringify(oldStyle)) : null;
            }
            affectedRowRefs.add(dataRef);
        });
        undoStack.value.push(undoChanges);
        syncChangesToServer(Array.from(affectedRowRefs));
    } finally {
        setTimeout(() => { isUndoing.value = false; }, 100);
    }
};

const syncChangesToServer = (rows) => {
    const updatedRows = [];
    rows.forEach(row => {
        let idx = tableData.value.indexOf(row);
        if (idx === -1) idx = tableData.value.findIndex(r => r.id === row.id);
        if (idx !== -1) updatedRows.push({ row_index: idx, data: row });
    });

    if (updatedRows.length > 0) {
        router.post(route('sheets.updateData', props.activeSheet.id), { rows: updatedRows }, 
        { preserveScroll: true, preserveState: true, onSuccess: () => tableApi.value?.redrawRows() });
    }
    tableApi.value?.redrawRows();
};

const handleTableReady = (api) => { tableApi.value = api; };
const handleSelectionChanged = (selection) => { currentSelection.value = selection; };

const handleCellFocused = (event) => {
    const { field, rowIndex, rowData, position, rawValue } = event;
    if (!field || rowIndex === null) return;
    activeCellInfo.value = {
        rowIndex, colId: field, rowData: rowData,
        style: rowData ? (rowData[field + '_style'] || {}) : {}
    };
    currentCellData.value.isUpdating = true;
    currentCellData.value = { value: rawValue || '', position: position || '', rowIndex, colId: field, isUpdating: false };
};

watch(() => currentCellData.value.value, (newValue) => {
    if (currentCellData.value.isUpdating) return;
    if (currentCellData.value.rowIndex !== null && currentCellData.value.colId !== null) {
        const row = tableData.value[currentCellData.value.rowIndex];
        if (row && row[currentCellData.value.colId] !== newValue) {
            const oldValue = row[currentCellData.value.colId];
            row[currentCellData.value.colId] = newValue;
            handleCellValueChanged({ 
                data: row, 
                node: { rowIndex: currentCellData.value.rowIndex },
                column: { getColId: () => currentCellData.value.colId },
                oldValue: oldValue
            });
        }
    }
});

const handleCellValueChanged = (event) => {
    const { data, node, oldValue, column } = event;
    
    // НЕ сохраняем в Undo, если это само действие Undo/Redo
    if (column && oldValue !== undefined && !isUndoing.value) {
        const field = column.getColId();
        saveUndoState([{
            dataRef: data, field, oldValue,
            oldStyle: data[field + '_style'] ? JSON.parse(JSON.stringify(data[field + '_style'])) : null
        }]);
    }
    const rowIndex = node?.rowIndex ?? node?.row_index;
    if (rowIndex !== null && rowIndex !== undefined) {
        router.post(route('sheets.updateData', props.activeSheet.id), {
            rows: [{ row_index: rowIndex, data: data }]
        }, { preserveScroll: true, preserveState: true });
    }
};

const handleRibbonAction = ({ type, value }) => {
    let { rowIndex: activeRow, colId: activeCol, rowData: activeRowData } = activeCellInfo.value;
    if (!activeRowData && activeRow !== null) activeRowData = tableData.value[activeRow];
    if (!activeRowData || activeCol === null) return;

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
        for (let c = startCol; c <= endCol; c++) { if (allCols[c]) cols.push(allCols[c].field); }
    }

    // === Копирование / Вырезание ===
    if (type === 'copy' || type === 'cut') {
        const copyData = [];
        targetRowsData.forEach(row => {
            const rc = {};
            cols.forEach(cId => {
                rc[cId] = row[cId];
                rc[cId + '_style'] = row[cId + '_style'] ? JSON.parse(JSON.stringify(row[cId + '_style'])) : null;
            });
            copyData.push(rc);
        });
        internalClipboard.value = { data: copyData, cols: [...cols] };
        const text = targetRowsData.map(r => cols.map(c => r[c] || '').join('\t')).join('\n');
        navigator.clipboard.writeText(text).catch(() => {});
        if (type === 'cut') {
            const uc = [];
            targetRowsData.forEach(row => { cols.forEach(cId => { uc.push({ dataRef: row, field: cId, oldValue: row[cId], oldStyle: row[cId + '_style'] ? JSON.parse(JSON.stringify(row[cId + '_style'])) : null }); row[cId] = ''; }); });
            saveUndoState(uc);
            syncChangesToServer(targetRowsData);
        }
        return;
    }

    // === Вставка ===
    if (type === 'paste') {
        if (!internalClipboard.value?.data) {
            navigator.clipboard.readText().then(text => {
                if (text && activeRowData) {
                    saveUndoState([{ dataRef: activeRowData, field: activeCol, oldValue: activeRowData[activeCol], oldStyle: null }]);
                    activeRowData[activeCol] = text;
                    syncChangesToServer([activeRowData]);
                }
            }).catch(() => {});
            return;
        }
        const { data: clipData, cols: clipCols } = internalClipboard.value;
        const uc = [];
        const startColIdx = columnDefs.value.findIndex(c => c.field === activeCol);
        clipData.forEach((clipRow, ri) => {
            const tr = tableData.value[activeRow + ri];
            if (!tr) return;
            clipCols.forEach((cc, ci) => {
                const tf = columnDefs.value[startColIdx + ci]?.field;
                if (!tf) return;
                uc.push({ dataRef: tr, field: tf, oldValue: tr[tf], oldStyle: tr[tf + '_style'] ? JSON.parse(JSON.stringify(tr[tf + '_style'])) : null });
                tr[tf] = clipRow[cc];
                tr[tf + '_style'] = clipRow[cc + '_style'] ? JSON.parse(JSON.stringify(clipRow[cc + '_style'])) : null;
            });
        });
        saveUndoState(uc);
        const affected = [...new Set(clipData.map((_, ri) => tableData.value[activeRow + ri]).filter(Boolean))];
        syncChangesToServer(affected);
        return;
    }

    // === Вставить строку ===
    if (type === 'insertRow') {
        const newRow = {};
        columnDefs.value.forEach(col => { newRow[col.field] = ''; });
        const at = activeRow !== null ? activeRow + 1 : tableData.value.length;
        tableData.value.splice(at, 0, newRow);
        const allRows = tableData.value.map((r, i) => ({ row_index: i, data: r }));
        router.post(route('sheets.updateData', props.activeSheet.id), { rows: allRows }, { preserveScroll: true, preserveState: true });
        tableApi.value?.refreshCells({ force: true });
        return;
    }

    // === Удалить строку ===
    if (type === 'deleteRow') {
        if (activeRow === null || tableData.value.length <= 1) return;
        tableData.value.splice(activeRow, 1);
        const allRows = tableData.value.map((r, i) => ({ row_index: i, data: r }));
        router.post(route('sheets.updateData', props.activeSheet.id), { rows: allRows }, { preserveScroll: true, preserveState: true });
        tableApi.value?.refreshCells({ force: true });
        return;
    }

    // === Автосумма ===
    if (type === 'autosum') {
        if (activeRow > 0) {
            saveUndoState([{ dataRef: activeRowData, field: activeCol, oldValue: activeRowData[activeCol], oldStyle: null }]);
            activeRowData[activeCol] = `=SUM(${activeCol}1:${activeCol}${activeRow})`;
            syncChangesToServer([activeRowData]);
        }
        return;
    }

    // === Сортировка ===
    if (type === 'sort') {
        tableData.value.sort((a, b) => String(a[activeCol] || '').localeCompare(String(b[activeCol] || ''), 'ru'));
        const allRows = tableData.value.map((r, i) => ({ row_index: i, data: r }));
        router.post(route('sheets.updateData', props.activeSheet.id), { rows: allRows }, { preserveScroll: true, preserveState: true });
        tableApi.value?.refreshCells({ force: true });
        return;
    }

    // === Найти ===
    if (type === 'find') {
        const query = prompt('Найти:');
        if (!query) return;
        for (let ri = 0; ri < tableData.value.length; ri++) {
            for (const col of columnDefs.value) {
                if (String(tableData.value[ri][col.field] || '').toLowerCase().includes(query.toLowerCase())) {
                    tableApi.value?.ensureIndexVisible(ri);
                    tableApi.value?.setFocusedCell(ri, col.field);
                    return;
                }
            }
        }
        alert('Не найдено');
        return;
    }

    // === Стилевые действия ===
    // Capture Undo State
    const undoChanges = [];
    targetRowsData.forEach(row => {
        cols.forEach(cId => {
            undoChanges.push({
                dataRef: row, field: cId, oldValue: row[cId],
                oldStyle: row[cId + '_style'] ? JSON.parse(JSON.stringify(row[cId + '_style'])) : null
            });
        });
    });
    saveUndoState(undoChanges);

    const targetState = {
        bold: activeRowData[activeCol + '_style']?.fontWeight !== 'bold' ? 'bold' : 'normal',
        italic: activeRowData[activeCol + '_style']?.fontStyle !== 'italic' ? 'italic' : 'normal',
        underline: activeRowData[activeCol + '_style']?.textDecoration !== 'underline' ? 'underline' : 'none'
    };

    targetRowsData.forEach(row => {
        cols.forEach(cId => {
            if (!row[cId + '_style']) row[cId + '_style'] = {};
            const style = row[cId + '_style'];
            const getNumericSize = (s) => parseInt(s?.replace('px', '') || '11');
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
                case 'wrapText': style.whiteSpace = style.whiteSpace === 'normal' ? 'nowrap' : 'normal'; break;
                case 'fontSizeInc': style.fontSize = (getNumericSize(style.fontSize) + 1) + 'px'; break;
                case 'fontSizeDec': style.fontSize = Math.max(8, getNumericSize(style.fontSize) - 1) + 'px'; break;
                case 'clear': row[cId] = ''; break;
            }
        });
    });

    syncChangesToServer(targetRowsData);
    activeCellInfo.value.style = { ...(activeRowData[activeCol + '_style'] || {}) };
};

const handleRangeClear = ({ targetRows, colFields }) => {
    const undoChanges = [];
    targetRows.forEach(row => {
        colFields.forEach(field => {
            undoChanges.push({
                dataRef: row, field, oldValue: row[field],
                oldStyle: row[field + '_style'] ? JSON.parse(JSON.stringify(row[field + '_style'])) : null
            });
        });
    });
    saveUndoState(undoChanges);

    targetRows.forEach(row => {
        colFields.forEach(field => {
            row[field] = '';
        });
    });

    syncChangesToServer(targetRows);
};

const handleMenuAction = async (action) => {
    const params = cellContextMenu.value.params;
    if (!params) return;
    const field = params.column.getColId();
    const row = params.node.data;
    if (!row) return;

    // Если есть выделение, применяем действие к нему
    if (currentSelection.value && (action === 'clear' || action === 'bold' || action === 'italic')) {
        if (action === 'clear') {
            handleRangeClear({
                targetRows: tableData.value.slice(
                    Math.min(currentSelection.value.start.row, currentSelection.value.end.row),
                    Math.max(currentSelection.value.start.row, currentSelection.value.end.row) + 1
                ),
                colFields: columnDefs.value.slice(
                    Math.min(currentSelection.value.start.col, currentSelection.value.end.col),
                    Math.max(currentSelection.value.start.col, currentSelection.value.end.col) + 1
                ).map(c => c.field)
            });
            return;
        }
        // Для стилей вызываем handleRibbonAction
        handleRibbonAction(action);
        return;
    }

    // Одиночное действие (если нет диапазона)
    saveUndoState([{
        dataRef: row, field, oldValue: row[field],
        oldStyle: row[field + '_style'] ? JSON.parse(JSON.stringify(row[field + '_style'])) : null
    }]);

    try {
        switch (action) {
            case 'copy':
                internalClipboard.value = { value: row[field], style: row[field + '_style'] ? JSON.parse(JSON.stringify(row[field + '_style'])) : null };
                await navigator.clipboard.writeText(row[field] || '');
                break;
            case 'cut':
                internalClipboard.value = { value: row[field], style: row[field + '_style'] ? JSON.parse(JSON.stringify(row[field + '_style'])) : null };
                await navigator.clipboard.writeText(row[field] || '');
                row[field] = '';
                row[field + '_style'] = null;
                syncChangesToServer([row]);
                break;
            case 'paste':
                if (internalClipboard.value) {
                    row[field] = internalClipboard.value.value;
                    row[field + '_style'] = internalClipboard.value.style ? JSON.parse(JSON.stringify(internalClipboard.value.style)) : null;
                } else {
                    row[field] = await navigator.clipboard.readText();
                }
                syncChangesToServer([row]);
                break;
            case 'bold': 
                if (!row[field + '_style']) row[field + '_style'] = {};
                row[field + '_style'].fontWeight = row[field + '_style'].fontWeight === 'bold' ? 'normal' : 'bold'; 
                syncChangesToServer([row]);
                break;
            case 'italic': 
                if (!row[field + '_style']) row[field + '_style'] = {};
                row[field + '_style'].fontStyle = row[field + '_style'].fontStyle === 'italic' ? 'normal' : 'italic'; 
                syncChangesToServer([row]);
                break;
            case 'clear': 
                row[field] = ''; 
                syncChangesToServer([row]);
                break;
            case 'delete': if (confirm('Удалить содержимое ячейки?')) handleMenuAction('clear'); break;
        }
        activeCellInfo.value.style = { ...(row[field + '_style'] || {}) };
    } catch (err) { console.error(err); }
};

const columnDefs = computed(() => props.activeSheet?.columns || [{ field: 'A', headerName: 'A' }, { field: 'B', headerName: 'B' }, { field: 'C', headerName: 'C' }]);
const editingSheetId = ref(null);
const newSheetName = ref('');
const tabContextMenu = ref({ show: false, x: 0, y: 0, sheet: null });

const startEditing = (sheet) => { editingSheetId.value = sheet.id; newSheetName.value = sheet.name; };
const saveSheetName = (sheet) => {
    if (newSheetName.value.trim() && newSheetName.value !== sheet.name) router.patch(route('sheets.update', sheet.id), { name: newSheetName.value });
    editingSheetId.value = null;
};
const openTabContextMenu = (event, sheet) => { tabContextMenu.value = { show: true, x: event.clientX, y: event.clientY - 100, sheet: sheet }; };
const closeContextMenu = () => { tabContextMenu.value.show = false; };
const deleteSheet = (sheet) => { if (confirm(`Удалить лист "${sheet.name}"?`)) router.delete(route('sheets.destroy', sheet.id)); closeContextMenu(); };

import ExcelContextMenu from '@/Components/ExcelContextMenu.vue';
const cellContextMenu = ref({ show: false, x: 0, y: 0, params: null });
const handleCellContextMenu = (params) => { cellContextMenu.value = { show: true, x: params.event.clientX, y: params.event.clientY, params: params }; };

onMounted(() => {
    window.addEventListener('keydown', (e) => {
        if (!e.ctrlKey) return;
        const k = e.key.toLowerCase();
        // Undo / Redo
        if (k === 'z' || k === 'я') { e.preventDefault(); undo(); return; }
        if (k === 'y' || k === 'н') { e.preventDefault(); redo(); return; }
        // Copy / Cut / Paste
        if (k === 'c' || k === 'с') { e.preventDefault(); handleRibbonAction({ type: 'copy' }); return; }
        if (k === 'x' || k === 'ч') { e.preventDefault(); handleRibbonAction({ type: 'cut' }); return; }
        if (k === 'v' || k === 'м') { e.preventDefault(); handleRibbonAction({ type: 'paste' }); return; }
        // Bold / Italic / Underline
        if (k === 'b' || k === 'и') { e.preventDefault(); handleRibbonAction({ type: 'bold' }); return; }
        if (k === 'i' || k === 'ш') { e.preventDefault(); handleRibbonAction({ type: 'italic' }); return; }
        if (k === 'u' || k === 'г') { e.preventDefault(); handleRibbonAction({ type: 'underline' }); return; }
    });
});
</script>

<template>
    <Head title="Excel Online" />
    <div class="h-screen w-screen flex flex-col overflow-hidden bg-[#f3f2f1] text-[#323130] font-sans">
        <div class="bg-[#217346] text-white px-4 py-1 flex items-center justify-between text-xs h-9 shrink-0">
            <div class="flex items-center gap-4">
                <div class="flex items-center gap-1 font-bold">
                    <svg class="w-5 h-5" viewBox="0 0 24 24" fill="white"><path d="M16.2,2H7.8C6.8,2,6,2.8,6,3.8v16.4C6,21.2,6.8,22,7.8,22h8.4c1,0,1.8-0.8,1.8-1.8V3.8C18,2.8,17.2,2,16.2,2z M12,19 c-0.6,0-1-0.4-1-1s0.4-1,1-1s1,0.4,1,1S12.6,19,12,19z M15,16H9v-2h6V16z M15,12H9v-2h6V12z M15,8H9V6h6V8z"/></svg>
                    <span class="text-[14px]">Excel Online</span>
                </div>
                <div class="h-4 w-[1px] bg-white/30 mx-2"></div>
                <div class="hover:bg-white/10 px-2 py-1 rounded cursor-pointer text-sm font-medium">{{ activeSheet?.name }} - Сохранено</div>
            </div>
            <div class="flex items-center gap-3">
                <button @click="openPermissionsModal" class="bg-white/10 hover:bg-white/20 px-3 py-1 rounded transition-colors">Права доступа</button>
                <div class="flex items-center gap-2">
                    <span class="text-sm font-medium">Admin</span>
                    <div class="w-8 h-8 bg-blue-600 rounded-full flex items-center justify-center font-bold border border-white/20">A</div>
                </div>
            </div>
        </div>
        
        <ExcelRibbon @action="handleRibbonAction" :activeCell="activeCellInfo" />

        <div class="flex-1 flex flex-col bg-white overflow-hidden min-h-0">
            <div v-if="showPermissionsModal" class="fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center">
                <div class="bg-white rounded-lg shadow-xl w-[500px] max-h-[80vh] flex flex-col">
                    <div class="p-4 border-b flex justify-between items-center">
                        <h3 class="font-bold">Права доступа: {{ activeSheet.name }}</h3>
                        <button @click="showPermissionsModal = false" class="text-gray-500 hover:text-black">&times;</button>
                    </div>
                    <div class="p-4 overflow-y-auto flex-1">
                        <table class="w-full text-sm">
                            <thead><tr class="text-left border-b"><th class="pb-2">Пользователь</th><th class="pb-2">Права</th></tr></thead>
                            <tbody>
                                <tr v-for="user in users" :key="user.id" class="border-b last:border-0">
                                    <td class="py-3">
                                        <div class="font-medium">{{ user.name }}</div>
                                        <div class="text-xs text-gray-500">{{ user.email }}</div>
                                    </td>
                                    <td class="py-3">
                                        <select :value="getUserRole(user.id)" @change="updateRole(user.id, $event.target.value)" class="text-xs border-gray-300 rounded p-1">
                                            <option value="none">Нет доступа</option><option value="viewer">Просмотр</option><option value="editor">Редактирование</option>
                                        </select>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="p-4 border-t text-right"><button @click="showPermissionsModal = false" class="px-4 py-2 bg-gray-200 rounded text-sm font-semibold">Закрыть</button></div>
                </div>
            </div>

            <div class="bg-white border-b border-gray-300 px-1 py-1 flex items-center gap-0 text-sm shrink-0">
                <div class="border-r border-gray-300 px-4 py-0.5 min-w-[80px] text-sm">{{ currentCellData.position }}</div>
                <div class="flex items-center px-2 gap-2 border-r border-gray-300 h-full">
                    <button class="text-gray-400 hover:text-red-600 text-xs">✕</button><button class="text-gray-400 hover:text-green-600 text-xs">✓</button>
                    <div class="text-[#217346] font-serif italic font-bold px-1 cursor-pointer">fx</div>
                </div>
                <input v-model="currentCellData.value" type="text" class="flex-1 border-none focus:ring-0 py-0.5 text-sm px-2" />
            </div>

            <div class="overflow-hidden relative bg-white" style="height: calc(100vh - 200px);">
                <ExcelTable v-if="activeSheet" :rowData="tableData" :columnDefs="columnDefs"
                    @cell-value-changed="handleCellValueChanged" @cell-focused="handleCellFocused"
                    @cell-context-menu="handleCellContextMenu" @selection-changed="handleSelectionChanged" 
                    @range-clear="handleRangeClear" @ready="handleTableReady" />
                <ExcelContextMenu v-if="cellContextMenu.show" :x="cellContextMenu.x" :y="cellContextMenu.y" :cellData="cellContextMenu.params"
                    @close="cellContextMenu.show = false" @action="handleMenuAction" />
            </div>

            <div class="flex flex-col select-none shrink-0 bg-[#f3f2f1]">
                <div class="bg-[#f3f2f1] border-t border-gray-300 flex items-center h-[32px] overflow-hidden">
                    <div class="flex-1 flex items-center overflow-x-auto no-scrollbar h-full">
                        <div v-for="(sheet, index) in sheets" :key="sheet.id" class="h-full flex items-center">
                            <div class="h-full flex items-center relative transition-colors" :class="sheet.id === activeSheet?.id ? 'bg-white' : 'bg-transparent hover:bg-gray-200'" @contextmenu.prevent="openTabContextMenu($event, sheet)">
                                <input v-if="editingSheetId === sheet.id" v-model="newSheetName" @blur="saveSheetName(sheet)" @keyup.enter="saveSheetName(sheet)" class="border-none focus:ring-0 outline-none px-4 py-0 w-24 text-[11px] font-bold bg-white h-full" v-focus />
                                <button v-else @click="router.visit(route('dashboard', { sheet_id: sheet.id }))" @dblclick="startEditing(sheet)" class="px-5 h-full text-[11px] whitespace-nowrap" :class="sheet.id === activeSheet?.id ? 'text-[#217346] font-bold shadow-[0_-2px_0_0_#217346_inset]' : 'text-gray-700'">
                                    {{ sheet.name }}
                                </button>
                            </div>
                            <div v-if="index < sheets.length - 1 && sheet.id !== activeSheet?.id && sheets[index+1].id !== activeSheet?.id" class="h-4 w-[1px] bg-gray-400"></div>
                        </div>
                        <button @click="router.post(route('sheets.store'))" class="ml-2 w-6 h-6 flex items-center justify-center rounded-full hover:bg-gray-300 text-gray-500 transition-colors"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" /></svg></button>
                    </div>
                </div>
                <div class="bg-[#f3f2f1] border-t border-gray-200 flex items-center justify-between h-[22px] px-3 text-[11px] text-gray-600">
                    <div class="flex items-center gap-4"><span>Ввод</span><div class="w-[1px] h-3 bg-gray-300"></div><span>Доступность: проверка</span></div>
                    <div class="flex items-center gap-4"><span>70%</span><button>－</button><div class="w-24 h-[1px] bg-gray-400 relative"><div class="absolute left-[70%] top-[-5px] w-[2px] h-[11px] bg-gray-600"></div></div><button>＋</button></div>
                </div>
            </div>
        </div>
    </div>
</template>

<style>
body { overflow: hidden; }
.no-scrollbar::-webkit-scrollbar { display: none; }
.no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
</style>

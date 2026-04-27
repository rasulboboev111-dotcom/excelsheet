<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import ExcelTable from '@/Components/ExcelTable.vue';
import ExcelRibbon from '@/Components/ExcelRibbon.vue';
import ExcelContextMenu from '@/Components/ExcelContextMenu.vue';
import { ref, computed, onMounted, onUnmounted, watch } from 'vue';
import axios from 'axios';
import { useSheetMeta } from '@/Composables/useSheetMeta';
import { readXlsxFile, writeXlsxFile } from '@/Composables/xlsxIO';

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

const currentCellData = ref({ value: '', position: '', rowIndex: null, colId: null });
const isFormulaBarUpdating = ref(false);
const activeCellInfo = ref({ rowIndex: null, colId: null, rowData: null, style: {} });
const tableApi = ref(null);
const tableData = ref(JSON.parse(JSON.stringify(props.initialData))); // Глубокая копия для полной изоляции
const currentSelection = ref(null);

// Предохранитель для Undo/Redo
const isUndoing = ref(false);

// Внутренний буфер для копирования со стилями
const internalClipboard = ref(null);

// Состояние "Формат по образцу"
const pendingFormatPainter = ref(null);

// --- Sheet meta (merges, validations, colWidths, rowHeights, hidden) ---
const activeSheetId = computed(() => props.activeSheet?.id);
const { meta: sheetMeta, setMetaFor } = useSheetMeta(activeSheetId);

const handleColumnResized = ({ field, width }) => {
    sheetMeta.value.colWidths = { ...sheetMeta.value.colWidths, [field]: width };
};

const handleRowResized = ({ rowIndex, height }) => {
    sheetMeta.value.rowHeights = { ...sheetMeta.value.rowHeights, [rowIndex]: height };
};

// Статистика по выделению (Excel показывает в нижней панели). Лимит на скан, чтобы не лагать.
const STATS_MAX_CELLS = 200000;
const selectionStats = computed(() => {
    if (!currentSelection.value) return null;
    const { start, end } = currentSelection.value;
    const r1 = Math.min(start.row, end.row);
    const r2 = Math.max(start.row, end.row);
    const c1 = Math.min(start.col, end.col);
    const c2 = Math.max(start.col, end.col);
    const cols = columnDefs.value;
    let sum = 0, count = 0, numCount = 0, min = Infinity, max = -Infinity, scanned = 0;
    let truncated = false;
    outer:
    for (let r = r1; r <= r2; r++) {
        const row = tableData.value[r];
        if (!row) continue;
        for (let c = c1; c <= c2; c++) {
            if (++scanned > STATS_MAX_CELLS) { truncated = true; break outer; }
            const f = cols[c]?.field;
            if (!f) continue;
            const v = row[f];
            if (v === null || v === undefined || v === '') continue;
            count++;
            const n = parseFloat(v);
            if (!isNaN(n) && isFinite(n)) {
                sum += n; numCount++;
                if (n < min) min = n;
                if (n > max) max = n;
            }
        }
    }
    return {
        count, numCount, truncated,
        sum: numCount > 0 ? sum : null,
        avg: numCount > 0 ? sum / numCount : null,
        min: numCount > 0 ? min : null,
        max: numCount > 0 ? max : null
    };
});

const fmt = (n) => {
    if (n === null || n === undefined) return '';
    if (Math.abs(n) >= 1e6 || (n !== 0 && Math.abs(n) < 0.01)) return n.toExponential(2);
    return Number(n.toFixed(2)).toLocaleString('ru-RU');
};

const handleMergeCells = () => {
    if (!currentSelection.value) return;
    const { start, end } = currentSelection.value;
    const row = Math.min(start.row, end.row);
    const col = Math.min(start.col, end.col);
    const rowSpan = Math.abs(end.row - start.row) + 1;
    const colSpan = Math.abs(end.col - start.col) + 1;
    if (rowSpan === 1 && colSpan === 1) return;
    // Удаляем мерджи, попавшие в новый прямоугольник
    const merges = (sheetMeta.value.merges || []).filter(m => {
        const overlap = !(m.row + m.rowSpan <= row || m.row >= row + rowSpan || m.col + m.colSpan <= col || m.col >= col + colSpan);
        return !overlap;
    });
    merges.push({ row, col, rowSpan, colSpan });
    sheetMeta.value.merges = merges;
    tableApi.value?.refreshCells({ force: true });
    tableApi.value?.redrawRows();
};

const handleUnmergeCells = () => {
    if (!currentSelection.value) return;
    const { start, end } = currentSelection.value;
    const r1 = Math.min(start.row, end.row), r2 = Math.max(start.row, end.row);
    const c1 = Math.min(start.col, end.col), c2 = Math.max(start.col, end.col);
    sheetMeta.value.merges = (sheetMeta.value.merges || []).filter(m => {
        const overlap = !(m.row + m.rowSpan <= r1 || m.row > r2 || m.col + m.colSpan <= c1 || m.col > c2);
        return !overlap;
    });
    tableApi.value?.refreshCells({ force: true });
    tableApi.value?.redrawRows();
};

const handleSetValidation = () => {
    const { rowIndex, colId } = activeCellInfo.value;
    if (rowIndex === null || !colId) {
        alert('Сначала выделите ячейку в нужной колонке');
        return;
    }
    const existing = sheetMeta.value.validations?.[colId];
    const cur = Array.isArray(existing) ? existing.join(', ') : '';
    const input = prompt(`Список значений для колонки "${colId}" (через запятую). Пусто = убрать проверку.`, cur);
    if (input === null) return;
    const list = input.split(',').map(s => s.trim()).filter(Boolean);
    const v = { ...(sheetMeta.value.validations || {}) };
    if (list.length === 0) delete v[colId];
    else v[colId] = list;
    sheetMeta.value.validations = v;
};

// Версионный счётчик — увеличивается при любом изменении hidden-состояния,
// чтобы visibleSheets / isSheetHidden пересчитывались (Vue не отслеживает localStorage сам).
const hiddenVersion = ref(0);

const handleToggleHideSheet = (sheet) => {
    if (!sheet) return;
    const id = sheet.id;
    try {
        const k = `excel_sheet_meta_${id}`;
        const cur = JSON.parse(localStorage.getItem(k) || '{}');
        cur.hidden = !cur.hidden;
        localStorage.setItem(k, JSON.stringify(cur));
    } catch (_) {}
    hiddenVersion.value++;
    closeContextMenu();
    if (sheet.id === props.activeSheet?.id) router.visit(route('dashboard'));
};

const isSheetHidden = (sheetId) => {
    // eslint-disable-next-line no-unused-expressions
    hiddenVersion.value; // зависимость для реактивности
    try {
        const raw = localStorage.getItem(`excel_sheet_meta_${sheetId}`);
        if (!raw) return false;
        return !!JSON.parse(raw).hidden;
    } catch (_) { return false; }
};
const showHidden = ref(false);
const visibleSheets = computed(() => {
    // eslint-disable-next-line no-unused-expressions
    hiddenVersion.value;
    if (showHidden.value) return props.sheets || [];
    return (props.sheets || []).filter(s => s.id === props.activeSheet?.id || !isSheetHidden(s.id));
});

// --- Авто-расширение таблицы (как в Excel) ---
const ROW_INITIAL = 100;
const ROW_GROW = 50;
const COL_INITIAL = 26;
const COL_GROW = 10;
const MAX_ROWS = 1048576;
const MAX_COLS = 16384;

const extraRowCount = ref(Math.max(ROW_INITIAL, (props.initialData?.length ?? 0)));
const extraColCount = ref(Math.max(COL_INITIAL, (props.activeSheet?.columns?.length ?? 0)));

const colLetter = (idx) => {
    let s = '';
    let n = idx;
    while (true) {
        s = String.fromCharCode(65 + (n % 26)) + s;
        n = Math.floor(n / 26) - 1;
        if (n < 0) break;
    }
    return s;
};

const padTableData = () => {
    const target = Math.min(extraRowCount.value, MAX_ROWS);
    while (tableData.value.length < target) {
        tableData.value.push({});
    }
};
watch(extraRowCount, padTableData, { immediate: true });

const handleGrowRows = () => {
    if (extraRowCount.value < MAX_ROWS) {
        extraRowCount.value = Math.min(extraRowCount.value + ROW_GROW, MAX_ROWS);
    }
};
const handleGrowCols = () => {
    if (extraColCount.value < MAX_COLS) {
        extraColCount.value = Math.min(extraColCount.value + COL_GROW, MAX_COLS);
    }
};

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
        { preserveScroll: true, preserveState: true });
    }
    tableApi.value?.redrawRows();
};

const handleTableReady = (api) => { tableApi.value = api; };
const handleSelectionChanged = (selection) => {
    currentSelection.value = selection;
    // Применяем "Формат по образцу" к новому выделению
    if (pendingFormatPainter.value && selection) {
        applyFormatPainter(selection);
    }
};

const handleCellFocused = (event) => {
    const { field, rowIndex, rowData, position, rawValue } = event;
    if (!field || rowIndex === null) return;
    activeCellInfo.value = {
        rowIndex, colId: field, rowData: rowData,
        style: rowData ? (rowData[field + '_style'] || {}) : {}
    };
    isFormulaBarUpdating.value = true;
    currentCellData.value = { value: rawValue ?? '', position: position || '', rowIndex, colId: field };
};

watch(() => currentCellData.value.value, (newValue) => {
    if (isFormulaBarUpdating.value) {
        isFormulaBarUpdating.value = false;
        return;
    }
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

const applyFormatPainter = (selection) => {
    const sourceStyle = pendingFormatPainter.value;
    pendingFormatPainter.value = null;
    if (!sourceStyle) return;

    const { start, end } = selection;
    const startRow = Math.min(start.row, end.row);
    const endRow = Math.max(start.row, end.row);
    const startCol = Math.min(start.col, end.col);
    const endCol = Math.max(start.col, end.col);

    const targetRows = [];
    for (let r = startRow; r <= endRow; r++) {
        if (tableData.value[r]) targetRows.push(tableData.value[r]);
    }
    const allCols = columnDefs.value;
    const cols = [];
    for (let c = startCol; c <= endCol; c++) { if (allCols[c]) cols.push(allCols[c].field); }

    const uc = [];
    targetRows.forEach(row => {
        cols.forEach(cId => {
            uc.push({
                dataRef: row, field: cId, oldValue: row[cId],
                oldStyle: row[cId + '_style'] ? JSON.parse(JSON.stringify(row[cId + '_style'])) : null
            });
            row[cId + '_style'] = { ...(row[cId + '_style'] || {}), ...sourceStyle };
        });
    });
    if (uc.length > 0) {
        saveUndoState(uc);
        syncChangesToServer(targetRows);
    }
};

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
    let rowIndex = node?.rowIndex ?? node?.row_index;
    if (rowIndex == null || rowIndex < 0) {
        // fallback: ищем по ссылке в полном tableData
        rowIndex = tableData.value.indexOf(data);
    }
    if (rowIndex >= 0) {
        router.post(route('sheets.updateData', props.activeSheet.id), {
            rows: [{ row_index: rowIndex, data: data }]
        }, { preserveScroll: true, preserveState: true });
    }
};

const applyBorder = (style, type, edges) => {
    const thin = '1px solid #000';
    const thick = '2px solid #000';
    const dbl = '3px double #000';
    const { isTopEdge, isBottomEdge, isLeftEdge, isRightEdge } = edges;
    // Сбрасываем шорткат, чтобы не перекрывал индивидуальные стороны
    if (style.border) delete style.border;

    if (!type || type === 'none') {
        delete style.borderTop; delete style.borderBottom;
        delete style.borderLeft; delete style.borderRight;
        return;
    }
    if (type === 'all') {
        style.borderTop = thin; style.borderBottom = thin;
        style.borderLeft = thin; style.borderRight = thin;
        return;
    }
    if (type === 'outside') {
        if (isTopEdge) style.borderTop = thin;
        if (isBottomEdge) style.borderBottom = thin;
        if (isLeftEdge) style.borderLeft = thin;
        if (isRightEdge) style.borderRight = thin;
        return;
    }
    if (type === 'thickBox') {
        if (isTopEdge) style.borderTop = thick;
        if (isBottomEdge) style.borderBottom = thick;
        if (isLeftEdge) style.borderLeft = thick;
        if (isRightEdge) style.borderRight = thick;
        return;
    }
    if (type === 'top') { if (isTopEdge) style.borderTop = thin; return; }
    if (type === 'bottom') { if (isBottomEdge) style.borderBottom = thin; return; }
    if (type === 'left') { if (isLeftEdge) style.borderLeft = thin; return; }
    if (type === 'right') { if (isRightEdge) style.borderRight = thin; return; }
    if (type === 'bottomDouble') { if (isBottomEdge) style.borderBottom = dbl; return; }
    if (type === 'bottomThick') { if (isBottomEdge) style.borderBottom = thick; return; }
    if (type === 'topBottom') {
        if (isTopEdge) style.borderTop = thin;
        if (isBottomEdge) style.borderBottom = thin;
        return;
    }
    if (type === 'topThickBottom') {
        if (isTopEdge) style.borderTop = thin;
        if (isBottomEdge) style.borderBottom = thick;
        return;
    }
    if (type === 'topDoubleBottom') {
        if (isTopEdge) style.borderTop = thin;
        if (isBottomEdge) style.borderBottom = dbl;
        return;
    }
};

const handleRibbonAction = ({ type, value }) => {
    // Глобальные действия, которые не требуют активной ячейки
    if (type === 'import') { triggerImport(); return; }
    if (type === 'export') { exportXlsx(); return; }
    if (type === 'mergeCells') { handleMergeCells(); return; }
    if (type === 'unmergeCells') { handleUnmergeCells(); return; }
    if (type === 'setValidation') { handleSetValidation(); return; }
    if (type === 'toggleHidden') { showHidden.value = !showHidden.value; return; }
    if (type === 'freezeRow') { handleToggleFreezeRow(); return; }
    if (type === 'freezeCol') { handleToggleFreezeCol(); return; }
    if (type === 'findReplace') { openFindReplace(); return; }

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
            const row = tableData.value[r];
            if (row) targetRowsData.push(row);
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

    // === Формат по образцу ===
    if (type === 'formatPainter') {
        pendingFormatPainter.value = activeRowData[activeCol + '_style']
            ? JSON.parse(JSON.stringify(activeRowData[activeCol + '_style']))
            : {};
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

    // === Заполнить (Fill Down) ===
    if (type === 'fill') {
        if (targetRowsData.length > 1 && activeCol) {
            const topValue = targetRowsData[0][activeCol];
            const topStyle = targetRowsData[0][activeCol + '_style'] ? JSON.parse(JSON.stringify(targetRowsData[0][activeCol + '_style'])) : null;
            const uc = [];
            for (let i = 1; i < targetRowsData.length; i++) {
                const tr = targetRowsData[i];
                uc.push({ dataRef: tr, field: activeCol, oldValue: tr[activeCol], oldStyle: tr[activeCol + '_style'] ? JSON.parse(JSON.stringify(tr[activeCol + '_style'])) : null });
                tr[activeCol] = topValue;
                tr[activeCol + '_style'] = topStyle ? JSON.parse(JSON.stringify(topStyle)) : null;
            }
            saveUndoState(uc);
            syncChangesToServer(targetRowsData);
        }
        return;
    }

    // === Условное форматирование и таблицы (заглушки) ===
    if (type === 'conditional' || type === 'formatTable') {
        alert('Функция в разработке');
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

    const rowsLen = targetRowsData.length;
    const colsLen = cols.length;
    targetRowsData.forEach((row, ri) => {
        cols.forEach((cId, ci) => {
            if (!row[cId + '_style']) row[cId + '_style'] = {};
            const style = row[cId + '_style'];
            const getNumericSize = (s) => parseInt(s?.replace('px', '') || '11');
            const isTopEdge = ri === 0;
            const isBottomEdge = ri === rowsLen - 1;
            const isLeftEdge = ci === 0;
            const isRightEdge = ci === colsLen - 1;
            switch (type) {
                case 'applyCellStyle':
                    if (value === 'normal') {
                        style.backgroundColor = 'transparent'; style.color = '#000000'; delete style.border; style.fontWeight = 'normal'; style.fontStyle = 'normal'; style.fontSize = '11px';
                    } else if (value === 'neutral') {
                        style.backgroundColor = '#ffeb9c'; style.color = '#9c6500';
                    } else if (value === 'bad') {
                        style.backgroundColor = '#ffc7ce'; style.color = '#9c0006';
                    } else if (value === 'good') {
                        style.backgroundColor = '#c6efce'; style.color = '#006100';
                    } else if (value === 'input') {
                        style.backgroundColor = '#f2dddc'; style.color = '#e26b0a'; style.border = '1px solid #7f7f7f';
                    } else if (value === 'output') {
                        style.backgroundColor = '#f2f2f2'; style.color = '#3f3f3f'; style.border = '1px solid #3f3f3f'; style.fontWeight = 'bold';
                    } else if (value === 'calc') {
                        style.backgroundColor = '#ffffff'; style.color = '#fa7d00'; style.border = '1px solid #7f7f7f'; style.fontWeight = 'bold';
                    } else if (value === 'check') {
                        style.backgroundColor = '#a5a5a5'; style.color = '#ffffff'; style.border = '2px solid #3f3f3f'; style.fontWeight = 'bold';
                    } else if (value === 'explain') {
                        style.backgroundColor = 'transparent'; style.color = '#7f7f7f'; style.fontStyle = 'italic';
                    } else if (value === 'note') {
                        style.backgroundColor = '#ffffcc'; style.color = '#000000'; style.border = '1px solid #b2b2b2';
                    } else if (value === 'linked') {
                        style.backgroundColor = 'transparent'; style.color = '#fa7d00'; style.border = 'none'; style.borderBottom = '2px solid #ff8001';
                    } else if (value === 'warning') {
                        style.backgroundColor = 'transparent'; style.color = '#ff0000';
                    } else if (value === 'heading1') {
                        style.fontSize = '24px'; style.fontWeight = 'bold'; style.color = '#000000'; style.border = 'none'; style.borderBottom = '2px solid #4f81bd';
                    } else if (value === 'heading2') {
                        style.fontSize = '18px'; style.fontWeight = 'bold'; style.color = '#000000'; style.border = 'none'; style.borderBottom = '2px solid #4f81bd';
                    } else if (value === 'heading3') {
                        style.fontSize = '14px'; style.fontWeight = 'bold'; style.color = '#000000'; style.border = 'none'; style.borderBottom = '2px solid #a5b592';
                    } else if (value === 'heading4') {
                        style.fontSize = '12px'; style.fontWeight = 'bold'; style.color = '#000000';
                    } else if (value === 'total') {
                        style.fontWeight = 'bold'; style.border = 'none'; style.borderBottom = '3px double #4f81bd'; style.borderTop = '1px solid #4f81bd';
                    } else if (value === 'title') {
                        style.fontSize = '28px'; style.fontWeight = 'bold'; style.color = '#000000';
                    }
                    break;
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
                case 'border': applyBorder(style, value, { isTopEdge, isBottomEdge, isLeftEdge, isRightEdge }); break;
                case 'format': if (value) style.numberFormat = value; break;
                case 'precisionInc': style.decimals = (style.decimals !== undefined ? style.decimals : 2) + 1; break;
                case 'precisionDec': style.decimals = Math.max(0, (style.decimals !== undefined ? style.decimals : 2) - 1); break;
                case 'fontSizeInc': style.fontSize = (getNumericSize(style.fontSize) + 1) + 'px'; break;
                case 'fontSizeDec': style.fontSize = Math.max(8, getNumericSize(style.fontSize) - 1) + 'px'; break;
                case 'mergeCenter':
                    style.textAlign = 'center'; style.verticalAlign = 'middle';
                    // Если ячеек > 1 — делаем настоящий merge выделения
                    if (rowsLen > 1 || colsLen > 1) {
                        if (ri === 0 && ci === 0) {
                            // Запускаем merge только один раз (для top-left)
                            handleMergeCells();
                        }
                    }
                    break;
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
    const field = params.column?.getColId();
    const row = params.node?.data;
    if (!row || !field) return;

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
        // Для стилей вызываем handleRibbonAction с правильной сигнатурой
        handleRibbonAction({ type: action });
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
            case 'delete':
                if (confirm('Удалить содержимое ячейки?')) {
                    row[field] = '';
                    syncChangesToServer([row]);
                }
                break;
        }
        activeCellInfo.value.style = { ...(row[field + '_style'] || {}) };
    } catch (err) { console.error(err); }
};

const columnDefs = computed(() => {
    const baseCols = props.activeSheet?.columns;
    const baseDefs = (Array.isArray(baseCols) && baseCols.length > 0)
        ? baseCols.map(c => ({ ...c }))
        : [];
    const usedFields = new Set(baseDefs.map(c => c.field));
    const result = [...baseDefs];
    let i = 0;
    const target = Math.min(extraColCount.value, MAX_COLS);
    while (result.length < target && i < MAX_COLS) {
        const letter = colLetter(i);
        i++;
        if (usedFields.has(letter)) continue;
        result.push({ field: letter, headerName: letter });
    }
    // Запекаем сохранённые ширины прямо в colDef — переживут Inertia-обновления.
    // flex: 0 ОБЯЗАТЕЛЬНО (defaultColDef.flex=1 иначе перетрёт width).
    const widths = sheetMeta.value?.colWidths || {};
    const freezeCol = !!sheetMeta.value?.freezeCol;
    return result.map((c, idx) => {
        let cd = { ...c };
        const w = widths[c.field];
        if (typeof w === 'number' && w > 20) {
            cd = { ...cd, width: w, flex: 0, minWidth: 20, suppressSizeToFit: true };
        }
        if (freezeCol && idx === 0) cd.pinned = 'left';
        return cd;
    });
});

// Закрепление первой строки реализовано через rowData без её первой записи + pinnedTopRowData.
const tableDataForGrid = computed(() => {
    if (sheetMeta.value?.freezeRow) return tableData.value.slice(1);
    return tableData.value;
});
const pinnedTopRowData = computed(() => {
    if (sheetMeta.value?.freezeRow && tableData.value.length > 0) return [tableData.value[0]];
    return [];
});

const handleToggleFreezeRow = () => { sheetMeta.value.freezeRow = !sheetMeta.value.freezeRow; };
const handleToggleFreezeCol = () => { sheetMeta.value.freezeCol = !sheetMeta.value.freezeCol; };

// --- Find & Replace ---
const showFindReplace = ref(false);
const findText = ref('');
const replaceText = ref('');
const findCaseSensitive = ref(false);
const findLastPos = ref({ row: -1, col: -1 });
const findStatus = ref('');

const _matches = (cellVal, query) => {
    if (cellVal === null || cellVal === undefined || cellVal === '') return false;
    const a = String(cellVal);
    const b = query;
    return findCaseSensitive.value ? a.includes(b) : a.toLowerCase().includes(b.toLowerCase());
};

const findNext = () => {
    const q = findText.value;
    if (!q) { findStatus.value = 'Введите текст для поиска'; return; }
    const cols = columnDefs.value;
    const rows = tableData.value;
    let { row: lr, col: lc } = findLastPos.value;
    // Стартовая позиция — после последней найденной (или 0,0)
    let startR = lr, startC = lc + 1;
    if (startR < 0) { startR = 0; startC = 0; }
    for (let r = startR; r < rows.length; r++) {
        for (let c = (r === startR ? startC : 0); c < cols.length; c++) {
            const f = cols[c].field;
            if (_matches(rows[r][f], q)) {
                findLastPos.value = { row: r, col: c };
                tableApi.value?.ensureIndexVisible?.(r);
                tableApi.value?.setFocusedCell?.(r, f);
                findStatus.value = `Найдено: ${f}${r + 1}`;
                return { row: r, col: c, field: f };
            }
        }
    }
    // Wrap around — начнём с начала
    for (let r = 0; r < rows.length; r++) {
        for (let c = 0; c < cols.length; c++) {
            if (r > startR || (r === startR && c >= startC)) break;
            const f = cols[c].field;
            if (_matches(rows[r][f], q)) {
                findLastPos.value = { row: r, col: c };
                tableApi.value?.ensureIndexVisible?.(r);
                tableApi.value?.setFocusedCell?.(r, f);
                findStatus.value = `Найдено (с начала): ${f}${r + 1}`;
                return { row: r, col: c, field: f };
            }
        }
    }
    findStatus.value = 'Ничего не найдено';
    return null;
};

const _isFormula = (v) => typeof v === 'string' && v.startsWith('=');

const replaceCurrent = () => {
    const q = findText.value;
    if (!q) return;
    const { row, col } = findLastPos.value;
    if (row < 0 || col < 0) { findNext(); return; }
    const f = columnDefs.value[col]?.field;
    const r = tableData.value[row];
    if (!r || !f) return;
    if (_matches(r[f], q) && !_isFormula(r[f])) {
        const re = new RegExp(q.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), findCaseSensitive.value ? 'g' : 'gi');
        const oldVal = r[f];
        r[f] = String(oldVal).replace(re, replaceText.value);
        saveUndoState([{ dataRef: r, field: f, oldValue: oldVal, oldStyle: r[f + '_style'] ? JSON.parse(JSON.stringify(r[f + '_style'])) : null }]);
        syncChangesToServer([r]);
    }
    findNext();
};

const replaceAll = () => {
    const q = findText.value;
    if (!q) return;
    const cols = columnDefs.value;
    const rows = tableData.value;
    const re = new RegExp(q.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), findCaseSensitive.value ? 'g' : 'gi');
    const undoCh = [];
    const affected = new Set();
    let total = 0, skipped = 0;
    for (let r = 0; r < rows.length; r++) {
        for (let c = 0; c < cols.length; c++) {
            const f = cols[c].field;
            const v = rows[r][f];
            if (!_matches(v, q)) continue;
            if (_isFormula(v)) { skipped++; continue; } // не трогаем формулы
            undoCh.push({ dataRef: rows[r], field: f, oldValue: v, oldStyle: rows[r][f + '_style'] ? JSON.parse(JSON.stringify(rows[r][f + '_style'])) : null });
            rows[r][f] = String(v).replace(re, replaceText.value);
            affected.add(rows[r]);
            total++;
        }
    }
    if (total > 0) {
        saveUndoState(undoCh);
        syncChangesToServer(Array.from(affected));
    }
    findStatus.value = `Заменено: ${total}${skipped ? ` (пропущено формул: ${skipped})` : ''}`;
};

const openFindReplace = () => {
    showFindReplace.value = true;
    findLastPos.value = { row: -1, col: -1 };
    findStatus.value = '';
};
const editingSheetId = ref(null);
const newSheetName = ref('');
const tabContextMenu = ref({ show: false, x: 0, y: 0, sheet: null });

const startEditing = (sheet) => { editingSheetId.value = sheet.id; newSheetName.value = sheet.name; };
const saveSheetName = (sheet) => {
    const name = newSheetName.value.trim();
    if (name && name !== sheet.name) {
        router.patch(route('sheets.update', sheet.id), { name }, {
            onSuccess: () => { editingSheetId.value = null; },
            onError: () => { /* оставляем поле редактирования открытым */ }
        });
    } else {
        editingSheetId.value = null;
    }
};
const openTabContextMenu = (event, sheet) => { tabContextMenu.value = { show: true, x: event.clientX, y: event.clientY - 100, sheet: sheet }; };
const closeContextMenu = () => { tabContextMenu.value.show = false; };
const deleteSheet = (sheet) => { if (confirm(`Удалить лист "${sheet.name}"?`)) router.delete(route('sheets.destroy', sheet.id)); closeContextMenu(); };

const cellContextMenu = ref({ show: false, x: 0, y: 0, params: null });
const handleCellContextMenu = (params) => { cellContextMenu.value = { show: true, x: params.event.clientX, y: params.event.clientY, params: params }; };

const fileInput = ref(null);
const triggerImport = () => { fileInput.value?.click(); };

const handleFileChosen = async (e) => {
    const file = e.target.files?.[0];
    e.target.value = '';
    if (!file) return;
    try {
        const wb = await readXlsxFile(file);
        if (!wb.sheets || wb.sheets.length === 0) { alert('В файле не найдено листов'); return; }

        const sheetCount = wb.sheets.length;
        const ok = confirm(
            `В файле найдено листов: ${sheetCount}.\n` +
            `Будут созданы ${sheetCount} новых вкладок (текущие листы не затронутся).\n\nПродолжить?`
        );
        if (!ok) return;

        const created = [];
        let failed = 0;

        for (let i = 0; i < wb.sheets.length; i++) {
            const s = wb.sheets[i];
            try {
                const resp = await axios.post(route('sheets.importSheet'), {
                    name: s.name || `Лист ${i + 1}`,
                    columns: s.columnDefs || [],
                    rows: (s.rowData || []).map((r, j) => ({ row_index: j, data: r }))
                });
                const newId = resp?.data?.id;
                if (newId) {
                    setMetaFor(newId, {
                        merges: s.merges || [],
                        validations: s.validations || {},
                        colWidths: s.colWidths || {},
                        rowHeights: s.rowHeights || {},
                        hidden: !!s.hidden
                    });
                    created.push(newId);
                } else {
                    failed++;
                }
            } catch (err) {
                console.error(`Ошибка импорта листа "${s.name}":`, err);
                failed++;
            }
        }

        if (created.length === 0) {
            alert('Не удалось создать ни одного листа. Проверь консоль.');
            return;
        }

        const msg = failed > 0
            ? `Создано листов: ${created.length}. Ошибок: ${failed}.`
            : `Создано листов: ${created.length}.`;
        alert(msg);

        // Переходим на первый созданный лист — Inertia подхватит обновлённый список вкладок
        router.visit(route('dashboard', { sheet_id: created[0] }));
    } catch (err) {
        console.error(err);
        alert('Не удалось прочитать файл: ' + (err?.message || err));
    }
};

const exportXlsx = async () => {
    const sheetsForExport = (props.sheets || []).map(s => {
        const isActive = s.id === props.activeSheet?.id;
        const colsBase = (s.columns && s.columns.length) ? s.columns : columnDefs.value;
        const data = isActive ? tableData.value : [];
        let m;
        if (isActive) m = sheetMeta.value;
        else {
            try { m = JSON.parse(localStorage.getItem(`excel_sheet_meta_${s.id}`) || '{}'); }
            catch (_) { m = {}; }
        }
        return {
            name: s.name,
            hidden: !!m.hidden,
            columnDefs: colsBase,
            rowData: data,
            merges: m.merges || [],
            validations: m.validations || {},
            colWidths: m.colWidths || {},
            rowHeights: m.rowHeights || {}
        };
    });
    try {
        await writeXlsxFile('export.xlsx', sheetsForExport);
    } catch (err) {
        console.error(err);
        alert('Не удалось сохранить файл: ' + (err?.message || err));
    }
};

const handleGlobalKeydown = (e) => {
    // Не перехватываем хоткеи, когда пользователь печатает в поле ввода
    const tgt = e.target;
    const inField = tgt && (tgt.tagName === 'INPUT' || tgt.tagName === 'TEXTAREA' || tgt.isContentEditable);
    if (!e.ctrlKey) return;
    const k = e.key.toLowerCase();
    // Undo / Redo — работают всегда
    if (k === 'z' || k === 'я') { e.preventDefault(); undo(); return; }
    if (k === 'y' || k === 'н') { e.preventDefault(); redo(); return; }
    if (inField) return;
    // Copy / Cut / Paste
    if (k === 'c' || k === 'с') { e.preventDefault(); handleRibbonAction({ type: 'copy' }); return; }
    if (k === 'x' || k === 'ч') { e.preventDefault(); handleRibbonAction({ type: 'cut' }); return; }
    if (k === 'v' || k === 'м') { e.preventDefault(); handleRibbonAction({ type: 'paste' }); return; }
    // Bold / Italic / Underline
    if (k === 'b' || k === 'и') { e.preventDefault(); handleRibbonAction({ type: 'bold' }); return; }
    if (k === 'i' || k === 'ш') { e.preventDefault(); handleRibbonAction({ type: 'italic' }); return; }
    if (k === 'u' || k === 'г') { e.preventDefault(); handleRibbonAction({ type: 'underline' }); return; }
    if (k === 'h' || k === 'р') { e.preventDefault(); openFindReplace(); return; }
    if (k === 'f' || k === 'а') { e.preventDefault(); openFindReplace(); return; }
};

const handleGlobalClick = () => {
    if (tabContextMenu.value.show) closeContextMenu();
};

onMounted(() => {
    window.addEventListener('keydown', handleGlobalKeydown);
    window.addEventListener('click', handleGlobalClick);
});

onUnmounted(() => {
    window.removeEventListener('keydown', handleGlobalKeydown);
    window.removeEventListener('click', handleGlobalClick);
});
</script>

<template>
    <Head title="Excel Online" />
    <input type="file" ref="fileInput" accept=".xlsx,.xls" class="hidden" @change="handleFileChosen" />
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
                <ExcelTable v-if="activeSheet" :rowData="tableDataForGrid" :columnDefs="columnDefs"
                    :pinnedTopRowData="pinnedTopRowData" :freezeRow="!!sheetMeta.freezeRow"
                    :merges="sheetMeta.merges" :validations="sheetMeta.validations"
                    :colWidths="sheetMeta.colWidths" :rowHeights="sheetMeta.rowHeights"
                    @cell-value-changed="handleCellValueChanged" @cell-focused="handleCellFocused"
                    @cell-context-menu="handleCellContextMenu" @selection-changed="handleSelectionChanged"
                    @range-clear="handleRangeClear" @ready="handleTableReady"
                    @grow-rows="handleGrowRows" @grow-cols="handleGrowCols"
                    @column-resized="handleColumnResized" @row-resized="handleRowResized" />
                <ExcelContextMenu v-if="cellContextMenu.show" :x="cellContextMenu.x" :y="cellContextMenu.y" :cellData="cellContextMenu.params"
                    @close="cellContextMenu.show = false" @action="handleMenuAction" />
            </div>

            <div class="flex flex-col select-none shrink-0 bg-[#f3f2f1]">
                <div class="bg-[#f3f2f1] border-t border-gray-300 flex items-center h-[32px] overflow-hidden">
                    <div class="flex-1 flex items-center overflow-x-auto no-scrollbar h-full">
                        <div v-for="(sheet, index) in visibleSheets" :key="sheet.id" class="h-full flex items-center">
                            <div class="h-full flex items-center relative transition-colors" :class="[sheet.id === activeSheet?.id ? 'bg-white' : 'bg-transparent hover:bg-gray-200', isSheetHidden(sheet.id) ? 'opacity-50' : '']" @contextmenu.prevent="openTabContextMenu($event, sheet)">
                                <input v-if="editingSheetId === sheet.id" v-model="newSheetName" @blur="saveSheetName(sheet)" @keyup.enter="saveSheetName(sheet)" class="border-none focus:ring-0 outline-none px-4 py-0 w-24 text-[11px] font-bold bg-white h-full" v-focus />
                                <button v-else @click="router.visit(route('dashboard', { sheet_id: sheet.id }))" @dblclick="startEditing(sheet)" class="px-5 h-full text-[11px] whitespace-nowrap" :class="sheet.id === activeSheet?.id ? 'text-[#217346] font-bold shadow-[0_-2px_0_0_#217346_inset]' : 'text-gray-700'">
                                    {{ sheet.name }}<span v-if="isSheetHidden(sheet.id)" class="ml-1 text-[9px] text-gray-500">(скрыт)</span>
                                </button>
                            </div>
                            <div v-if="index < visibleSheets.length - 1 && sheet.id !== activeSheet?.id && visibleSheets[index+1].id !== activeSheet?.id" class="h-4 w-[1px] bg-gray-400"></div>
                        </div>
                        <button @click="router.post(route('sheets.store'))" class="ml-2 w-6 h-6 flex items-center justify-center rounded-full hover:bg-gray-300 text-gray-500 transition-colors" title="Добавить лист"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" /></svg></button>
                        <button @click="showHidden = !showHidden" class="ml-2 px-2 h-6 flex items-center justify-center rounded hover:bg-gray-300 text-gray-600 text-[10px]" title="Показать скрытые листы">{{ showHidden ? 'Скрыть скрытые' : 'Показать скрытые' }}</button>
                    </div>
                </div>
                <div class="bg-[#f3f2f1] border-t border-gray-200 flex items-center justify-between h-[22px] px-3 text-[11px] text-gray-600">
                    <div class="flex items-center gap-4"><span>Ввод</span><div class="w-[1px] h-3 bg-gray-300"></div><span>Доступность: проверка</span></div>
                    <div class="flex items-center gap-4 flex-1 justify-end pr-4">
                        <template v-if="selectionStats && selectionStats.count > 0">
                            <span v-if="selectionStats.numCount > 0">Среднее: <b>{{ fmt(selectionStats.avg) }}</b></span>
                            <span>Количество: <b>{{ selectionStats.count }}</b></span>
                            <span v-if="selectionStats.numCount > 0 && selectionStats.numCount !== selectionStats.count">Числовых: <b>{{ selectionStats.numCount }}</b></span>
                            <span v-if="selectionStats.numCount > 0">Мин: <b>{{ fmt(selectionStats.min) }}</b></span>
                            <span v-if="selectionStats.numCount > 0">Макс: <b>{{ fmt(selectionStats.max) }}</b></span>
                            <span v-if="selectionStats.numCount > 0">Сумма: <b>{{ fmt(selectionStats.sum) }}</b></span>
                        </template>
                    </div>
                    <div class="flex items-center gap-4"><span>70%</span><button>－</button><div class="w-24 h-[1px] bg-gray-400 relative"><div class="absolute left-[70%] top-[-5px] w-[2px] h-[11px] bg-gray-600"></div></div><button>＋</button></div>
                </div>
            </div>
        </div>

        <!-- Find & Replace -->
        <div v-if="showFindReplace" class="fixed inset-0 z-50 flex items-start justify-center pt-20 bg-black/20" @click.self="showFindReplace = false">
            <div class="bg-white rounded-lg shadow-xl w-[420px] p-4">
                <div class="flex justify-between items-center mb-3">
                    <h3 class="font-bold text-sm">Найти и заменить</h3>
                    <button @click="showFindReplace = false" class="text-gray-500 hover:text-black">&times;</button>
                </div>
                <div class="space-y-2">
                    <div class="flex items-center gap-2">
                        <label class="text-xs w-16 text-gray-600">Найти:</label>
                        <input v-model="findText" type="text" class="flex-1 border border-gray-300 rounded px-2 py-1 text-sm focus:outline-none focus:border-[#217346]" @keyup.enter="findNext()" />
                    </div>
                    <div class="flex items-center gap-2">
                        <label class="text-xs w-16 text-gray-600">Заменить:</label>
                        <input v-model="replaceText" type="text" class="flex-1 border border-gray-300 rounded px-2 py-1 text-sm focus:outline-none focus:border-[#217346]" />
                    </div>
                    <label class="flex items-center gap-2 text-xs text-gray-700">
                        <input type="checkbox" v-model="findCaseSensitive" /> Учитывать регистр
                    </label>
                    <div class="text-xs text-gray-500 min-h-[16px]">{{ findStatus }}</div>
                </div>
                <div class="flex gap-2 mt-3 justify-end">
                    <button @click="findNext()" class="px-3 py-1 text-sm rounded bg-gray-100 hover:bg-gray-200">Найти далее</button>
                    <button @click="replaceCurrent()" class="px-3 py-1 text-sm rounded bg-gray-100 hover:bg-gray-200">Заменить</button>
                    <button @click="replaceAll()" class="px-3 py-1 text-sm rounded bg-[#217346] text-white hover:bg-[#1a5d39]">Заменить все</button>
                    <button @click="showFindReplace = false" class="px-3 py-1 text-sm rounded bg-gray-100 hover:bg-gray-200">Закрыть</button>
                </div>
            </div>
        </div>

        <!-- Контекстное меню вкладок -->
        <div v-if="tabContextMenu.show" :style="{ top: tabContextMenu.y + 'px', left: tabContextMenu.x + 'px' }"
             class="fixed bg-white border border-gray-300 shadow-lg z-50 text-sm" @click.stop>
            <div class="px-3 py-1.5 hover:bg-gray-100 cursor-pointer" @click="startEditing(tabContextMenu.sheet); closeContextMenu()">Переименовать</div>
            <div class="px-3 py-1.5 hover:bg-gray-100 cursor-pointer" @click="handleToggleHideSheet(tabContextMenu.sheet)">{{ isSheetHidden(tabContextMenu.sheet?.id) ? 'Показать' : 'Скрыть' }}</div>
            <div class="px-3 py-1.5 hover:bg-gray-100 cursor-pointer text-red-600" @click="deleteSheet(tabContextMenu.sheet)">Удалить</div>
        </div>
    </div>
</template>

<style>
body { overflow: hidden; }
.no-scrollbar::-webkit-scrollbar { display: none; }
.no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
</style>

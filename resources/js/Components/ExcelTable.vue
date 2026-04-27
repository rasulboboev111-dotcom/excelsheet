<script setup>
import { ref, watch, onMounted, onUnmounted, computed } from 'vue';
import { AgGridVue } from 'ag-grid-vue3';
import { ModuleRegistry, AllCommunityModule } from 'ag-grid-community';
import { HyperFormula } from 'hyperformula';
import 'ag-grid-community/styles/ag-grid.css';
import 'ag-grid-community/styles/ag-theme-balham.css';

ModuleRegistry.registerModules([AllCommunityModule]);

const props = defineProps({
    rowData: { type: Array, default: () => [] },
    columnDefs: { type: Array, default: () => [] },
    readOnly: { type: Boolean, default: false },
    merges: { type: Array, default: () => [] },           // [{row,col,rowSpan,colSpan}]
    validations: { type: Object, default: () => ({}) },   // { [field]: [values] }
    colWidths: { type: Object, default: () => ({}) },     // { [field]: number }
    rowHeights: { type: Object, default: () => ({}) },    // { [rowIndex]: number }
    pinnedTopRowData: { type: Array, default: () => [] },
    freezeRow: { type: Boolean, default: false }
});

// Преобразует AG Grid (rowIndex, rowPinned) → абсолютный индекс в полном tableData
const toAbsRow = (rowIdx, rowPinned) => {
    if (rowPinned === 'top') return 0;
    if (rowPinned === 'bottom') return -1;
    if (rowIdx == null || rowIdx < 0) return -1;
    return props.freezeRow ? rowIdx + 1 : rowIdx;
};

// Общее количество абсолютных строк (rowData может быть sliced при freezeRow)
const totalAbsRows = () => (props.rowData?.length || 0) + (props.freezeRow ? 1 : 0);

const emit = defineEmits(['cell-value-changed', 'cell-focused', 'cell-context-menu', 'selection-changed', 'ready', 'range-clear', 'grow-rows', 'grow-cols', 'column-resized', 'row-resized']);
const gridApi = ref(null);

const hf = HyperFormula.buildEmpty({ licenseKey: 'gpl-v3' });

const onGridReady = (params) => {
    gridApi.value = params.api;
    emit('ready', params.api);
    setTimeout(() => { updateHFData(); }, 0);
};

// --- Тултип с размером во время ресайза ---
let resizeTooltipEl = null;
let lastMouseX = 0;
let lastMouseY = 0;
const trackMouse = (e) => { lastMouseX = e.clientX; lastMouseY = e.clientY; };
const ensureTooltip = () => {
    if (resizeTooltipEl) return;
    resizeTooltipEl = document.createElement('div');
    resizeTooltipEl.className = 'excel-resize-tooltip';
    document.body.appendChild(resizeTooltipEl);
};
const showTooltip = (text, x, y) => {
    ensureTooltip();
    resizeTooltipEl.textContent = text;
    resizeTooltipEl.style.left = (x + 14) + 'px';
    resizeTooltipEl.style.top = (y + 14) + 'px';
    resizeTooltipEl.style.display = 'block';
};
const hideTooltip = () => {
    if (resizeTooltipEl) resizeTooltipEl.style.display = 'none';
};

// --- Ресайз высоты строки (потяните за нижний край номера строки слева) ---
let resizingRowIdx = null;
let resizeStartY = 0;
let resizeStartH = 0;
let resizeCurrentH = 0;

const onRowResizeMove = (e) => {
    if (resizingRowIdx === null) return;
    const dy = e.clientY - resizeStartY;
    resizeCurrentH = Math.max(15, resizeStartH + dy);
    const node = gridApi.value?.getDisplayedRowAtIndex?.(resizingRowIdx);
    if (node && typeof node.setRowHeight === 'function') {
        node.setRowHeight(resizeCurrentH);
        gridApi.value?.onRowHeightChanged?.();
    }
    showTooltip(`Высота: ${Math.round(resizeCurrentH)}px`, e.clientX, e.clientY);
};

const onRowResizeEnd = () => {
    if (resizingRowIdx !== null && resizeCurrentH) {
        emit('row-resized', { rowIndex: resizingRowIdx, height: resizeCurrentH });
    }
    document.removeEventListener('mousemove', onRowResizeMove);
    document.removeEventListener('mouseup', onRowResizeEnd);
    document.body.style.cursor = '';
    resizingRowIdx = null;
    hideTooltip();
};

const startRowResize = (e, rowIdx) => {
    e.preventDefault();
    e.stopPropagation();
    resizingRowIdx = rowIdx;
    resizeStartY = e.clientY;
    resizeStartH = (props.rowHeights?.[rowIdx]) || 25;
    resizeCurrentH = resizeStartH;
    document.addEventListener('mousemove', onRowResizeMove);
    document.addEventListener('mouseup', onRowResizeEnd);
    document.body.style.cursor = 'row-resize';
};

const selectEntireRow = (rowIdx) => {
    const lastCol = (props.columnDefs?.length || 1) - 1;
    selectionStart.value = { row: rowIdx, col: 0 };
    selectionEnd.value = { row: rowIdx, col: Math.max(0, lastCol) };
    emit('selection-changed', { start: selectionStart.value, end: selectionEnd.value });
    gridApi.value?.refreshHeader();
    gridApi.value?.refreshCells({ suppressFlash: true });
};

const selectEntireColumn = (colIdx) => {
    const lastRow = totalAbsRows() - 1;
    selectionStart.value = { row: 0, col: colIdx };
    selectionEnd.value = { row: Math.max(0, lastRow), col: colIdx };
    emit('selection-changed', { start: selectionStart.value, end: selectionEnd.value });
    gridApi.value?.refreshHeader();
    gridApi.value?.refreshCells({ suppressFlash: true });
};

const selectAll = () => {
    const lastCol = (props.columnDefs?.length || 1) - 1;
    const lastRow = totalAbsRows() - 1;
    selectionStart.value = { row: 0, col: 0 };
    selectionEnd.value = { row: Math.max(0, lastRow), col: Math.max(0, lastCol) };
    emit('selection-changed', { start: selectionStart.value, end: selectionEnd.value });
    gridApi.value?.refreshHeader();
    gridApi.value?.refreshCells({ suppressFlash: true });
    // Excel-стиль: фокус на A1
    const f = props.columnDefs[0]?.field;
    if (f) gridApi.value?.setFocusedCell?.(0, f);
};

const rowNumCellRenderer = (params) => {
    const absR = toAbsRow(params.node?.rowIndex, params.node?.rowPinned);
    const wrap = document.createElement('div');
    wrap.className = 'excel-row-num-wrap';
    wrap.textContent = absR >= 0 ? String(absR + 1) : '';
    wrap.addEventListener('click', (e) => {
        if (e.target && e.target.classList && e.target.classList.contains('excel-row-resize-handle')) return;
        if (absR >= 0) selectEntireRow(absR);
    });
    const handle = document.createElement('div');
    handle.className = 'excel-row-resize-handle';
    handle.title = 'Потяните, чтобы изменить высоту строки';
    handle.addEventListener('mousedown', (e) => { if (absR >= 0) startRowResize(e, absR); });
    wrap.appendChild(handle);
    return wrap;
};

const onColumnResized = (event) => {
    if (!event || !event.column) return;
    const src = event.source;
    // Тултип во время drag'а (только для пользовательских ресайзов)
    if (!event.finished && src && src.startsWith('ui')) {
        const w = event.column.getActualWidth();
        showTooltip(`Ширина: ${Math.round(w)}px`, lastMouseX, lastMouseY);
    } else if (event.finished) {
        hideTooltip();
        if (src === 'api' || src === 'flex') return;
        const field = event.column.getColId();
        const w = event.column.getActualWidth();
        if (field && w) emit('column-resized', { field, width: w });
    }
};

const getRowHeight = (params) => {
    const h = props.rowHeights?.[params.node?.rowIndex];
    return (typeof h === 'number' && h > 0) ? h : null;
};

// Selection State
const selectionStart = ref(null);
const selectionEnd = ref(null);
const isSelecting = ref(false);

const updateHFData = () => {
    const sheetName = 'Sheet1';
    let sheetId = hf.getSheetId(sheetName);
    if (sheetId === undefined) {
        hf.addSheet(sheetName);
        sheetId = hf.getSheetId(sheetName);
    }
    if (typeof sheetId !== 'number') return;
    const fields = props.columnDefs.map(c => c.field);
    const hfData = props.rowData.map(row => fields.map(field => row[field] ?? ''));
    hf.setSheetContent(sheetId, hfData);
};

// Синхронизируем HyperFormula при любых внешних изменениях данных/колонок
watch(() => [props.rowData?.length, props.columnDefs?.length], () => {
    updateHFData();
    setTimeout(() => gridApi.value?.refreshCells({ force: true }), 0);
}, { flush: 'post' });

const getCellValue = (params) => {
    const value = params.data[params.colDef.field];
    if (typeof value === 'string' && value.startsWith('=')) {
        const sheetId = hf.getSheetId('Sheet1');
        const colIndex = props.columnDefs.findIndex(c => c.field === params.colDef.field);
        const rowIndex = params.node.rowIndex;
        try {
            const cellValue = hf.getCellValue({ sheet: sheetId, col: colIndex, row: rowIndex });
            return cellValue instanceof Error ? '#ERROR!' : cellValue;
        } catch (e) { return '#VALUE!'; }
    }
    return value;
};

const onCellFocused = (event) => {
    if (!event.column) return;
    const field = event.column.getColId();
    const rowIndex = event.rowIndex;
    const rowPinned = event.rowPinned ?? event.floating;
    const absR = toAbsRow(rowIndex, rowPinned);
    if (absR < 0) return;

    let rowData;
    if (rowPinned === 'top' && typeof gridApi.value?.getPinnedTopRow === 'function') {
        rowData = gridApi.value.getPinnedTopRow(rowIndex)?.data;
    } else {
        rowData = gridApi.value?.getDisplayedRowAtIndex?.(rowIndex)?.data;
    }
    const rawValue = rowData ? rowData[field] : '';

    // Авто-расширение
    if (absR >= totalAbsRows() - 10) emit('grow-rows');
    const colIdx = props.columnDefs.findIndex(c => c.field === field);
    if (colIdx >= 0 && colIdx >= props.columnDefs.length - 3) emit('grow-cols');

    gridApi.value?.refreshHeader();
    gridApi.value?.refreshCells({ columns: ['row_num'], force: true });

    emit('cell-focused', {
        ...event,
        field,
        rowIndex: absR,
        rawValue,
        position: `${field}${absR + 1}`,
        rowData
    });
};

const onCellContextMenu = (params) => {
    params.event.preventDefault();
    emit('cell-context-menu', params);
};

const onCellValueChanged = (event) => {
    updateHFData();
    setTimeout(() => gridApi.value?.refreshCells(), 0);
    const absR = toAbsRow(event.node?.rowIndex, event.node?.rowPinned);
    emit('cell-value-changed', {
        data: event.data,
        column: event.column,
        oldValue: event.oldValue,
        newValue: event.newValue,
        node: { rowIndex: absR }
    });
};

// Range Selection Logic
const onCellMouseDown = (params) => {
    isSelecting.value = true;
    const colIndex = props.columnDefs.findIndex(c => c.field === params.colDef.field);
    if (colIndex === -1) return; // pinned row-num column — клик обрабатывается через cellRenderer
    const absR = toAbsRow(params.node.rowIndex, params.node.rowPinned);
    if (absR < 0) return;
    selectionStart.value = { row: absR, col: colIndex, rowData: params.node.data };
    selectionEnd.value   = { row: absR, col: colIndex, rowData: params.node.data };

    onCellFocused({
        column: params.column,
        rowIndex: params.node.rowIndex,
        rowPinned: params.node.rowPinned,
        node: params.node
    });

    emit('selection-changed', { start: selectionStart.value, end: selectionEnd.value });
    gridApi.value?.refreshCells({ suppressFlash: true });
};

const onCellMouseOver = (params) => {
    if (!isSelecting.value) return;
    const colIndex = props.columnDefs.findIndex(c => c.field === params.colDef.field);
    if (colIndex === -1) return;
    const absR = toAbsRow(params.node.rowIndex, params.node.rowPinned);
    if (absR < 0) return;
    if (selectionEnd.value?.row !== absR || selectionEnd.value?.col !== colIndex) {
        selectionEnd.value = { row: absR, col: colIndex, rowData: params.node.data };

        gridApi.value?.refreshHeader();
        gridApi.value?.refreshCells({ columns: ['row_num'], force: true });

        emit('selection-changed', { start: selectionStart.value, end: selectionEnd.value });
        gridApi.value?.refreshCells({ suppressFlash: true });
    }
};

const onWindowMouseUp = () => { isSelecting.value = false; };
onMounted(() => {
    window.addEventListener('mouseup', onWindowMouseUp);
    window.addEventListener('mousemove', trackMouse);
});
onUnmounted(() => {
    window.removeEventListener('mouseup', onWindowMouseUp);
    window.removeEventListener('mousemove', trackMouse);
    document.removeEventListener('mousemove', onRowResizeMove);
    document.removeEventListener('mouseup', onRowResizeEnd);
    if (resizeTooltipEl) {
        resizeTooltipEl.remove();
        resizeTooltipEl = null;
    }
});

// Клик по букве колонки → выделить всю колонку. Клик по угловой пустой ячейке → выделить всё.
const onWrapperClick = (e) => {
    const target = e.target;
    if (!target) return;
    // Фильтры элементов внутри шапки, по которым НЕ выделяем колонку
    if (target.closest('.ag-header-cell-resize')) return;
    if (target.closest('.ag-header-icon')) return;
    if (target.closest('.ag-sort-indicator-container')) return;
    if (target.closest('.ag-header-cell-menu-button')) return;
    if (target.closest('.ag-filter-icon')) return;
    if (target.closest('.ag-floating-filter-button')) return;
    if (target.closest('.ag-header-expand-icon')) return;
    if (target.closest('.ag-checkbox-input-wrapper')) return;

    const headerCell = target.closest('.ag-header-cell');
    if (!headerCell) return;
    const colId = headerCell.getAttribute('col-id');
    if (!colId) return;
    const colIdx = props.columnDefs.findIndex(c => c.field === colId);
    if (colIdx >= 0) {
        selectEntireColumn(colIdx);
    } else {
        // Пустая шапка над номерами строк = выделить всё
        selectAll();
    }
};

let growRowsTimer = null;
let growColsTimer = null;

const onBodyScroll = () => {
    const api = gridApi.value;
    if (!api) return;

    // Вертикальный край → grow-rows
    const lastRowIdx = (api.getLastDisplayedRowIndex?.() ?? api.getLastDisplayedRow?.());
    const totalRows = props.rowData?.length ?? 0;
    if (lastRowIdx != null && totalRows > 0 && lastRowIdx >= totalRows - 10) {
        if (!growRowsTimer) {
            growRowsTimer = setTimeout(() => { emit('grow-rows'); growRowsTimer = null; }, 80);
        }
    }

    // Горизонтальный край → grow-cols
    try {
        if (typeof api.getHorizontalPixelRange === 'function') {
            const range = api.getHorizontalPixelRange();
            const cols = (api.getAllDisplayedColumns?.() || []).filter(c => !c.getColDef?.().pinned);
            const totalW = cols.reduce((sum, c) => sum + (c.getActualWidth?.() || 0), 0);
            if (totalW > 0 && range && range.right >= totalW - 200) {
                if (!growColsTimer) {
                    growColsTimer = setTimeout(() => { emit('grow-cols'); growColsTimer = null; }, 80);
                }
            }
        }
    } catch (e) { /* API может отличаться между версиями ag-grid */ }
};

const onGridKeyDown = (e) => {
    handleKeyDown(e);
};

const handleKeyDown = (e) => {
    // 1. Очистка диапазона по клавише Delete или Backspace
    if (e.key === 'Delete' || e.key === 'Del' || e.key === 'Backspace') {
        if (!selectionStart.value || !selectionEnd.value) return;
        e.preventDefault();
        e.stopPropagation();
        
        const sR = Math.min(selectionStart.value.row, selectionEnd.value.row);
        const eR = Math.max(selectionStart.value.row, selectionEnd.value.row);
        const sC = Math.min(selectionStart.value.col, selectionEnd.value.col);
        const eC = Math.max(selectionStart.value.col, selectionEnd.value.col);

        const updatedRows = [];
        for (let r = sR; r <= eR; r++) {
            const node = gridApi.value?.getDisplayedRowAtIndex(r);
            if (node?.data) {
                updatedRows.push({ row_index: r, data: node.data });
            }
        }

        if (updatedRows.length > 0) {
            emit('range-clear', {
                targetRows: updatedRows.map(info => info.data),
                colFields: Array.from(new Set(updatedRows.flatMap(info => {
                    const fields = [];
                    for (let c = sC; c <= eC; c++) {
                        const f = props.columnDefs[c]?.field;
                        if (f) fields.push(f);
                    }
                    return fields;
                })))
            });
        }
        return;
    }

    // 2. Навигация с Shift (абсолютные индексы)
    if (e.shiftKey && ['ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight'].includes(e.key)) {
        if (!selectionStart.value || !selectionEnd.value) return;
        e.preventDefault();

        let { row, col } = selectionEnd.value;
        const total = totalAbsRows();
        if (e.key === 'ArrowUp')    row = Math.max(0, row - 1);
        if (e.key === 'ArrowDown')  row = Math.min(total - 1, row + 1);
        if (e.key === 'ArrowLeft')  col = Math.max(0, col - 1);
        if (e.key === 'ArrowRight') col = Math.min(props.columnDefs.length - 1, col + 1);

        selectionEnd.value = { row, col, rowData: null };
        emit('selection-changed', { start: selectionStart.value, end: selectionEnd.value });
        gridApi.value?.refreshCells({ suppressFlash: true });
    }
};

// Cache column indices for performance
const colIndexMap = computed(() => {
    const map = {};
    props.columnDefs.forEach((c, i) => map[c.field] = i);
    return map;
});

const isCellSelected = (params) => {
    if (!selectionStart.value || !selectionEnd.value) return false;
    const absR = toAbsRow(params.node.rowIndex, params.node.rowPinned);
    if (absR < 0) return false;
    const colIndex = colIndexMap.value[params.colDef.field];
    if (colIndex === undefined) return false;
    const sR = Math.min(selectionStart.value.row, selectionEnd.value.row);
    const eR = Math.max(selectionStart.value.row, selectionEnd.value.row);
    const sC = Math.min(selectionStart.value.col, selectionEnd.value.col);
    const eC = Math.max(selectionStart.value.col, selectionEnd.value.col);
    return absR >= sR && absR <= eR && colIndex >= sC && colIndex <= eC;
};

const isCellActive = (params) => {
    if (!selectionStart.value) return false;
    const absR = toAbsRow(params.node.rowIndex, params.node.rowPinned);
    return absR >= 0
        && selectionStart.value.row === absR
        && props.columnDefs[selectionStart.value.col]?.field === params.colDef.field;
};

const isCellOnEdge = (params, edge) => {
    if (!selectionStart.value || !selectionEnd.value) return false;
    const absR = toAbsRow(params.node.rowIndex, params.node.rowPinned);
    if (absR < 0) return false;
    const colIndex = colIndexMap.value[params.colDef.field];
    if (colIndex === undefined) return false;
    const sR = Math.min(selectionStart.value.row, selectionEnd.value.row);
    const eR = Math.max(selectionStart.value.row, selectionEnd.value.row);
    const sC = Math.min(selectionStart.value.col, selectionEnd.value.col);
    const eC = Math.max(selectionStart.value.col, selectionEnd.value.col);
    if (edge === 'top')    return absR === sR && colIndex >= sC && colIndex <= eC;
    if (edge === 'bottom') return absR === eR && colIndex >= sC && colIndex <= eC;
    if (edge === 'left')   return colIndex === sC && absR >= sR && absR <= eR;
    if (edge === 'right')  return colIndex === eC && absR >= sR && absR <= eR;
    if (edge === 'corner') return absR === eR && colIndex === eC;
    return false;
};

const cellClassRules = {
    'excel-range-selected': (p) => isCellSelected(p),
    'excel-active-cell':    (p) => isCellActive(p),
    'excel-range-top':      (p) => isCellOnEdge(p, 'top'),
    'excel-range-bottom':   (p) => isCellOnEdge(p, 'bottom'),
    'excel-range-left':     (p) => isCellOnEdge(p, 'left'),
    'excel-range-right':    (p) => isCellOnEdge(p, 'right'),
    'excel-range-corner':   (p) => isCellOnEdge(p, 'corner'),
};

// Excel-serial → JS Date (1900-01-01 + N days, accounting for the 1900 leap-year bug)
const excelSerialToDate = (serial) => {
    const utcDays = serial - 25569;
    const ms = utcDays * 86400 * 1000;
    return new Date(ms);
};

// Find merge that COVERS this cell (but is not the top-left). Such cells should not render.
const findCoveringMerge = (rowIndex, colIndex) => {
    for (const m of (props.merges || [])) {
        if (rowIndex >= m.row && rowIndex < m.row + m.rowSpan
            && colIndex >= m.col && colIndex < m.col + m.colSpan) {
            if (rowIndex === m.row && colIndex === m.col) return null; // top-left renders normally
            return m;
        }
    }
    return null;
};

const defaultColDef = {
    flex: 1, minWidth: 100, filter: true, sortable: false, resizable: true,
    wrapText: true,
    // autoHeight несовместим с rowSpan (merges) — поэтому ставим только wrapText.
    // Для длинных шапок используются rowHeights из импорта или ручной ресайз.
    editable: (params) => !props.readOnly,
    valueGetter: getCellValue,
    valueFormatter: (params) => {
        const val = params.value;
        if (val === null || val === undefined || val === '') return val;
        const style = params.data?.[params.colDef.field + '_style'] || {};

        const num = parseFloat(val);

        // Date format: convert Excel-serial → human date
        if (style.numberFormat === 'shortDate' && !isNaN(num) && num > 0 && num < 2958466) {
            const d = excelSerialToDate(num);
            if (!isNaN(d)) return d.toLocaleDateString('ru-RU');
        }

        if (isNaN(num)) return val;

        let formatted = val;
        if (style.decimals !== undefined) {
            formatted = num.toFixed(style.decimals);
        } else if (style.numberFormat === 'currency' || style.numberFormat === 'percentage') {
            formatted = num.toFixed(2);
        }
        if (style.numberFormat === 'currency') formatted = '$' + formatted;
        if (style.numberFormat === 'percent' || style.numberFormat === 'percentage') formatted = formatted + '%';
        if (style.numberFormat === 'comma') {
            const parts = String(formatted).split('.');
            parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ',');
            formatted = parts.join('.');
        }
        return formatted;
    },
    valueParser: (params) => params.newValue,
    headerClass: 'excel-column-header',
    suppressKeyboardEvent: (params) => {
        const key = params.event.key;
        if (key === 'Delete' || key === 'Del' || key === 'Backspace') return true;
        return false;
    },
    cellStyle: (params) => {
        if (!params.data || !params.colDef.field) return null;
        const base = params.data[params.colDef.field + '_style'] || null;
        // Скрываем ячейки, попавшие внутрь merge (но не top-left)
        const colIdx = colIndexMap.value[params.colDef.field];
        if (colIdx === undefined) return base;
        const cov = findCoveringMerge(params.node.rowIndex, colIdx);
        if (cov) return { ...(base || {}), display: 'none' };
        return base;
    },
    colSpan: (params) => {
        const colIdx = colIndexMap.value[params.colDef.field];
        if (colIdx === undefined) return 1;
        const m = (props.merges || []).find(m => m.row === params.node.rowIndex && m.col === colIdx);
        return m ? m.colSpan : 1;
    },
    rowSpan: (params) => {
        const colIdx = colIndexMap.value[params.colDef.field];
        if (colIdx === undefined) return 1;
        const m = (props.merges || []).find(m => m.row === params.node.rowIndex && m.col === colIdx);
        return m ? m.rowSpan : 1;
    },
    cellEditorSelector: (params) => {
        const list = props.validations?.[params.colDef.field];
        if (Array.isArray(list) && list.length > 0) {
            return { component: 'agSelectCellEditor', params: { values: list } };
        }
        return undefined;
    }
};

// Этот computed зависит ТОЛЬКО от props.columnDefs — не пересоздаётся при выборе ячеек,
// поэтому ag-grid сохраняет пользовательскую ширину колонок при кликах.
// Подсветка заголовка делается через headerClass-функцию, которая читает selectionStart/End
// лениво в момент рендера (refreshHeader перевызывает её при изменении выделения).
const finalColumnDefs = computed(() => {
    const rowNumCol = {
        headerName: '',
        valueGetter: "node.rowIndex + 1",
        width: 45, minWidth: 45, maxWidth: 45,
        pinned: 'left',
        editable: false,
        sortable: false,
        filter: false,
        suppressSizeToFit: true,
        cellRenderer: rowNumCellRenderer,
        cellClassRules: {
            'excel-row-number-cell': () => true,
            'excel-header-highlight': (params) => {
                if (!selectionStart.value || !selectionEnd.value) return false;
                const sR = Math.min(selectionStart.value.row, selectionEnd.value.row);
                const eR = Math.max(selectionStart.value.row, selectionEnd.value.row);
                return params.node.rowIndex >= sR && params.node.rowIndex <= eR;
            }
        }
    };

    const mainCols = props.columnDefs.map((col) => ({
        ...col,
        headerClass: (params) => {
            const baseClass = typeof col.headerClass === 'string' ? col.headerClass : '';
            if (!selectionStart.value || !selectionEnd.value) return baseClass;
            const idx = props.columnDefs.findIndex(c => c.field === params.column.getColId());
            if (idx === -1) return baseClass;
            const sC = Math.min(selectionStart.value.col, selectionEnd.value.col);
            const eC = Math.max(selectionStart.value.col, selectionEnd.value.col);
            return idx >= sC && idx <= eC
                ? ('excel-header-highlight ' + baseClass).trim()
                : baseClass;
        },
        cellClassRules: cellClassRules
    }));

    return [rowNumCol, ...mainCols];
});
</script>

<template>
    <div class="ag-theme-balham" style="width: 100%; height: 100%;" @contextmenu.prevent @click="onWrapperClick">
        <ag-grid-vue
            style="width: 100%; height: 100%;"
            theme="legacy"
            :columnDefs="finalColumnDefs"
            :rowData="rowData"
            :pinnedTopRowData="pinnedTopRowData"
            :defaultColDef="defaultColDef"
            @grid-ready="onGridReady"
            @cell-value-changed="onCellValueChanged"
            @cell-focused="onCellFocused"
            @cell-mouse-down="onCellMouseDown"
            @cell-mouse-over="onCellMouseOver"
            @cell-context-menu="onCellContextMenu"
            @body-scroll="onBodyScroll"
            @column-resized="onColumnResized"
            @keydown="onGridKeyDown"
            :animateRows="true"
            :headerHeight="24"
            :rowHeight="25"
            :getRowHeight="getRowHeight"
        />
    </div>
</template>

<style>
/* === EXCEL-LIKE THEME === */
.ag-theme-balham {
    --ag-selected-row-background-color: transparent !important;
    --ag-header-background-color: #f3f3f3;
    --ag-header-foreground-color: #444;
    --ag-border-color: #d4d4d4;
    --ag-row-border-color: #d4d4d4;
    --ag-cell-horizontal-border: solid 1px #d4d4d4;
    --ag-grid-size: 2px;
    --ag-font-size: 11px;
    --ag-font-family: Calibri, 'Segoe UI', Tahoma, sans-serif;
    --ag-row-hover-color: rgba(0, 0, 0, 0.02);
    user-select: none;
    background: #fff;
}

/* Excel column header — clean grey */
.ag-theme-balham .ag-header {
    border-bottom: 1px solid #d4d4d4;
    background: #f3f3f3;
}
.ag-theme-balham .ag-header-cell {
    border-right: 1px solid #d4d4d4;
    font-weight: 400;
    color: #444;
    cursor: pointer;
}
.ag-theme-balham .ag-header-cell:hover {
    background: #e8e8e8;
}

/* Selected column header — green underline like real Excel */
.ag-theme-balham .excel-header-highlight,
.ag-theme-balham .ag-header-cell.excel-header-highlight {
    background-color: #d4dfd6 !important;
    color: #107c41 !important;
    font-weight: 600 !important;
    box-shadow: inset 0 -2px 0 0 #107c41;
}

/* Selection range — Excel light-green tint */
.ag-theme-balham .excel-range-selected {
    background-color: rgba(16, 124, 65, 0.06) !important;
    z-index: 2 !important;
}
.ag-theme-balham .excel-range-top    { border-top:    2px solid #107c41 !important; }
.ag-theme-balham .excel-range-bottom { border-bottom: 2px solid #107c41 !important; }
.ag-theme-balham .excel-range-left   { border-left:   2px solid #107c41 !important; }
.ag-theme-balham .excel-range-right  { border-right:  2px solid #107c41 !important; }

/* Fill handle — small green square in bottom-right of selection */
.ag-theme-balham .excel-range-corner::after {
    content: '';
    position: absolute;
    bottom: -4px;
    right: -4px;
    width: 7px;
    height: 7px;
    background-color: #107c41;
    border: 1.5px solid white;
    z-index: 100;
    cursor: crosshair;
    box-shadow: 0 0 1px rgba(0,0,0,0.4);
}

/* Active cell — Excel deep green border, white inside */
.ag-theme-balham .ag-cell-focus,
.ag-theme-balham .excel-active-cell {
    border: 2px solid #107c41 !important;
    outline: none !important;
    background-color: white !important;
    z-index: 6 !important;
}

/* Row number column (the grey strip on the left) */
.ag-theme-balham .excel-row-number-cell {
    background-color: #f3f3f3 !important;
    border-right: 1px solid #d4d4d4 !important;
    text-align: center;
    color: #444;
    cursor: pointer;
    font-weight: 400;
}
.ag-theme-balham .excel-row-number-cell:hover {
    background-color: #e8e8e8 !important;
}
.ag-theme-balham .ag-cell.excel-header-highlight.excel-row-number-cell {
    background-color: #d4dfd6 !important;
    color: #107c41 !important;
    font-weight: 600 !important;
    box-shadow: inset -2px 0 0 0 #107c41;
}

/* Cells: thinner border + Excel default left-align for text, right-align for numbers handled inline */
.ag-theme-balham .ag-cell {
    border-right: 1px solid #d4d4d4;
    border-bottom: 1px solid #d4d4d4;
    line-height: 1.3;
}

/* Pinned-top row (frozen first row) — slight shadow under it */
.ag-theme-balham .ag-floating-top {
    box-shadow: 0 2px 4px rgba(0,0,0,0.08);
    border-bottom: 1px solid #b8b8b8;
}

/* === Row resize handle === */
.excel-row-num-wrap {
    position: relative;
    width: 100%;
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    user-select: none;
}
.excel-row-resize-handle {
    position: absolute;
    left: 0;
    right: 0;
    bottom: -2px;
    height: 4px;
    cursor: row-resize;
    z-index: 10;
}
.excel-row-resize-handle:hover {
    background-color: #107c41;
}

/* Resize tooltip during drag */
.excel-resize-tooltip {
    position: fixed;
    background: #323130;
    color: #fff;
    padding: 4px 10px;
    border-radius: 2px;
    font-size: 11px;
    font-family: 'Segoe UI', sans-serif;
    z-index: 100000;
    pointer-events: none;
    box-shadow: 0 2px 6px rgba(0,0,0,0.3);
}
</style>

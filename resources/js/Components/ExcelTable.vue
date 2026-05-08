<script setup>
import { ref, watch, onMounted, onUnmounted, computed } from 'vue';
import { AgGridVue } from 'ag-grid-vue3';
import { ModuleRegistry, AllCommunityModule } from 'ag-grid-community';
import { HyperFormula } from 'hyperformula';
import DropdownEditor from '@/Components/DropdownEditor.vue';
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
    freezeRow: { type: Boolean, default: false },
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

const emit = defineEmits(['cell-value-changed', 'cell-focused', 'cell-context-menu', 'selection-changed', 'ready', 'range-clear', 'grow-rows', 'grow-cols', 'column-resized', 'row-resized', 'row-inspect']);
const gridApi = ref(null);

const hf = HyperFormula.buildEmpty({ licenseKey: 'gpl-v3' });

const onGridReady = (params) => {
    gridApi.value = params.api;
    emit('ready', params.api);
    setTimeout(() => { updateHFData(); }, 0);
};

// Стабильный id для каждой строки. БЕЗ него AG-Grid v35 при любой реактивной
// мутации rowData (Vue 3 deep proxy) делает фактически setRowData() — сбрасывает
// виртуальный скролл к строке 0 и потом восстанавливает фокус на активной ячейке.
// Визуально это «страница прыгает наверх и возвращается» во время ввода.
//
// С getRowId AG-Grid делает дельта-апдейты — скролл и активный редактор переживают
// любую правку tableData. Используем data.id для строк из БД, либо `_client_id`
// для локальных строк (поле проставляется при создании пустой строки в Dashboard).
// WeakMap-вариант пробовали — AG-Grid v35 передаёт в params.data НЕ тот же proxy,
// что в массиве, поэтому WeakMap-lookup промахивался и uid'ы плыли → текст
// уезжал в верхние строки.
const getRowId = (params) => {
    const d = params?.data;
    if (!d) return undefined;
    if (d.id != null) return 'id_' + d.id;
    if (d._client_id) return d._client_id;
    return undefined;
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

// Class-renderer с явным destroy() — AG Grid вызывает его при пересборе/прокрутке,
// чтобы мы могли отвязать обработчики и не плодить замыкания.
class RowNumCellRenderer {
    init(params) {
        this.params = params;
        const absR = toAbsRow(params.node?.rowIndex, params.node?.rowPinned);
        this.absR = absR;
        const wrap = document.createElement('div');
        wrap.className = 'excel-row-num-wrap';
        wrap.textContent = absR >= 0 ? String(absR + 1) : '';
        this._onClick = (e) => {
            if (e.target && e.target.classList && e.target.classList.contains('excel-row-resize-handle')) return;
            if (this.absR >= 0) selectEntireRow(this.absR);
        };
        wrap.addEventListener('click', this._onClick);
        const handle = document.createElement('div');
        handle.className = 'excel-row-resize-handle';
        handle.title = 'Потяните, чтобы изменить высоту строки';
        this._onHandleDown = (e) => { if (this.absR >= 0) startRowResize(e, this.absR); };
        handle.addEventListener('mousedown', this._onHandleDown);
        wrap.appendChild(handle);
        this._wrap = wrap;
        this._handle = handle;
    }
    getGui() { return this._wrap; }
    refresh(params) {
        // Возвращаем false — AG Grid пересоздаст компонент. Перерисовывать
        // имеет смысл, только если изменился rowIndex; проще пересоздать.
        return false;
    }
    destroy() {
        if (this._wrap && this._onClick) this._wrap.removeEventListener('click', this._onClick);
        if (this._handle && this._onHandleDown) this._handle.removeEventListener('mousedown', this._onHandleDown);
        this._wrap = null;
        this._handle = null;
        this._onClick = null;
        this._onHandleDown = null;
        this.params = null;
    }
}

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

// Полный набор данных для HyperFormula с учётом закреплённой первой строки.
// Иначе формула =A1 в body-row 0 (когда freezeRow=true) ссылается сама на себя:
// HF не знает про вынутую шапку.
const fullRowsForHF = () => {
    const pinned = (props.freezeRow && props.pinnedTopRowData?.length) ? [props.pinnedTopRowData[0]] : [];
    return [...pinned, ...(props.rowData || [])];
};

const updateHFData = () => {
    const sheetName = 'Sheet1';
    let sheetId = hf.getSheetId(sheetName);
    if (sheetId === undefined) {
        hf.addSheet(sheetName);
        sheetId = hf.getSheetId(sheetName);
    }
    if (typeof sheetId !== 'number') return;
    const fields = props.columnDefs.map(c => c.field);
    const hfData = fullRowsForHF().map(row => fields.map(field => row[field] ?? ''));
    hf.setSheetContent(sheetId, hfData);
};

// Throttled обновление HF: при паст/филл/автосумм мутируется тот же массив (длина та же),
// просто [length] не сработает. Делаем глубокий watch по ссылкам данных.
let _hfThrottleTimer = null;
const scheduleHFUpdate = () => {
    if (_hfThrottleTimer) return;
    _hfThrottleTimer = setTimeout(() => {
        _hfThrottleTimer = null;
        updateHFData();
        gridApi.value?.refreshCells({ force: true });
    }, 50);
};
watch(() => props.rowData, scheduleHFUpdate, { deep: true });
watch(() => props.columnDefs?.length, scheduleHFUpdate);
watch(() => props.freezeRow, scheduleHFUpdate);
watch(() => props.pinnedTopRowData, scheduleHFUpdate, { deep: true });

const getCellValue = (params) => {
    const value = params.data[params.colDef.field];
    if (typeof value === 'string' && value.startsWith('=')) {
        const sheetId = hf.getSheetId('Sheet1');
        const colIndex = props.columnDefs.findIndex(c => c.field === params.colDef.field);
        // Абсолютный индекс строки в HF: учитываем закреплённую шапку.
        const rowPinned = params.node.rowPinned;
        const bodyIdx = params.node.rowIndex;
        const rowIndex = rowPinned === 'top'
            ? 0
            : (props.freezeRow ? bodyIdx + 1 : bodyIdx);
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

// Форматирует значение точно так же, как valueFormatter в defaultColDef:
// Excel-сериал → дата ru-RU, числа → разряды/валюта/проценты/decimals.
// Используется для панели «Информация о строке», чтобы показывать ровно то,
// что юзер видит в ячейке, а не сырой сериал/число без формата.
const formatLikeCell = (rawVal, style) => {
    if (rawVal === null || rawVal === undefined || rawVal === '') return rawVal;
    const num = parseFloat(rawVal);
    if (style?.numberFormat === 'shortDate' && !isNaN(num) && num > 0 && num < 2958466) {
        const d = excelSerialToDate(num);
        if (!isNaN(d)) return d.toLocaleDateString('ru-RU');
    }
    if (isNaN(num)) return rawVal;
    let formatted = rawVal;
    if (style?.decimals !== undefined) {
        formatted = num.toFixed(style.decimals);
    } else if (style?.numberFormat === 'currency' || style?.numberFormat === 'percentage') {
        formatted = num.toFixed(2);
    }
    if (style?.numberFormat === 'currency') formatted = '$' + formatted;
    if (style?.numberFormat === 'percent' || style?.numberFormat === 'percentage') formatted = formatted + '%';
    if (style?.numberFormat === 'comma') {
        const parts = String(formatted).split('.');
        parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        formatted = parts.join('.');
    }
    return formatted;
};

// Двойной клик по ячейке — отдаём наверх всю строку для панели просмотра.
// Игнорируем колонку с номером строки (rowNum) и пустые pinned-плейсхолдеры.
// Алгоритм для каждой колонки:
//   1) если значение — формула (=...), считаем через HyperFormula и берём результат
//      (как в ячейке таблицы);
//   2) применяем тот же formatter, что и в ячейке (даты, разряды, валюта, проценты).
const onCellDoubleClicked = (params) => {
    if (!params.data) return;
    if (params.column?.getColId() === 'rowNum') return;

    const rowPinned = params.node?.rowPinned;
    const bodyIdx = params.node?.rowIndex;
    const absRow = rowPinned === 'top' ? 0 : (props.freezeRow ? bodyIdx + 1 : bodyIdx);
    const sheetId = hf.getSheetId('Sheet1');

    const snapshot = {};
    props.columnDefs.forEach((col, colIndex) => {
        const raw = params.data[col.field];
        let resolved = raw;
        if (typeof raw === 'string' && raw.startsWith('=')) {
            try {
                const v = (typeof sheetId === 'number')
                    ? hf.getCellValue({ sheet: sheetId, col: colIndex, row: absRow })
                    : raw;
                resolved = v instanceof Error ? '#ERROR!' : v;
            } catch (e) {
                resolved = '#VALUE!';
            }
        }
        const style = params.data[col.field + '_style'] || {};
        snapshot[col.field] = formatLikeCell(resolved, style);
    });
    emit('row-inspect', snapshot);
};

const onCellValueChanged = (event) => {
    // Инкрементально обновляем ОДНУ ячейку в HyperFormula вместо полного ребилда листа.
    // Для большого листа (10К строк × 50 колонок) это в разы быстрее.
    try {
        const sheetId = hf.getSheetId('Sheet1');
        if (typeof sheetId === 'number') {
            const colIndex = props.columnDefs.findIndex(c => c.field === event.column?.getColId());
            const rowPinned = event.node?.rowPinned;
            // ВНИМАНИЕ: event.node.rowIndex после фильтра/сортировки = «отображаемый» индекс,
            // а не индекс в данных. Берём абсолютный индекс по ССЫЛКЕ event.data в массиве —
            // это надёжно при любом порядке/фильтрации.
            let absRow = -1;
            if (rowPinned === 'top') {
                absRow = 0;
            } else {
                const dataIdx = (props.rowData || []).indexOf(event.data);
                if (dataIdx >= 0) absRow = props.freezeRow ? dataIdx + 1 : dataIdx;
            }
            if (colIndex >= 0 && absRow >= 0) {
                hf.setCellContents({ sheet: sheetId, col: colIndex, row: absRow }, [[event.newValue ?? '']]);
            }
        }
    } catch (_) { /* падать не должны — пусть deep-watcher подхватит */ }

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
    // Правый клик НЕ должен сбрасывать выделение — иначе перед открытием
    // контекстного меню множественное выделение схлопывается в одну ячейку,
    // и «Сумма выделения» считает только одну. Игнорируем right-click здесь;
    // меню откроется через onCellContextMenu, который не трогает selection.
    if (params.event && params.event.button === 2) return;

    isSelecting.value = true;
    const colIndex = props.columnDefs.findIndex(c => c.field === params.colDef.field);
    if (colIndex === -1) return; // pinned row-num column — клик обрабатывается через cellRenderer
    const absR = toAbsRow(params.node.rowIndex, params.node.rowPinned);
    if (absR < 0) return;

    // Shift+click — расширяем существующее выделение от уже выбранного start.
    // Без Shift — обычный клик стартует новый диапазон.
    const shiftKey = !!(params.event && params.event.shiftKey);
    if (shiftKey && selectionStart.value) {
        selectionEnd.value = { row: absR, col: colIndex, rowData: params.node.data };
    } else {
        selectionStart.value = { row: absR, col: colIndex, rowData: params.node.data };
        selectionEnd.value   = { row: absR, col: colIndex, rowData: params.node.data };
    }

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

// === Auto-scroll при выделении мышью ===
// Если при зажатой левой кнопке курсор ушёл к нижнему/верхнему/правому/левому
// краю грид-контейнера — таймер продлевает выделение и докручивает грид в ту
// же сторону. Без этого `cellMouseOver` не приходит для строк за пределами
// видимой области, и selectionEnd замирает на последней видимой ячейке.
const wrapperRef = ref(null);
let _autoScrollTimer = null;
let _autoScrollDir = { row: 0, col: 0 };

const stopAutoScroll = () => {
    if (_autoScrollTimer) { clearInterval(_autoScrollTimer); _autoScrollTimer = null; }
    _autoScrollDir = { row: 0, col: 0 };
};

const computeAutoScrollDir = (e) => {
    const wrap = wrapperRef.value;
    if (!wrap) return { row: 0, col: 0 };
    const rect = wrap.getBoundingClientRect();
    const EDGE = 30; // пиксели зоны срабатывания у каждого края
    let row = 0, col = 0;
    if (e.clientY > rect.bottom - EDGE) row = 1;
    else if (e.clientY < rect.top + EDGE) row = -1;
    if (e.clientX > rect.right - EDGE) col = 1;
    else if (e.clientX < rect.left + EDGE) col = -1;
    return { row, col };
};

const ensureAutoScrollRunning = () => {
    if (_autoScrollTimer) return;
    _autoScrollTimer = setInterval(() => {
        if (!isSelecting.value || !selectionEnd.value || !gridApi.value) {
            stopAutoScroll();
            return;
        }
        const { row: dRow, col: dCol } = _autoScrollDir;
        if (dRow === 0 && dCol === 0) { stopAutoScroll(); return; }

        const totalRows = totalAbsRows();
        const lastCol = (props.columnDefs?.length || 1) - 1;
        let newRow = Math.max(0, Math.min(totalRows - 1, selectionEnd.value.row + dRow));
        let newCol = Math.max(0, Math.min(lastCol, selectionEnd.value.col + dCol));
        if (newRow === selectionEnd.value.row && newCol === selectionEnd.value.col) {
            stopAutoScroll();
            return;
        }
        selectionEnd.value = { row: newRow, col: newCol, rowData: null };

        // Скроллим грид так, чтобы новая «end»-ячейка была видна.
        // ensureIndexVisible принимает AG-Grid display-index (без pinned-строки).
        try {
            const agRowIdx = props.freezeRow ? Math.max(0, newRow - 1) : newRow;
            gridApi.value.ensureIndexVisible(agRowIdx);
        } catch (_) {}
        try {
            const cf = props.columnDefs[newCol]?.field;
            if (cf) gridApi.value.ensureColumnVisible(cf);
        } catch (_) {}

        emit('selection-changed', { start: selectionStart.value, end: selectionEnd.value });
        gridApi.value.refreshCells({ suppressFlash: true });
    }, 40);
};

const trackMouseAndAutoScroll = (e) => {
    trackMouse(e); // существующий tracker для tooltip-ресайза
    if (!isSelecting.value) { stopAutoScroll(); return; }
    _autoScrollDir = computeAutoScrollDir(e);
    if (_autoScrollDir.row === 0 && _autoScrollDir.col === 0) {
        stopAutoScroll();
    } else {
        ensureAutoScrollRunning();
    }
};

const onWindowMouseUp = () => { isSelecting.value = false; stopAutoScroll(); };
onMounted(() => {
    window.addEventListener('mouseup', onWindowMouseUp);
    window.addEventListener('mousemove', trackMouseAndAutoScroll);
});
onUnmounted(() => {
    window.removeEventListener('mouseup', onWindowMouseUp);
    window.removeEventListener('mousemove', trackMouseAndAutoScroll);
    stopAutoScroll();
    document.removeEventListener('mousemove', onRowResizeMove);
    document.removeEventListener('mouseup', onRowResizeEnd);
    if (growRowsTimer) { clearTimeout(growRowsTimer); growRowsTimer = null; }
    if (growColsTimer) { clearTimeout(growColsTimer); growColsTimer = null; }
    if (_hfThrottleTimer) { clearTimeout(_hfThrottleTimer); _hfThrottleTimer = null; }
    try { hf.destroy(); } catch (_) {}
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

// Проверяет, был ли keydown инициирован из любого редактируемого поля —
// инпута/textarea/select, contenteditable, ИЛИ AG Grid-попапа (фильтр, меню колонки),
// в том числе если оно где-то внутри Shadow DOM. composedPath() даёт всех предков
// сквозь Shadow-границы; closest() — только обычный DOM.
const isEventInField = (e) => {
    const path = (typeof e.composedPath === 'function') ? e.composedPath() : [e.target];
    for (const el of path) {
        if (!el || el === window || el === document) continue;
        const tag = el.tagName;
        if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return true;
        if (el.isContentEditable) return true;
        const cl = el.classList;
        if (cl && (cl.contains('ag-filter') || cl.contains('ag-menu') || cl.contains('ag-popup'))) return true;
    }
    return false;
};

const handleKeyDown = (e) => {
    // Если клавиша нажата внутри редактируемого поля — НЕ перехватываем Delete/Backspace
    // и Shift+Arrows глобально, иначе сотрём содержимое ранее выделенного диапазона.
    if (isEventInField(e)) return;

    // 1. Очистка диапазона по клавише Delete или Backspace
    if (e.key === 'Delete' || e.key === 'Del' || e.key === 'Backspace') {
        // Read-only защита: viewer'ы не должны удалять данные клавиатурой.
        // Просто проглатываем нажатие — не шлём range-clear, не делаем
        // preventDefault. Это позволяет Backspace в адресной строке/инпуте
        // браузера работать нормально (но isEventInField выше уже отсеял).
        if (props.readOnly) return;
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

// Кэш «top-left ячейка → merge»: для colSpan/rowSpan callbacks (вызываются на каждый
// видимый рендер). Линейный поиск был квадратичным по числу видимых ячеек.
const mergeTopLeftCache = computed(() => {
    const cache = new Map();
    (props.merges || []).forEach(m => {
        cache.set(m.row + '-' + m.col, m);
    });
    return cache;
});

// Кэш «строка-колонка → merge»: при большом числе merge'ей линейный поиск
// делает cellStyle квадратичным по числу видимых ячеек. Map работает за O(1).
const mergeCoverCache = computed(() => {
    const cache = new Map(); // key = `${row}-${col}` → merge object covering this cell (or 'self' if top-left)
    const merges = props.merges || [];
    for (const m of merges) {
        for (let r = m.row; r < m.row + m.rowSpan; r++) {
            for (let c = m.col; c < m.col + m.colSpan; c++) {
                const key = r + '-' + c;
                if (r === m.row && c === m.col) cache.set(key, null); // top-left — рисуется как обычно
                else cache.set(key, m);
            }
        }
    }
    return cache;
});

// Find merge that COVERS this cell (but is not the top-left). Such cells should not render.
const findCoveringMerge = (rowIndex, colIndex) => {
    const key = rowIndex + '-' + colIndex;
    if (!mergeCoverCache.value.has(key)) return null;
    return mergeCoverCache.value.get(key); // null означает «top-left, рисуем»
};

// --- Русская локализация UI AG Grid (фильтры, меню колонок, общие надписи) ---
const localeText = {
    // Кнопки внизу попапа фильтра
    applyFilter: 'Применить',
    resetFilter: 'Сбросить',
    clearFilter: 'Очистить',
    cancelFilter: 'Отмена',

    // Заголовок и поиск в попапе фильтра
    filterOoo: 'Фильтр…',
    searchOoo: 'Поиск…',
    selectAll: 'Выбрать всё',
    selectAllSearchResults: 'Выбрать всё (результаты поиска)',
    blanks: '(Пустые)',
    noMatches: 'Совпадений нет',

    // Текстовый фильтр
    contains: 'Содержит',
    notContains: 'Не содержит',
    startsWith: 'Начинается с',
    endsWith: 'Заканчивается на',

    // Общие операторы
    equals: 'Равно',
    notEqual: 'Не равно',
    blank: 'Пусто',
    notBlank: 'Не пусто',
    empty: 'Выберите условие',

    // Числовые / датные операторы
    lessThan: 'Меньше',
    lessThanOrEqual: 'Меньше или равно',
    greaterThan: 'Больше',
    greaterThanOrEqual: 'Больше или равно',
    inRange: 'В диапазоне',
    inRangeStart: 'От',
    inRangeEnd: 'До',

    // И / Или
    andCondition: 'И',
    orCondition: 'Или',

    // Меню колонки (при клике на «гамбургер»)
    pinColumn: 'Закрепить колонку',
    pinLeft: 'Слева',
    pinRight: 'Справа',
    noPin: 'Не закреплять',
    autosizeThiscolumn: 'Авторазмер этой колонки',
    autosizeAllColumns: 'Авторазмер всех колонок',
    resetColumns: 'Сбросить колонки',
    columns: 'Колонки',

    // Шапка / прочее
    loadingOoo: 'Загрузка…',
    noRowsToShow: 'Нет данных',
    page: 'Страница',
    of: 'из',
    to: 'до',
    next: 'Следующая',
    last: 'Последняя',
    first: 'Первая',
    previous: 'Предыдущая',
};

// --- Авто-определение типа фильтра по содержимому колонки (Excel-style) ---
// Сэмплируем до 100 значений и решаем: число / дата / текст.
const detectFilterType = (field) => {
    const data = props.rowData || [];
    if (data.length === 0) return 'text';
    const limit = Math.min(100, data.length);
    let total = 0, nums = 0, dates = 0;
    for (let i = 0; i < limit; i++) {
        const raw = data[i]?.[field];
        if (raw === null || raw === undefined || raw === '') continue;
        total++;
        const s = String(raw).trim();
        if (s.startsWith('=')) continue; // формулы не считаем при определении типа
        if (/^-?\d+([.,]\d+)?$/.test(s)) nums++;
        else if (/^\d{1,4}[./-]\d{1,2}[./-]\d{1,4}$/.test(s)) dates++;
    }
    if (total === 0) return 'text';
    if (dates / total > 0.6) return 'date';
    if (nums / total > 0.6) return 'number';
    return 'text';
};

const filterConfigFor = (field) => {
    const t = detectFilterType(field);
    if (t === 'number') {
        return {
            filter: 'agNumberColumnFilter',
            filterParams: {
                filterOptions: ['equals', 'notEqual', 'lessThan', 'lessThanOrEqual',
                                'greaterThan', 'greaterThanOrEqual', 'inRange', 'blank', 'notBlank'],
                defaultOption: 'equals',
                buttons: ['clear', 'reset', 'apply'],
                closeOnApply: true,
                filterPlaceholder: 'Введите число…',
                numberParser: (text) => (text == null || text === '') ? null : parseFloat(String(text).replace(',', '.')),
            }
        };
    }
    if (t === 'date') {
        return {
            filter: 'agDateColumnFilter',
            filterParams: {
                filterOptions: ['equals', 'notEqual', 'lessThan', 'greaterThan', 'inRange', 'blank', 'notBlank'],
                defaultOption: 'equals',
                buttons: ['clear', 'reset', 'apply'],
                closeOnApply: true,
                filterPlaceholder: 'Выберите дату…',
                comparator: (filterDate, cellValue) => {
                    if (!cellValue) return -1;
                    const s = String(cellValue).trim().replace(/\./g, '-').replace(/\//g, '-');
                    // Поддерживаем DD-MM-YYYY и YYYY-MM-DD
                    let d;
                    const m1 = s.match(/^(\d{1,2})-(\d{1,2})-(\d{2,4})$/);
                    if (m1) {
                        const yyyy = m1[3].length === 2 ? '20' + m1[3] : m1[3];
                        d = new Date(`${yyyy}-${m1[2].padStart(2,'0')}-${m1[1].padStart(2,'0')}`);
                    } else {
                        d = new Date(s);
                    }
                    if (isNaN(d)) return -1;
                    if (d < filterDate) return -1;
                    if (d > filterDate) return 1;
                    return 0;
                },
            }
        };
    }
    return {
        filter: 'agTextColumnFilter',
        filterParams: {
            filterOptions: ['contains', 'notContains', 'equals', 'notEqual',
                            'startsWith', 'endsWith', 'blank', 'notBlank'],
            defaultOption: 'contains',
            buttons: ['clear', 'reset', 'apply'],
            closeOnApply: true,
            caseSensitive: false,
            trimInput: true,
            filterPlaceholder: 'Введите текст…',
        }
    };
};

// Excel-style comparator: пустые ячейки ВСЕГДА внизу (независимо от направления
// сортировки), числа сравниваются как числа, строки — через localeCompare с
// поддержкой кириллицы и natural-numeric (а10 после а2, не после а1).
//
// Без этого тысячи placeholder-строк (с _client_id но без значений) лезут
// наверх при ascending-сортировке и закрывают реальные данные → юзеру
// кажется что таблица «исчезла».
const excelStyleComparator = (valueA, valueB, _nodeA, _nodeB, isDescending) => {
    const aEmpty = valueA === null || valueA === undefined || valueA === '';
    const bEmpty = valueB === null || valueB === undefined || valueB === '';
    if (aEmpty && bEmpty) return 0;
    // Пустая всегда внизу. AG-Grid инвертирует возвращаемое значение при
    // descending — поэтому возвращаем знак с учётом isDescending, иначе пустые
    // улетят наверх при ↓-сортировке.
    if (aEmpty) return isDescending ? -1 : 1;
    if (bEmpty) return isDescending ? 1 : -1;
    if (typeof valueA === 'number' && typeof valueB === 'number') return valueA - valueB;
    // Числа в строках («10» vs «9») — natural-сортировка.
    return String(valueA).localeCompare(String(valueB), 'ru', { numeric: true, sensitivity: 'base' });
};

const defaultColDef = {
    // sortable: true — AG-Grid рисует стрелку ▲▼ в шапке + клик меняет порядок.
    // Сортировка применяется к виду без изменения row_index в БД (это локальный
    // визуальный порядок). Filter уже был настроен — теперь оба работают.
    flex: 1, minWidth: 100, filter: 'agTextColumnFilter', sortable: true, resizable: true,
    comparator: excelStyleComparator,
    filterParams: {
        filterOptions: ['contains', 'notContains', 'equals', 'notEqual',
                        'startsWith', 'endsWith', 'blank', 'notBlank'],
        defaultOption: 'contains',
        buttons: ['clear', 'reset', 'apply'],
        closeOnApply: true,
        caseSensitive: false,
        trimInput: true,
        filterPlaceholder: 'Введите текст…',
    },
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
        const colIdx = colIndexMap.value[params.colDef.field];
        if (colIdx === undefined) return base;
        const cov = findCoveringMerge(params.node.rowIndex, colIdx);
        if (cov) return { ...(base || {}), display: 'none' };
        return base;
    },
    colSpan: (params) => {
        const colIdx = colIndexMap.value[params.colDef.field];
        if (colIdx === undefined) return 1;
        const m = mergeTopLeftCache.value.get(params.node.rowIndex + '-' + colIdx);
        return m ? m.colSpan : 1;
    },
    rowSpan: (params) => {
        const colIdx = colIndexMap.value[params.colDef.field];
        if (colIdx === undefined) return 1;
        const m = mergeTopLeftCache.value.get(params.node.rowIndex + '-' + colIdx);
        return m ? m.rowSpan : 1;
    },
    cellEditorSelector: (params) => {
        const list = props.validations?.[params.colDef.field];
        if (Array.isArray(list) && list.length > 0) {
            // Мягкая валидация: список-подсказки + возможность напечатать своё (как в Excel).
            return { component: DropdownEditor, params: { values: list } };
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
        cellRenderer: RowNumCellRenderer,
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
        ...filterConfigFor(col.field),
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
    <div ref="wrapperRef" class="ag-theme-balham" style="width: 100%; height: 100%;" @contextmenu.prevent @click="onWrapperClick">
        <ag-grid-vue
            style="width: 100%; height: 100%;"
            theme="legacy"
            :columnDefs="finalColumnDefs"
            :rowData="rowData"
            :pinnedTopRowData="pinnedTopRowData"
            :defaultColDef="defaultColDef"
            :localeText="localeText"
            @grid-ready="onGridReady"
            @cell-value-changed="onCellValueChanged"
            @cell-focused="onCellFocused"
            @cell-mouse-down="onCellMouseDown"
            @cell-mouse-over="onCellMouseOver"
            @cell-context-menu="onCellContextMenu"
            @cell-double-clicked="onCellDoubleClicked"
            @body-scroll="onBodyScroll"
            @column-resized="onColumnResized"
            @keydown="onGridKeyDown"
            :animateRows="false"
            :getRowId="getRowId"
            :suppressScrollOnNewData="true"
            :headerHeight="24"
            :rowHeight="25"
            :getRowHeight="getRowHeight"
            :suppressRowTransform="true"
            :suppressClickEdit="true"
            :stopEditingWhenCellsLoseFocus="true"
        />
    </div>
</template>

<style>
/* === EXCEL-LIKE THEME === */
.ag-theme-balham {
    --ag-selected-row-background-color: transparent !important;
    --ag-header-background-color: #f8f9fa;
    --ag-header-foreground-color: #374151;
    --ag-border-color: #e5e7eb;
    --ag-row-border-color: #e5e7eb;
    --ag-cell-horizontal-border: solid 1px #e5e7eb;
    --ag-grid-size: 3px;
    --ag-font-size: 12px;
    --ag-font-family: 'Segoe UI', -apple-system, system-ui, 'Calibri', sans-serif;
    /* Hover — едва заметный синий tint, чтобы не отвлекал но был виден */
    --ag-row-hover-color: rgba(37, 99, 235, 0.04);
    user-select: none;
    background: #fff;
}

/* Modern thin scrollbars — Webkit */
.ag-theme-balham .ag-body-viewport::-webkit-scrollbar,
.ag-theme-balham .ag-body-horizontal-scroll-viewport::-webkit-scrollbar,
.ag-theme-balham .ag-virtual-list-viewport::-webkit-scrollbar { width: 10px; height: 10px; }
.ag-theme-balham .ag-body-viewport::-webkit-scrollbar-track,
.ag-theme-balham .ag-body-horizontal-scroll-viewport::-webkit-scrollbar-track,
.ag-theme-balham .ag-virtual-list-viewport::-webkit-scrollbar-track { background: transparent; }
.ag-theme-balham .ag-body-viewport::-webkit-scrollbar-thumb,
.ag-theme-balham .ag-body-horizontal-scroll-viewport::-webkit-scrollbar-thumb,
.ag-theme-balham .ag-virtual-list-viewport::-webkit-scrollbar-thumb {
    background: #d1d5db;
    border-radius: 5px;
    border: 2px solid transparent;
    background-clip: padding-box;
}
.ag-theme-balham .ag-body-viewport::-webkit-scrollbar-thumb:hover,
.ag-theme-balham .ag-body-horizontal-scroll-viewport::-webkit-scrollbar-thumb:hover,
.ag-theme-balham .ag-virtual-list-viewport::-webkit-scrollbar-thumb:hover { background: #9ca3af; background-clip: padding-box; }

/* Excel column header — мягкий нейтральный фон */
.ag-theme-balham .ag-header {
    border-bottom: 1px solid #d1d5db;
    background: #f8f9fa;
    /* Тонкая тень снизу — отделяет хедер от тела */
    box-shadow: 0 1px 0 0 rgba(0, 0, 0, 0.03);
}
.ag-theme-balham .ag-header-cell {
    border-right: 1px solid #e5e7eb;
    font-weight: 500;
    color: #374151;
    cursor: pointer;
    transition: background-color 120ms ease;
}
.ag-theme-balham .ag-header-cell:hover {
    background: #eef2ff;
    color: #2563eb;
}

/* Selected column header — green underline like real Excel */
.ag-theme-balham .excel-header-highlight,
.ag-theme-balham .ag-header-cell.excel-header-highlight {
    background-color: #dbeafe !important;
    color: #2563eb !important;
    font-weight: 600 !important;
    box-shadow: inset 0 -2px 0 0 #2563eb;
}

/* Selection range — мягкий синий tint (унифицирован с акцентом) */
.ag-theme-balham .excel-range-selected {
    background-color: rgba(37, 99, 235, 0.07) !important;
    z-index: 2 !important;
}
.ag-theme-balham .excel-range-top    { border-top:    2px solid #2563eb !important; }
.ag-theme-balham .excel-range-bottom { border-bottom: 2px solid #2563eb !important; }
.ag-theme-balham .excel-range-left   { border-left:   2px solid #2563eb !important; }
.ag-theme-balham .excel-range-right  { border-right:  2px solid #2563eb !important; }

/* Fill handle — синий квадратик в правом нижнем углу выделения, чуть приподнятый */
.ag-theme-balham .excel-range-corner::after {
    content: '';
    position: absolute;
    bottom: -4px;
    right: -4px;
    width: 8px;
    height: 8px;
    background-color: #2563eb;
    border: 2px solid white;
    border-radius: 1px;
    z-index: 100;
    cursor: crosshair;
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.25);
    transition: transform 120ms ease;
}
.ag-theme-balham .excel-range-corner:hover::after {
    transform: scale(1.25);
}

/* Active cell — синяя рамка с лёгкой тенью для глубины */
.ag-theme-balham .ag-cell-focus,
.ag-theme-balham .excel-active-cell {
    border: 2px solid #2563eb !important;
    outline: none !important;
    background-color: white !important;
    z-index: 6 !important;
    box-shadow: 0 0 0 1px rgba(37, 99, 235, 0.15), 0 2px 4px rgba(37, 99, 235, 0.12);
}

/* Row number column (нумерация строк слева) */
.ag-theme-balham .excel-row-number-cell {
    background-color: #f8f9fa !important;
    border-right: 1px solid #d1d5db !important;
    text-align: center;
    color: #6b7280;
    cursor: pointer;
    font-weight: 400;
    font-variant-numeric: tabular-nums;
    transition: background-color 120ms ease, color 120ms ease;
}
.ag-theme-balham .excel-row-number-cell:hover {
    background-color: #eef2ff !important;
    color: #2563eb;
}
.ag-theme-balham .ag-cell.excel-header-highlight.excel-row-number-cell {
    background-color: #dbeafe !important;
    color: #2563eb !important;
    font-weight: 600 !important;
    box-shadow: inset -2px 0 0 0 #2563eb;
}

/* Cells — мягкие границы, удобная высота строки */
.ag-theme-balham .ag-cell {
    border-right: 1px solid #e5e7eb;
    border-bottom: 1px solid #e5e7eb;
    line-height: 1.4;
    padding: 0 6px;
}

/* Pinned-top row (закреплённая шапка) — заметная тень + насыщенная нижняя граница */
.ag-theme-balham .ag-floating-top {
    box-shadow: 0 3px 6px -1px rgba(0, 0, 0, 0.08);
    border-bottom: 2px solid #2563eb;
    background: #fafbff;
}
/* Закреплённая строка визуально отличается — лёгкий синий tint */
.ag-theme-balham .ag-floating-top .ag-row {
    background: #fafbff !important;
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
    transition: background-color 120ms ease;
}
.excel-row-resize-handle:hover {
    background-color: #2563eb;
}

/* Cell editing state — синий ободок с белым фоном + glow */
.ag-theme-balham .ag-cell-inline-editing {
    border: 2px solid #2563eb !important;
    background: #fff !important;
    box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.18), 0 2px 6px rgba(37, 99, 235, 0.15) !important;
    border-radius: 2px;
    z-index: 7 !important;
    height: auto !important;
}
.ag-theme-balham .ag-cell-inline-editing input {
    height: 100% !important;
    padding: 0 6px !important;
    font-size: 12px !important;
    border: none !important;
    outline: none !important;
    background: transparent !important;
    color: #111827;
}

/* Плавный hover на строки */
.ag-theme-balham .ag-row {
    transition: background-color 120ms ease;
}

/* Корнеры таблицы — закруглить чуть-чуть */
.ag-theme-balham {
    border-radius: 0;
}

/* === Filter UX (modern / non-Excel) ============================ */
/* Палитра: тёмно-фиолетовый акцент + светло-серый фон, скруглённые формы. */

/* 1) Иконка фильтра в шапке: всегда чуть видна, ярче на hover */
.ag-theme-balham .ag-header-cell .ag-header-cell-menu-button,
.ag-theme-balham .ag-header-cell .ag-header-icon {
    opacity: 0.6;
    transition: opacity .15s, color .15s;
}
.ag-theme-balham .ag-header-cell:hover .ag-header-cell-menu-button,
.ag-theme-balham .ag-header-cell:hover .ag-header-icon {
    opacity: 1;
}

/* 2) Колонка с активным фильтром — фиолетовая «таблетка» вокруг иконки + точка-индикатор */
.ag-theme-balham .ag-header-cell.ag-header-cell-filtered {
    background-color: #eff6ff !important;
    box-shadow: inset 0 0 0 1px #bfdbfe;
}
.ag-theme-balham .ag-header-cell.ag-header-cell-filtered::after {
    content: '';
    position: absolute;
    top: 4px;
    right: 4px;
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: #2563eb;
    box-shadow: 0 0 0 2px #fff;
}
.ag-theme-balham .ag-header-cell.ag-header-cell-filtered .ag-header-cell-menu-button,
.ag-theme-balham .ag-header-cell.ag-header-cell-filtered .ag-icon-filter,
.ag-theme-balham .ag-header-cell.ag-header-cell-filtered .ag-header-icon {
    opacity: 1 !important;
    color: #2563eb !important;
}

/* 3) Попап — карточка с большим скруглением и мягкой тенью */
.ag-theme-balham .ag-popup .ag-menu,
.ag-theme-balham .ag-filter {
    border: 1px solid #e5e7eb !important;
    border-radius: 12px !important;
    box-shadow: 0 12px 40px -8px rgba(17,24,39,.18), 0 4px 12px rgba(17,24,39,.06) !important;
    background: #fff !important;
    min-width: 300px !important;
    overflow: hidden !important;
    font-family: 'Inter', -apple-system, 'Segoe UI', Roboto, sans-serif !important;
}

/* 4) Внутренние поля — крупнее, со скруглением, фокус — фиолетовый */
.ag-theme-balham .ag-filter-wrapper {
    padding: 14px 14px 6px 14px !important;
    background: #fafafa !important;
}
.ag-theme-balham .ag-filter input[type="text"],
.ag-theme-balham .ag-filter input[type="number"],
.ag-theme-balham .ag-filter input[type="date"],
.ag-theme-balham .ag-filter .ag-picker-field-wrapper,
.ag-theme-balham .ag-filter select {
    border: 1px solid #e5e7eb !important;
    border-radius: 8px !important;
    padding: 8px 12px !important;
    height: 36px !important;
    font-size: 13px !important;
    background: #fff !important;
    color: #111827 !important;
    box-sizing: border-box !important;
    width: 100% !important;
    transition: border-color .15s, box-shadow .15s;
}
.ag-theme-balham .ag-filter input:focus,
.ag-theme-balham .ag-filter .ag-picker-field-wrapper:focus-within {
    border-color: #2563eb !important;
    outline: none !important;
    box-shadow: 0 0 0 3px rgba(37,99,235,.18) !important;
}

/* 5) И / Или — мягкая «тонкая» панель посередине */
.ag-theme-balham .ag-filter-condition {
    margin: 10px 0 !important;
    padding: 8px 10px !important;
    background: #fff !important;
    border: 1px solid #e5e7eb !important;
    border-radius: 8px !important;
    font-size: 12px !important;
    color: #6b7280 !important;
    display: flex !important;
    gap: 18px !important;
    justify-content: center !important;
}

/* 6) Кнопочная панель: pill-кнопки, primary — фиолетовый градиент */
.ag-theme-balham .ag-filter-apply-panel {
    border-top: 1px solid #e5e7eb !important;
    background: #fff !important;
    padding: 12px 14px !important;
    display: flex !important;
    gap: 8px !important;
    justify-content: flex-end !important;
}
.ag-theme-balham .ag-filter-apply-panel button {
    border: 1px solid #e5e7eb !important;
    border-radius: 999px !important;
    padding: 7px 18px !important;
    font-size: 12px !important;
    font-weight: 500 !important;
    background: #fff !important;
    color: #4b5563 !important;
    cursor: pointer !important;
    min-width: 84px !important;
    transition: background .15s, border-color .15s, transform .08s;
}
.ag-theme-balham .ag-filter-apply-panel button:hover {
    background: #f9fafb !important;
    border-color: #d1d5db !important;
}
.ag-theme-balham .ag-filter-apply-panel button:active {
    transform: scale(.97);
}
/* «Применить» — последняя кнопка, фиолетовый primary с градиентом */
.ag-theme-balham .ag-filter-apply-panel button:last-child {
    background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%) !important;
    color: #fff !important;
    border-color: transparent !important;
    font-weight: 600 !important;
    box-shadow: 0 1px 2px rgba(37,99,235,.3), 0 4px 10px -2px rgba(37,99,235,.4);
}
.ag-theme-balham .ag-filter-apply-panel button:last-child:hover {
    background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%) !important;
    box-shadow: 0 2px 4px rgba(37,99,235,.4), 0 6px 14px -2px rgba(37,99,235,.5);
}

/* 7) Placeholder — тонкий, без курсива */
.ag-theme-balham .ag-filter input::placeholder {
    color: #9ca3af !important;
    font-style: normal !important;
}

/* === конец Filter UX =========================================== */

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

<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import ExcelTable from '@/Components/ExcelTable.vue';
import ExcelRibbon from '@/Components/ExcelRibbon.vue';
import ExcelContextMenu from '@/Components/ExcelContextMenu.vue';
import { ref, reactive, computed, onMounted, onUnmounted, watch, nextTick } from 'vue';
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
    canEdit: { type: Boolean, default: false },
    isAdmin: { type: Boolean, default: false },
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

// --- Zoom (как в Excel: ползунок справа снизу, Ctrl+колесо, диалог по клику на проценты) ---
const ZOOM_MIN = 25;
const ZOOM_MAX = 200;
const zoom = ref(100);
const showZoomDialog = ref(false);
const zoomDialogValue = ref(100);
const setZoom = (v) => {
    const n = Math.round(Number(v));
    if (Number.isNaN(n)) return;
    zoom.value = Math.max(ZOOM_MIN, Math.min(ZOOM_MAX, n));
};
const zoomIn = () => setZoom(zoom.value + 10);
const zoomOut = () => setZoom(zoom.value - 10);
const onZoomSliderDown = (e) => {
    const slider = e.currentTarget;
    const update = (clientX) => {
        const rect = slider.getBoundingClientRect();
        const ratio = Math.max(0, Math.min(1, (clientX - rect.left) / rect.width));
        setZoom(ZOOM_MIN + ratio * (ZOOM_MAX - ZOOM_MIN));
    };
    update(e.clientX);
    const move = (ev) => update(ev.clientX);
    const up = () => {
        document.removeEventListener('mousemove', move);
        document.removeEventListener('mouseup', up);
    };
    document.addEventListener('mousemove', move);
    document.addEventListener('mouseup', up);
};
const onWheelZoom = (e) => {
    if (!e.ctrlKey) return;
    e.preventDefault();
    setZoom(zoom.value + (e.deltaY < 0 ? 10 : -10));
};
const openZoomDialog = () => { zoomDialogValue.value = zoom.value; showZoomDialog.value = true; };

// === Sort dialog ===
const sortDialog = ref({ show: false, column: null, order: 'asc' });
const applySortFromDialog = () => {
    const col = sortDialog.value.column;
    const dir = sortDialog.value.order === 'desc' ? -1 : 1;
    sortDialog.value.show = false;
    if (!col) return;
    const num = (v) => { const n = parseFloat(String(v ?? '').replace(',', '.')); return isNaN(n) ? null : n; };
    // Если первая строка закреплена — не вовлекаем её в сортировку, она остаётся шапкой.
    const frozen = !!sheetMeta.value?.freezeRow;
    const head = frozen && tableData.value.length > 0 ? [tableData.value[0]] : [];
    const body = frozen ? tableData.value.slice(1) : tableData.value.slice();
    body.sort((a, b) => {
        const va = a[col], vb = b[col];
        const na = num(va), nb = num(vb);
        if (na !== null && nb !== null) return (na - nb) * dir;
        return String(va ?? '').localeCompare(String(vb ?? ''), 'ru') * dir;
    });
    tableData.value = [...head, ...body];
    const allRows = tableData.value.map((r, i) => ({ row_index: i, data: r }));
    router.post(route('sheets.updateData', props.activeSheet.id), { rows: allRows }, { preserveScroll: true, preserveState: true });
    tableApi.value?.refreshCells({ force: true });
};

// === Fill (Down/Up/Right/Left) ===
const applyFill = (dir) => {
    if (!currentSelection.value) return;
    const { start, end } = currentSelection.value;
    const r1 = Math.min(start.row, end.row);
    const r2 = Math.max(start.row, end.row);
    const c1 = Math.min(start.col, end.col);
    const c2 = Math.max(start.col, end.col);
    const cols = columnDefs.value.slice(c1, c2 + 1).map(c => c.field);
    const rowsArr = tableData.value.slice(r1, r2 + 1);
    const undoChanges = [];

    const fillCol = (ci) => {
        const f = cols[ci];
        const src = dir === 'up' ? rowsArr[rowsArr.length - 1] : rowsArr[0];
        const srcVal = src[f];
        const srcStyle = src[f + '_style'] ? JSON.parse(JSON.stringify(src[f + '_style'])) : null;
        rowsArr.forEach(row => {
            if (row === src) return;
            undoChanges.push({ dataRef: row, field: f, oldValue: row[f], oldStyle: row[f + '_style'] ? JSON.parse(JSON.stringify(row[f + '_style'])) : null });
            row[f] = srcVal;
            row[f + '_style'] = srcStyle ? JSON.parse(JSON.stringify(srcStyle)) : null;
        });
    };
    const fillRow = (ri) => {
        const row = rowsArr[ri];
        const srcField = dir === 'left' ? cols[cols.length - 1] : cols[0];
        const srcVal = row[srcField];
        const srcStyle = row[srcField + '_style'] ? JSON.parse(JSON.stringify(row[srcField + '_style'])) : null;
        cols.forEach(f => {
            if (f === srcField) return;
            undoChanges.push({ dataRef: row, field: f, oldValue: row[f], oldStyle: row[f + '_style'] ? JSON.parse(JSON.stringify(row[f + '_style'])) : null });
            row[f] = srcVal;
            row[f + '_style'] = srcStyle ? JSON.parse(JSON.stringify(srcStyle)) : null;
        });
    };

    if (dir === 'down' || dir === 'up') {
        for (let ci = 0; ci < cols.length; ci++) fillCol(ci);
    } else {
        for (let ri = 0; ri < rowsArr.length; ri++) fillRow(ri);
    }
    saveUndoState(undoChanges);
    syncChangesToServer(rowsArr);
};

// === Clear (All / Formats / Contents) ===
const applyClear = (what) => {
    if (!currentSelection.value) return;
    const { start, end } = currentSelection.value;
    const r1 = Math.min(start.row, end.row);
    const r2 = Math.max(start.row, end.row);
    const c1 = Math.min(start.col, end.col);
    const c2 = Math.max(start.col, end.col);
    const cols = columnDefs.value.slice(c1, c2 + 1).map(c => c.field);
    const rowsArr = tableData.value.slice(r1, r2 + 1);
    const undoChanges = [];
    rowsArr.forEach(row => cols.forEach(f => {
        undoChanges.push({ dataRef: row, field: f, oldValue: row[f], oldStyle: row[f + '_style'] ? JSON.parse(JSON.stringify(row[f + '_style'])) : null });
        if (what === 'all' || what === 'contents') row[f] = '';
        if (what === 'all' || what === 'formats') row[f + '_style'] = null;
    }));
    saveUndoState(undoChanges);
    syncChangesToServer(rowsArr);
};

// === Format submenu (Cells group): row height / col width ===
const applyCellsFormat = (type) => {
    const { rowIndex, colId } = activeCellInfo.value;
    if (type === 'format-rowHeight') {
        if (rowIndex == null) {
            alert('Сначала выделите ячейку в нужной строке.');
            return;
        }
        const cur = sheetMeta.value.rowHeights?.[rowIndex] || 25;
        const v = prompt('Высота строки (px):', cur);
        const n = parseInt(v, 10);
        if (!isNaN(n) && n >= 10 && n <= 500) {
            sheetMeta.value.rowHeights = { ...sheetMeta.value.rowHeights, [rowIndex]: n };
            tableApi.value?.onRowHeightChanged?.();
        }
    } else if (type === 'format-colWidth') {
        if (!colId) {
            alert('Сначала выделите ячейку в нужной колонке.');
            return;
        }
        const cur = sheetMeta.value.colWidths?.[colId] || 100;
        const v = prompt('Ширина столбца (px):', cur);
        const n = parseInt(v, 10);
        if (!isNaN(n) && n >= 20 && n <= 1000) {
            sheetMeta.value.colWidths = { ...sheetMeta.value.colWidths, [colId]: n };
        }
    }
};

const handleColumnResized = ({ field, width }) => {
    sheetMeta.value.colWidths = { ...sheetMeta.value.colWidths, [field]: width };
};

const handleRowResized = ({ rowIndex, height }) => {
    sheetMeta.value.rowHeights = { ...sheetMeta.value.rowHeights, [rowIndex]: height };
};

// При вставке строки на позицию `at` все абсолютные индексы строк >= at
// в merges/rowHeights должны сдвинуться на +1. Иначе подсветка merge'ев "уезжает".
const shiftMetaRowsOnInsert = (at) => {
    const merges = (sheetMeta.value.merges || []).map(m => {
        if (m.row >= at) return { ...m, row: m.row + 1 };
        // Если вставка попала ВНУТРЬ существующего merge — растягиваем его на одну строку.
        if (m.row < at && m.row + m.rowSpan > at) return { ...m, rowSpan: m.rowSpan + 1 };
        return m;
    });
    const rh = {};
    Object.entries(sheetMeta.value.rowHeights || {}).forEach(([k, v]) => {
        const r = parseInt(k, 10);
        if (Number.isNaN(r)) return;
        rh[r >= at ? r + 1 : r] = v;
    });
    sheetMeta.value.merges = merges;
    sheetMeta.value.rowHeights = rh;
};

// При удалении строки с индексом `at`: уменьшаем rowSpan мерджей, покрывающих её,
// удаляем те, что схлопнулись до 0 строк, сдвигаем индексы строк >= at на -1.
const shiftMetaRowsOnDelete = (at) => {
    const merges = [];
    (sheetMeta.value.merges || []).forEach(m => {
        const last = m.row + m.rowSpan - 1;
        if (at < m.row) {
            merges.push({ ...m, row: m.row - 1 });
        } else if (at >= m.row && at <= last) {
            const newSpan = m.rowSpan - 1;
            if (newSpan >= 1) merges.push({ ...m, rowSpan: newSpan });
            // newSpan === 0 — merge исчезает (был на одну строку и её удалили)
        } else {
            // at > last — merge выше удалённой строки, не трогаем
            merges.push(m);
        }
    });
    const rh = {};
    Object.entries(sheetMeta.value.rowHeights || {}).forEach(([k, v]) => {
        const r = parseInt(k, 10);
        if (Number.isNaN(r) || r === at) return;
        rh[r > at ? r - 1 : r] = v;
    });
    sheetMeta.value.merges = merges;
    sheetMeta.value.rowHeights = rh;
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

const undo = async () => {
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
        // Снимаем флаг после Vue-флаша/тика — это ловит синхронные ag-grid колбэки,
        // но не блокирует следующий Ctrl+Z дольше необходимого.
        await nextTick();
        isUndoing.value = false;
    }
};

const redo = async () => {
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
        await nextTick();
        isUndoing.value = false;
    }
};

// === Дебаунс-очередь записи изменений на сервер ===
// Каждый ввод символа в строке формул и каждое изменение ячейки порождают запрос.
// Без буферизации это: 1) топит сеть кучей POST'ов, 2) перерисовка после ответа
// убивает активный редактор. Копим уникальные строки и шлём один запрос раз/400мс.
const _pendingSyncRows = new Set();
let _pendingSyncTimer = null;
const SYNC_DEBOUNCE_MS = 400;

// Сборка тела запроса из буфера. Возвращает {url, payload} или null если нечего слать.
const _buildSyncPayload = () => {
    if (_pendingSyncRows.size === 0) return null;
    if (!props.activeSheet?.id) { _pendingSyncRows.clear(); return null; }
    const rowsToSync = Array.from(_pendingSyncRows);
    _pendingSyncRows.clear();
    const updatedRows = [];
    rowsToSync.forEach(row => {
        let idx = tableData.value.indexOf(row);
        if (idx === -1) idx = tableData.value.findIndex(r => r.id === row.id);
        if (idx !== -1) updatedRows.push({ row_index: idx, data: row });
    });
    if (updatedRows.length === 0) return null;
    return {
        url: route('sheets.updateData', props.activeSheet.id),
        rows: updatedRows,
    };
};

// Обычный flush через Inertia: с preserveState, обновляет props в случае ответа.
const _flushPendingSync = () => {
    if (_pendingSyncTimer) { clearTimeout(_pendingSyncTimer); _pendingSyncTimer = null; }
    const p = _buildSyncPayload();
    if (!p) return;
    router.post(p.url, { rows: p.rows }, { preserveScroll: true, preserveState: true });
};

// «Гарантированная» доставка для unload / Inertia 'before'-навигации:
// `fetch` с `keepalive: true` переживает закрытие вкладки и переход. Inertia-router
// этот запрос не отменяет (мы не используем router.post). Минус: ответ не обрабатывается
// — для последнего рывка перед уходом не критично.
const _csrfToken = () => {
    try {
        const meta = document.querySelector('meta[name="csrf-token"]');
        if (meta) return meta.getAttribute('content') || '';
    } catch (_) {}
    // Fallback на XSRF cookie (Laravel ставит её всегда).
    try {
        const m = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
        if (m) return decodeURIComponent(m[1]);
    } catch (_) {}
    return '';
};
const _flushPendingSyncBeacon = () => {
    if (_pendingSyncTimer) { clearTimeout(_pendingSyncTimer); _pendingSyncTimer = null; }
    const p = _buildSyncPayload();
    if (!p) return;
    const body = JSON.stringify({ rows: p.rows });
    try {
        // keepalive: запрос переживёт закрытие вкладки или Inertia-навигацию.
        fetch(p.url, {
            method: 'POST',
            keepalive: true,
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': _csrfToken(),
                'X-XSRF-TOKEN': _csrfToken(),
            },
            body,
        });
    } catch (_) {
        // Если fetch недоступен (очень старый браузер) — последний шанс sendBeacon.
        try {
            const blob = new Blob([body], { type: 'application/json' });
            navigator.sendBeacon?.(p.url + '?_token=' + encodeURIComponent(_csrfToken()), blob);
        } catch (_) {}
    }
};

const syncChangesToServer = (rows) => {
    if (!Array.isArray(rows)) rows = [rows];
    rows.forEach(r => { if (r) _pendingSyncRows.add(r); });
    if (_pendingSyncTimer) clearTimeout(_pendingSyncTimer);
    _pendingSyncTimer = setTimeout(_flushPendingSync, SYNC_DEBOUNCE_MS);
    // ВАЖНО: больше НЕ дёргаем redrawRows() здесь. Он убивал активный редактор
    // у пользователя. Vue-реактивность сама перерисует ячейки при мутации tableData.
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
    if (!props.canEdit) return;
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
    // Read-only safeguard. ag-grid с readOnly editable=false уже блокирует редактирование,
    // но если событие как-то прошло (программно, например), не записываем на сервер.
    if (!props.canEdit) return;

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
        // В общую дебаунс-очередь — единый канал записи на сервер.
        syncChangesToServer([data]);
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
    // Read-only safeguard.
    // export / import — доступны всем залогиненным юзерам.
    // import создаёт новые листы (юзер становится owner).
    // всё остальное — требует canEdit.
    if (type === 'export' || type === 'import') {
        // ok
    } else if (!props.canEdit) {
        return;
    }

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
    // === Найти — открывает Find&Replace независимо от выделения ===
    if (type === 'find') { openFindReplace(); return; }

    // === Сортировка — открывает диалог независимо от выделения ===
    if (type === 'sort') {
        const colDefault = activeCellInfo.value.colId || columnDefs.value[0]?.field;
        sortDialog.value = { show: true, column: colDefault, order: 'asc' };
        return;
    }
    if (type === 'sort-asc' || type === 'sort-desc') {
        sortDialog.value = { show: true, column: activeCellInfo.value.colId || columnDefs.value[0]?.field, order: type === 'sort-desc' ? 'desc' : 'asc' };
        applySortFromDialog();
        return;
    }

    // === Формат (Cells group): высота строки / ширина колонки — корректно сообщит о невыделенной ячейке ===
    if (type === 'format-rowHeight' || type === 'format-colWidth') {
        applyCellsFormat(type);
        return;
    }

    // === Действия, которым нужно выделение, но НЕ обязательно активная ячейка ===
    if (type === 'fill-down' || type === 'fill-up' || type === 'fill-right' || type === 'fill-left' || type === 'fill') {
        if (!currentSelection.value) { alert('Выделите диапазон ячеек.'); return; }
        const dir = type === 'fill-up' ? 'up' : type === 'fill-right' ? 'right' : type === 'fill-left' ? 'left' : 'down';
        applyFill(dir);
        return;
    }
    if (type === 'clear-all' || type === 'clear-formats' || type === 'clear-contents') {
        if (!currentSelection.value) { alert('Выделите ячейки, которые нужно очистить.'); return; }
        const what = type === 'clear-all' ? 'all' : type === 'clear-formats' ? 'formats' : 'contents';
        applyClear(what);
        return;
    }

    // === Автосумма — пишет формулу в текущую ячейку (нужна активная ячейка) ===
    if (type === 'autosum' || type.startsWith('autosum-')) {
        const { rowIndex, colId } = activeCellInfo.value;
        if (rowIndex == null || rowIndex < 1 || !colId) {
            alert('Поставьте курсор в ячейку ниже значений, по которым нужно посчитать.');
            return;
        }
        const fn = type === 'autosum' ? 'SUM' : type.split('-')[1].toUpperCase();
        const row = tableData.value[rowIndex];
        if (!row) return;
        saveUndoState([{ dataRef: row, field: colId, oldValue: row[colId], oldStyle: null }]);
        row[colId] = `=${fn}(${colId}1:${colId}${rowIndex})`;
        syncChangesToServer([row]);
        return;
    }

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
        // Сдвиг merges и rowHeights, чтобы они не "уехали" относительно данных.
        shiftMetaRowsOnInsert(at);
        const allRows = tableData.value.map((r, i) => ({ row_index: i, data: r }));
        router.post(route('sheets.updateData', props.activeSheet.id), { rows: allRows }, { preserveScroll: true, preserveState: true });
        tableApi.value?.refreshCells({ force: true });
        return;
    }

    // === Удалить строку ===
    if (type === 'deleteRow') {
        if (activeRow === null || tableData.value.length <= 1) return;
        tableData.value.splice(activeRow, 1);
        shiftMetaRowsOnDelete(activeRow);
        const allRows = tableData.value.map((r, i) => ({ row_index: i, data: r }));
        router.post(route('sheets.updateData', props.activeSheet.id), { rows: allRows }, { preserveScroll: true, preserveState: true });
        tableApi.value?.refreshCells({ force: true });
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
                case 'applyCellStyle': {
                    // Чистим все возможные предыдущие границы перед применением пресета,
                    // иначе CSS-shorthand "border" + оставшиеся "borderTop/Bottom/Left/Right"
                    // дают непредсказуемый результат (shorthand сбрасывает longhand или наоборот).
                    const clearBorders = () => {
                        delete style.border;
                        delete style.borderTop; delete style.borderBottom;
                        delete style.borderLeft; delete style.borderRight;
                    };
                    // Шорткат для «общих границ всех 4 сторон» через longhand (без shorthand-конфликта).
                    const setAllSides = (val) => {
                        style.borderTop = val; style.borderBottom = val;
                        style.borderLeft = val; style.borderRight = val;
                    };
                    clearBorders();
                    if (value === 'normal') {
                        style.backgroundColor = 'transparent'; style.color = '#000000';
                        style.fontWeight = 'normal'; style.fontStyle = 'normal'; style.fontSize = '11px';
                    } else if (value === 'neutral') {
                        style.backgroundColor = '#ffeb9c'; style.color = '#9c6500';
                    } else if (value === 'bad') {
                        style.backgroundColor = '#ffc7ce'; style.color = '#9c0006';
                    } else if (value === 'good') {
                        style.backgroundColor = '#c6efce'; style.color = '#006100';
                    } else if (value === 'input') {
                        style.backgroundColor = '#f2dddc'; style.color = '#e26b0a'; setAllSides('1px solid #7f7f7f');
                    } else if (value === 'output') {
                        style.backgroundColor = '#f2f2f2'; style.color = '#3f3f3f'; setAllSides('1px solid #3f3f3f'); style.fontWeight = 'bold';
                    } else if (value === 'calc') {
                        style.backgroundColor = '#ffffff'; style.color = '#fa7d00'; setAllSides('1px solid #7f7f7f'); style.fontWeight = 'bold';
                    } else if (value === 'check') {
                        style.backgroundColor = '#a5a5a5'; style.color = '#ffffff'; setAllSides('2px solid #3f3f3f'); style.fontWeight = 'bold';
                    } else if (value === 'explain') {
                        style.backgroundColor = 'transparent'; style.color = '#7f7f7f'; style.fontStyle = 'italic';
                    } else if (value === 'note') {
                        style.backgroundColor = '#ffffcc'; style.color = '#000000'; setAllSides('1px solid #b2b2b2');
                    } else if (value === 'linked') {
                        style.backgroundColor = 'transparent'; style.color = '#fa7d00'; style.borderBottom = '2px solid #ff8001';
                    } else if (value === 'warning') {
                        style.backgroundColor = 'transparent'; style.color = '#ff0000';
                    } else if (value === 'heading1') {
                        style.fontSize = '24px'; style.fontWeight = 'bold'; style.color = '#000000'; style.borderBottom = '2px solid #4f81bd';
                    } else if (value === 'heading2') {
                        style.fontSize = '18px'; style.fontWeight = 'bold'; style.color = '#000000'; style.borderBottom = '2px solid #4f81bd';
                    } else if (value === 'heading3') {
                        style.fontSize = '14px'; style.fontWeight = 'bold'; style.color = '#000000'; style.borderBottom = '2px solid #a5b592';
                    } else if (value === 'heading4') {
                        style.fontSize = '12px'; style.fontWeight = 'bold'; style.color = '#000000';
                    } else if (value === 'total') {
                        style.fontWeight = 'bold'; style.borderBottom = '3px double #4f81bd'; style.borderTop = '1px solid #4f81bd';
                    } else if (value === 'title') {
                        style.fontSize = '28px'; style.fontWeight = 'bold'; style.color = '#000000';
                    }
                    break;
                }
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
    if (!props.canEdit) return;
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

const handleMenuAction = async (action, value) => {
    // Read-only режим: разрешаем только copy.
    if (!props.canEdit && action !== 'copy') return;
    const params = cellContextMenu.value.params;
    if (!params) return;
    const field = params.column?.getColId();
    const row = params.node?.data;
    if (!row || !field) return;

    // === Границы — делегируем в Ribbon (применит к выделению или одной ячейке) ===
    if (action === 'border') {
        handleRibbonAction({ type: 'border', value });
        return;
    }

    // Действия, применяющиеся ко всему диапазону — делегируем в Ribbon
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
        handleRibbonAction({ type: action });
        return;
    }

    // === Действия, использующие Ribbon (для согласованности с тулбаром) ===
    if (action === 'insert') { handleRibbonAction({ type: 'insertRow' }); return; }

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
            case 'paste-special':
                // «Специальная вставка» — только значения, без стилей
                if (internalClipboard.value) {
                    row[field] = internalClipboard.value.value;
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
                    row[field + '_style'] = null;
                    syncChangesToServer([row]);
                }
                break;
            case 'hyperlink': {
                const url = prompt('Введите URL гиперссылки:', String(row[field] ?? 'https://'));
                if (url) {
                    row[field] = url;
                    if (!row[field + '_style']) row[field + '_style'] = {};
                    row[field + '_style'].color = '#2563eb';
                    row[field + '_style'].textDecoration = 'underline';
                    syncChangesToServer([row]);
                }
                break;
            }
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
const openTabContextMenu = (event, sheet) => {
    // Контекстное меню вкладки (переименовать/скрыть/удалить) — только для админа.
    if (!props.isAdmin) return;
    // Excel-style: меню открывается в точке клика, но "вылетает" вверх,
    // если снизу не хватает места (вкладки обычно у нижнего края).
    const MENU_W = 180;
    const MENU_H = 110; // 3 пункта по ~36px + рамки
    const vw = window.innerWidth;
    const vh = window.innerHeight;
    let x = event.clientX;
    let y = event.clientY;
    if (x + MENU_W > vw - 4) x = Math.max(4, vw - MENU_W - 4);
    if (y + MENU_H > vh - 4) y = Math.max(4, y - MENU_H);
    tabContextMenu.value = { show: true, x, y, sheet };
};
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

        // Не-админу права раздавать нельзя — он стал owner новых листов и может их
        // редактировать сам. Просто открываем первый импортированный лист.
        if (!props.isAdmin) {
            const msg = failed > 0
                ? `Создано листов: ${created.length}. Ошибок: ${failed}.`
                : `Создано листов: ${created.length}.`;
            alert(msg);
            router.visit(route('dashboard', { sheet_id: created[0] }));
            return;
        }

        // Админу открываем модалку «Назначить права» для всех созданных листов.
        // Если её закрыть/пропустить — просто перейдём на первый импортированный лист.
        try {
            // Подтянем список юзеров через permissions endpoint первого нового листа.
            const r = await axios.get(route('sheets.permissions', created[0]));
            bulkPermissions.users = (r.data?.allUsers || []).map(u => ({
                id: u.id, name: u.name, email: u.email, role: 'none'
            }));
        } catch (_) { bulkPermissions.users = []; }

        bulkPermissions.sheetIds = created;
        bulkPermissions.sheetNames = wb.sheets.slice(0, created.length).map(s => s.name);
        bulkPermissions.failed = failed;
        bulkPermissions.show = true;
    } catch (err) {
        console.error(err);
        alert('Не удалось прочитать файл: ' + (err?.message || err));
    }
};

// --- Bulk-permissions модалка после импорта ---
const bulkPermissions = reactive({
    show: false,
    sheetIds: [],
    sheetNames: [],
    failed: 0,
    users: [],     // [{id, name, email, role}]
    saving: false
});

const submitBulkPermissions = async () => {
    if (bulkPermissions.saving) return;
    bulkPermissions.saving = true;
    try {
        // Отправляем по одному запросу на юзера со всеми листами.
        // Юзеров с role=none пропускаем (детач не нужен — листы только что созданы, их там нет).
        const tasks = bulkPermissions.users
            .filter(u => u.role && u.role !== 'none')
            .map(u => axios.post(route('sheets.permissions.bulk'), {
                sheet_ids: bulkPermissions.sheetIds,
                user_id: u.id,
                role: u.role
            }));
        await Promise.all(tasks);
    } catch (err) {
        console.error(err);
        alert('Часть прав не удалось назначить. См. консоль.');
    } finally {
        bulkPermissions.saving = false;
        const firstId = bulkPermissions.sheetIds[0];
        bulkPermissions.show = false;
        router.visit(route('dashboard', { sheet_id: firstId }));
    }
};

const skipBulkPermissions = () => {
    const firstId = bulkPermissions.sheetIds[0];
    bulkPermissions.show = false;
    router.visit(route('dashboard', { sheet_id: firstId }));
};

const exportXlsx = async () => {
    if (!props.activeSheet) {
        alert('Откройте лист, который хотите скачать.');
        return;
    }
    const allSheets = props.sheets || [];
    const activeId = props.activeSheet.id;

    // Локальная мета (merges/validations/colWidths/rowHeights) для каждого листа
    // живёт в localStorage. Для активного — берём sheetMeta.value (актуальная копия).
    const metaFor = (id) => {
        if (id === activeId) return sheetMeta.value;
        try { return JSON.parse(localStorage.getItem(`excel_sheet_meta_${id}`) || '{}'); }
        catch (_) { return {}; }
    };

    // rowData неактивных листов не лежит в памяти браузера → дотягиваем GET-запросами.
    // Используем Promise.allSettled, чтобы один упавший лист не валил весь экспорт.
    const results = await Promise.allSettled(allSheets.map(async (s) => {
        let rowData;
        let cols;
        if (s.id === activeId) {
            rowData = tableData.value;
            cols = columnDefs.value;
        } else {
            const resp = await axios.get(route('sheets.fetchData', s.id));
            rowData = resp.data?.rows || [];
            cols = (resp.data?.columns && resp.data.columns.length)
                ? resp.data.columns
                : (s.columns && s.columns.length ? s.columns : []);
        }
        const m = metaFor(s.id);
        return {
            name: s.name,
            hidden: !!m.hidden,
            columnDefs: cols,
            rowData,
            merges: m.merges || [],
            validations: m.validations || {},
            colWidths: m.colWidths || {},
            rowHeights: m.rowHeights || {},
        };
    }));

    const sheetsForExport = [];
    const failed = [];
    results.forEach((r, idx) => {
        if (r.status === 'fulfilled') sheetsForExport.push(r.value);
        else failed.push(allSheets[idx]?.name || `#${idx}`);
    });

    if (sheetsForExport.length === 0) {
        alert('Не удалось загрузить ни одного листа: ' + failed.join(', '));
        return;
    }
    if (failed.length > 0) {
        if (!confirm(`Не удалось загрузить листы: ${failed.join(', ')}.\nСкачать файл без них?`)) return;
    }

    const filename = (props.activeSheet.name || 'export').replace(/[/\\?%*:|"<>]/g, '_') + '.xlsx';
    try {
        await writeXlsxFile(filename, sheetsForExport);
    } catch (err) {
        console.error(err);
        alert('Не удалось сохранить файл: ' + (err?.message || err));
    }
};

const handleGlobalKeydown = (e) => {
    // Esc — закрыть контекстное меню вкладки. Работает даже в полях ввода.
    if (e.key === 'Escape' && tabContextMenu.value.show) {
        closeContextMenu();
        return;
    }
    if (!e.ctrlKey) return;

    // Любой Ctrl-хоткей внутри редактируемого поля (input, textarea, AG Grid-попап,
    // contenteditable, в т.ч. через Shadow DOM) — пропускаем нативному браузеру:
    // Ctrl+Z откатывает текст в инпуте, не данные таблицы.
    const path = (typeof e.composedPath === 'function') ? e.composedPath() : [e.target];
    let inField = false;
    for (const el of path) {
        if (!el || el === window || el === document) continue;
        const tag = el.tagName;
        if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || el.isContentEditable) { inField = true; break; }
        const cl = el.classList;
        if (cl && (cl.contains('ag-filter') || cl.contains('ag-menu') || cl.contains('ag-popup'))) { inField = true; break; }
    }
    if (inField) return;

    const k = e.key.toLowerCase();
    // Read-only safe (просто читают данные).
    if (k === 'h' || k === 'р' || k === 'f' || k === 'а') {
        e.preventDefault(); openFindReplace(); return;
    }
    if (k === 'c' || k === 'с') {
        e.preventDefault(); handleRibbonAction({ type: 'copy' }); return;
    }

    // Всё остальное — мутации, требуют canEdit.
    if (!props.canEdit) return;

    if (k === 'z' || k === 'я') { e.preventDefault(); undo(); return; }
    if (k === 'y' || k === 'н') { e.preventDefault(); redo(); return; }
    if (k === 'x' || k === 'ч') { e.preventDefault(); handleRibbonAction({ type: 'cut' }); return; }
    if (k === 'v' || k === 'м') { e.preventDefault(); handleRibbonAction({ type: 'paste' }); return; }
    if (k === 'b' || k === 'и') { e.preventDefault(); handleRibbonAction({ type: 'bold' }); return; }
    if (k === 'i' || k === 'ш') { e.preventDefault(); handleRibbonAction({ type: 'italic' }); return; }
    if (k === 'u' || k === 'г') { e.preventDefault(); handleRibbonAction({ type: 'underline' }); return; }
};

const handleGlobalClick = () => {
    if (tabContextMenu.value.show) closeContextMenu();
};

// Перед навигацией Inertia (смена листа, выход) — досылаем буфер несохранённых правок.
let _removeInertiaBefore = null;

onMounted(() => {
    window.addEventListener('keydown', handleGlobalKeydown);
    window.addEventListener('click', handleGlobalClick);
    // Закрытие вкладки / навигация — досылаем буфер через keepalive-fetch,
    // который не отменяется Inertia/браузером при unload.
    window.addEventListener('beforeunload', _flushPendingSyncBeacon);
    _removeInertiaBefore = router.on('before', () => { _flushPendingSyncBeacon(); });
});

onUnmounted(() => {
    // При размонтировании самого Dashboard отправляем синхронно через keepalive —
    // обычный router.post Inertia может отменить.
    _flushPendingSyncBeacon();
    window.removeEventListener('keydown', handleGlobalKeydown);
    window.removeEventListener('click', handleGlobalClick);
    window.removeEventListener('beforeunload', _flushPendingSyncBeacon);
    if (_removeInertiaBefore) _removeInertiaBefore();
});
</script>

<template>
    <Head title="Таблицы" />
    <input type="file" ref="fileInput" accept=".xlsx,.xls" class="hidden" @change="handleFileChosen" />
    <div class="h-screen w-screen flex flex-col overflow-hidden bg-[#f3f2f1] text-[#323130] font-sans">
        <div class="bg-[#2563eb] text-white px-4 py-1 flex items-center justify-between text-xs h-9 shrink-0">
            <div class="flex items-center gap-4">
                <div class="flex items-center gap-1 font-bold">
                    <svg class="w-5 h-5" viewBox="0 0 24 24" fill="white"><path d="M16.2,2H7.8C6.8,2,6,2.8,6,3.8v16.4C6,21.2,6.8,22,7.8,22h8.4c1,0,1.8-0.8,1.8-1.8V3.8C18,2.8,17.2,2,16.2,2z M12,19 c-0.6,0-1-0.4-1-1s0.4-1,1-1s1,0.4,1,1S12.6,19,12,19z M15,16H9v-2h6V16z M15,12H9v-2h6V12z M15,8H9V6h6V8z"/></svg>
                    <span class="text-[14px]">Excel Online</span>
                </div>
                <div class="h-4 w-[1px] bg-white/30 mx-2"></div>
                <div class="hover:bg-white/10 px-2 py-1 rounded cursor-pointer text-sm font-medium">{{ activeSheet?.name }} - Сохранено</div>
            </div>
            <div class="flex items-center gap-3">
                <span v-if="!canEdit && activeSheet" class="bg-yellow-300/80 text-yellow-900 text-xs px-2 py-1 rounded font-semibold">
                    Только просмотр
                </span>
                <span v-if="isAdmin && activeSheet?.owner" class="bg-white/10 text-xs px-2 py-1 rounded">
                    Импортировал: <b>{{ activeSheet.owner.name }}</b>
                </span>
                <button @click="triggerImport" class="bg-white/10 hover:bg-white/20 px-3 py-1 rounded transition-colors text-sm flex items-center gap-1.5" title="Загрузить .xlsx с компьютера">
                    <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5"/>
                        <path d="M16.5 12L12 7.5 7.5 12"/>
                        <path d="M12 7.5V18"/>
                    </svg>
                    Импорт
                </button>
                <button v-if="activeSheet" @click="handleRibbonAction({ type: 'export' })" class="bg-white/10 hover:bg-white/20 px-3 py-1 rounded transition-colors text-sm flex items-center gap-1.5" title="Скачать как .xlsx">
                    <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5"/>
                        <path d="M7.5 12l4.5 4.5 4.5-4.5"/>
                        <path d="M12 3v13.5"/>
                    </svg>
                    Скачать
                </button>
                <Link v-if="isAdmin" :href="route('users.index')" class="bg-white/10 hover:bg-white/20 px-3 py-1 rounded transition-colors text-sm">Пользователи</Link>
                <button v-if="isAdmin" @click="openPermissionsModal" class="bg-white/10 hover:bg-white/20 px-3 py-1 rounded transition-colors">Права доступа</button>
                <div class="flex items-center gap-2">
                    <span class="text-sm font-medium">{{ $page.props.auth?.user?.name || 'User' }}</span>
                    <div class="w-8 h-8 bg-blue-600 rounded-full flex items-center justify-center font-bold border border-white/20">{{ ($page.props.auth?.user?.name || '?').charAt(0).toUpperCase() }}</div>
                </div>
            </div>
        </div>

        <div v-if="canEdit" :class="{ 'pointer-events-none opacity-50': !canEdit }">
            <ExcelRibbon @action="handleRibbonAction" :activeCell="activeCellInfo" />
        </div>

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
                    <div class="text-[#2563eb] font-serif italic font-bold px-1 cursor-pointer">fx</div>
                </div>
                <input v-model="currentCellData.value" type="text" :readonly="!canEdit"
                       :class="['flex-1 border-none focus:ring-0 py-0.5 text-sm px-2', !canEdit && 'bg-gray-50 text-gray-500']" />
            </div>

            <div class="overflow-hidden relative bg-white" style="height: calc(100vh - 200px);" @wheel="onWheelZoom">
                <div v-if="!activeSheet" class="h-full flex items-center justify-center text-gray-500 text-sm flex-col gap-3">
                    <svg class="w-12 h-12 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 13h6m-3-3v6m-9 1V7a2 2 0 012-2h4l2 2h8a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2z"/></svg>
                    <div v-if="isAdmin" class="text-center">Создайте новый лист «+» или импортируйте .xlsx.</div>
                    <div v-else class="text-center">
                        У вас пока нет листов.<br>
                        Импортируйте .xlsx — вы станете владельцем и сможете его редактировать.<br>
                        <span class="text-xs text-gray-400">Чтобы получить доступ к чужим листам — обратитесь к администратору.</span>
                    </div>
                    <button @click="triggerImport" class="px-4 py-2 text-sm rounded bg-[#2563eb] text-white hover:bg-[#1d4ed8] flex items-center gap-2">
                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5"/>
                            <path d="M16.5 12L12 7.5 7.5 12"/>
                            <path d="M12 7.5V18"/>
                        </svg>
                        Импортировать .xlsx
                    </button>
                </div>
                <div v-if="activeSheet" class="w-full h-full" :style="{ zoom: zoom / 100 }">
                    <ExcelTable :rowData="tableDataForGrid" :columnDefs="columnDefs"
                        :pinnedTopRowData="pinnedTopRowData" :freezeRow="!!sheetMeta.freezeRow"
                        :merges="sheetMeta.merges" :validations="sheetMeta.validations"
                        :colWidths="sheetMeta.colWidths" :rowHeights="sheetMeta.rowHeights"
                        :readOnly="!canEdit"
                        @cell-value-changed="handleCellValueChanged" @cell-focused="handleCellFocused"
                        @cell-context-menu="handleCellContextMenu" @selection-changed="handleSelectionChanged"
                        @range-clear="handleRangeClear" @ready="handleTableReady"
                        @grow-rows="handleGrowRows" @grow-cols="handleGrowCols"
                        @column-resized="handleColumnResized" @row-resized="handleRowResized" />
                </div>
                <ExcelContextMenu v-if="cellContextMenu.show" :x="cellContextMenu.x" :y="cellContextMenu.y" :cellData="cellContextMenu.params"
                    :canEdit="canEdit" :hasClipboard="!!internalClipboard"
                    @close="cellContextMenu.show = false" @action="handleMenuAction" />
            </div>

            <div class="flex flex-col select-none shrink-0 bg-[#f3f2f1]">
                <div class="bg-[#f3f2f1] border-t border-gray-300 flex items-center h-[32px] overflow-hidden">
                    <div class="flex-1 flex items-center overflow-x-auto no-scrollbar h-full">
                        <div v-for="(sheet, index) in visibleSheets" :key="sheet.id" class="h-full flex items-center">
                            <div class="h-full flex items-center relative transition-colors" :class="[sheet.id === activeSheet?.id ? 'bg-white' : 'bg-transparent hover:bg-gray-200', isSheetHidden(sheet.id) ? 'opacity-50' : '']" @contextmenu.prevent="openTabContextMenu($event, sheet)">
                                <input v-if="editingSheetId === sheet.id" v-model="newSheetName" @blur="saveSheetName(sheet)" @keyup.enter="saveSheetName(sheet)" class="border-none focus:ring-0 outline-none px-4 py-0 w-24 text-[11px] font-bold bg-white h-full" v-focus />
                                <button v-else @click="router.visit(route('dashboard', { sheet_id: sheet.id }))" @dblclick="isAdmin && startEditing(sheet)" class="px-5 h-full text-[11px] whitespace-nowrap" :class="sheet.id === activeSheet?.id ? 'text-[#2563eb] font-bold shadow-[0_-2px_0_0_#2563eb_inset]' : 'text-gray-700'">
                                    {{ sheet.name }}<span v-if="isSheetHidden(sheet.id)" class="ml-1 text-[9px] text-gray-500">(скрыт)</span>
                                </button>
                            </div>
                            <div v-if="index < visibleSheets.length - 1 && sheet.id !== activeSheet?.id && visibleSheets[index+1].id !== activeSheet?.id" class="h-4 w-[1px] bg-gray-400"></div>
                        </div>
                        <button v-if="isAdmin" @click="router.post(route('sheets.store'))" class="ml-2 w-6 h-6 flex items-center justify-center rounded-full hover:bg-gray-300 text-gray-500 transition-colors" title="Добавить лист"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" /></svg></button>
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
                    <div class="flex items-center gap-2 select-none">
                        <button @click="zoomOut" :disabled="zoom <= ZOOM_MIN" class="w-5 h-5 flex items-center justify-center hover:bg-gray-300 rounded disabled:opacity-40 text-gray-700" title="Уменьшить">−</button>
                        <div class="w-32 h-1 bg-gray-300 relative cursor-pointer rounded" @mousedown="onZoomSliderDown" title="Масштаб">
                            <div class="absolute top-[-4px] w-[10px] h-[10px] bg-white border border-gray-500 rounded-full pointer-events-none shadow"
                                 :style="{ left: `calc(${(zoom - ZOOM_MIN) / (ZOOM_MAX - ZOOM_MIN) * 100}% - 5px)` }"></div>
                        </div>
                        <button @click="zoomIn" :disabled="zoom >= ZOOM_MAX" class="w-5 h-5 flex items-center justify-center hover:bg-gray-300 rounded disabled:opacity-40 text-gray-700" title="Увеличить">＋</button>
                        <button @click="openZoomDialog" class="ml-1 min-w-[42px] text-right hover:bg-gray-300 rounded px-1" title="Параметры масштаба">{{ zoom }}%</button>
                    </div>
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
                        <input v-model="findText" type="text" class="flex-1 border border-gray-300 rounded px-2 py-1 text-sm focus:outline-none focus:border-[#2563eb]" @keyup.enter="findNext()" />
                    </div>
                    <div class="flex items-center gap-2">
                        <label class="text-xs w-16 text-gray-600">Заменить:</label>
                        <input v-model="replaceText" type="text" class="flex-1 border border-gray-300 rounded px-2 py-1 text-sm focus:outline-none focus:border-[#2563eb]" />
                    </div>
                    <label class="flex items-center gap-2 text-xs text-gray-700">
                        <input type="checkbox" v-model="findCaseSensitive" /> Учитывать регистр
                    </label>
                    <div class="text-xs text-gray-500 min-h-[16px]">{{ findStatus }}</div>
                </div>
                <div class="flex gap-2 mt-3 justify-end">
                    <button @click="findNext()" class="px-3 py-1 text-sm rounded bg-gray-100 hover:bg-gray-200">Найти далее</button>
                    <button @click="replaceCurrent()" class="px-3 py-1 text-sm rounded bg-gray-100 hover:bg-gray-200">Заменить</button>
                    <button @click="replaceAll()" class="px-3 py-1 text-sm rounded bg-[#2563eb] text-white hover:bg-[#1d4ed8]">Заменить все</button>
                    <button @click="showFindReplace = false" class="px-3 py-1 text-sm rounded bg-gray-100 hover:bg-gray-200">Закрыть</button>
                </div>
            </div>
        </div>

        <!-- Диалог "Масштаб" (как в Excel: пресеты + произвольный) -->
        <div v-if="showZoomDialog" class="fixed inset-0 z-50 flex items-center justify-center bg-black/30" @click.self="showZoomDialog = false">
            <div class="bg-white rounded-lg shadow-xl w-[300px] p-4">
                <h3 class="font-bold text-sm mb-3">Масштаб</h3>
                <div class="grid grid-cols-3 gap-2 mb-3">
                    <button v-for="p in [200, 100, 75, 50, 25]" :key="p"
                            @click="setZoom(p); showZoomDialog = false"
                            class="px-2 py-1 text-sm border border-gray-300 rounded hover:bg-gray-100"
                            :class="{ 'bg-[#2563eb] text-white border-[#2563eb]': zoom === p }">{{ p }}%</button>
                </div>
                <div class="flex items-center gap-2 mb-3">
                    <span class="text-sm">Произвольный:</span>
                    <input type="number" :min="ZOOM_MIN" :max="ZOOM_MAX" v-model.number="zoomDialogValue"
                           @keyup.enter="setZoom(zoomDialogValue); showZoomDialog = false"
                           class="border border-gray-300 rounded w-20 px-1 text-sm" />
                    <span class="text-sm">%</span>
                </div>
                <div class="flex justify-end gap-2">
                    <button @click="showZoomDialog = false" class="px-3 py-1 text-sm rounded bg-gray-200 hover:bg-gray-300">Отмена</button>
                    <button @click="setZoom(zoomDialogValue); showZoomDialog = false" class="px-3 py-1 text-sm rounded bg-[#2563eb] text-white hover:bg-[#1d4ed8]">ОК</button>
                </div>
            </div>
        </div>

        <!-- Диалог "Сортировка" -->
        <div v-if="sortDialog.show" class="fixed inset-0 z-50 flex items-center justify-center bg-black/30" @click.self="sortDialog.show = false">
            <div class="bg-white rounded-lg shadow-xl w-[360px] p-4">
                <h3 class="font-bold text-sm mb-3">Сортировка</h3>
                <div class="space-y-3 text-sm">
                    <div>
                        <label class="block text-xs text-gray-600 mb-1">Сортировать по столбцу</label>
                        <select v-model="sortDialog.column" class="w-full border border-gray-300 rounded px-2 py-1 text-sm">
                            <option v-for="c in columnDefs" :key="c.field" :value="c.field">{{ c.headerName || c.field }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs text-gray-600 mb-1">Порядок</label>
                        <div class="flex gap-3">
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="radio" v-model="sortDialog.order" value="asc" /> По возрастанию (А→Я / 0→9)
                            </label>
                        </div>
                        <div class="flex gap-3 mt-1">
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="radio" v-model="sortDialog.order" value="desc" /> По убыванию (Я→А / 9→0)
                            </label>
                        </div>
                    </div>
                </div>
                <div class="mt-4 flex justify-end gap-2">
                    <button @click="sortDialog.show = false" class="px-3 py-1 text-sm rounded bg-gray-200 hover:bg-gray-300">Отмена</button>
                    <button @click="applySortFromDialog" class="px-3 py-1 text-sm rounded bg-[#2563eb] text-white hover:bg-[#1d4ed8]">Сортировать</button>
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

        <!-- Модалка bulk-назначения прав после импорта -->
        <div v-if="bulkPermissions.show" class="fixed inset-0 z-50 flex items-start justify-center pt-12 bg-black/40">
            <div class="bg-white rounded-lg shadow-xl w-[640px] max-h-[85vh] flex flex-col">
                <div class="p-4 border-b">
                    <h3 class="font-bold text-base">Назначить права на новые листы</h3>
                    <p class="text-xs text-gray-600 mt-1">
                        Импортировано листов: <b>{{ bulkPermissions.sheetIds.length }}</b><span v-if="bulkPermissions.failed > 0">, ошибок: <b>{{ bulkPermissions.failed }}</b></span>.
                        Выбранная роль будет применена ко всем перечисленным ниже листам сразу.
                    </p>
                    <details class="mt-2">
                        <summary class="text-xs text-gray-500 cursor-pointer">Список листов ({{ bulkPermissions.sheetNames.length }})</summary>
                        <ul class="text-xs text-gray-600 mt-1 list-disc pl-5 max-h-24 overflow-y-auto">
                            <li v-for="(n, i) in bulkPermissions.sheetNames" :key="i">{{ n }}</li>
                        </ul>
                    </details>
                </div>
                <div class="flex-1 overflow-y-auto p-4">
                    <div v-if="bulkPermissions.users.length === 0" class="text-sm text-gray-500 italic">
                        Нет других пользователей в системе. Создайте их на странице «Пользователи».
                    </div>
                    <table v-else class="w-full text-sm">
                        <thead class="text-left">
                            <tr class="border-b">
                                <th class="pb-2">Пользователь</th>
                                <th class="pb-2 w-44">Роль на этих листах</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="u in bulkPermissions.users" :key="u.id" class="border-b last:border-0">
                                <td class="py-2">
                                    <div class="font-medium">{{ u.name }}</div>
                                    <div class="text-xs text-gray-500">{{ u.email }}</div>
                                </td>
                                <td class="py-2">
                                    <select v-model="u.role" class="text-sm border-gray-300 rounded p-1 w-full">
                                        <option value="none">Нет доступа</option>
                                        <option value="viewer">Просмотр</option>
                                        <option value="editor">Редактирование</option>
                                    </select>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="p-4 border-t flex items-center justify-end gap-2">
                    <button @click="skipBulkPermissions" :disabled="bulkPermissions.saving"
                            class="px-3 py-1.5 text-sm rounded bg-gray-200 hover:bg-gray-300">Пропустить</button>
                    <button @click="submitBulkPermissions" :disabled="bulkPermissions.saving"
                            class="px-4 py-1.5 text-sm rounded bg-[#2563eb] text-white hover:bg-[#1d4ed8] disabled:opacity-50">
                        {{ bulkPermissions.saving ? 'Сохранение…' : 'Назначить и открыть' }}
                    </button>
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

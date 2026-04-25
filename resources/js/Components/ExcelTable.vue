<script setup>
import { ref, watch, onMounted, computed } from 'vue';
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
});

const emit = defineEmits(['cell-value-changed', 'cell-focused', 'cell-context-menu', 'selection-changed', 'ready', 'range-clear']);
const gridApi = ref(null);

const hf = HyperFormula.buildEmpty({ licenseKey: 'gpl-v3' });

const onGridReady = (params) => {
    gridApi.value = params.api;
    emit('ready', params.api);
    setTimeout(() => updateHFData(), 0);
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
    const rowNode = gridApi.value.getDisplayedRowAtIndex(rowIndex);
    const rowData = rowNode?.data;
    const rawValue = rowData ? rowData[field] : '';

    // Перерисовываем для подсветки заголовков
    gridApi.value?.refreshHeader();
    gridApi.value?.refreshCells({ columns: ['row_num'], force: true });

    emit('cell-focused', { 
        ...event, 
        field: field,
        rowIndex: rowIndex,
        rawValue: rawValue, 
        position: `${field}${rowIndex + 1}`,
        rowData: rowData
    });
};

const onCellContextMenu = (params) => {
    params.event.preventDefault();
    emit('cell-context-menu', params);
};

const onCellValueChanged = (event) => {
    updateHFData();
    setTimeout(() => gridApi.value?.refreshCells(), 0);
    emit('cell-value-changed', event);
};

// Range Selection Logic
const onCellMouseDown = (params) => {
    isSelecting.value = true;
    const colIndex = props.columnDefs.findIndex(c => c.field === params.colDef.field);
    selectionStart.value = { row: params.node.rowIndex, col: colIndex, rowData: params.node.data };
    selectionEnd.value = { row: params.node.rowIndex, col: colIndex, rowData: params.node.data };
    
    // Принудительно вызываем фокус, чтобы Dashboard узнал об активной ячейке
    onCellFocused({
        column: params.column,
        rowIndex: params.node.rowIndex,
        node: params.node
    });

    emit('selection-changed', { start: selectionStart.value, end: selectionEnd.value });
    gridApi.value?.refreshCells({ suppressFlash: true });
};

const onCellMouseOver = (params) => {
    if (!isSelecting.value) return;
    const colIndex = props.columnDefs.findIndex(c => c.field === params.colDef.field);
    if (selectionEnd.value?.row !== params.node.rowIndex || selectionEnd.value?.col !== colIndex) {
        selectionEnd.value = { row: params.node.rowIndex, col: colIndex, rowData: params.node.data };
        
        // Обновляем для подсветки заголовков при растягивании
        gridApi.value?.refreshHeader();
        gridApi.value?.refreshCells({ columns: ['row_num'], force: true });
        
        emit('selection-changed', { start: selectionStart.value, end: selectionEnd.value });
        gridApi.value?.refreshCells({ suppressFlash: true });
    }
};

onMounted(() => {
    window.addEventListener('mouseup', () => { isSelecting.value = false; });
});

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

    // 2. Навигация с Shift
    if (e.shiftKey && ['ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight'].includes(e.key)) {
        if (!selectionStart.value || !selectionEnd.value) return;
        e.preventDefault();
        
        let { row, col } = selectionEnd.value;
        if (e.key === 'ArrowUp') row = Math.max(0, row - 1);
        if (e.key === 'ArrowDown') row = Math.min(props.rowData.length - 1, row + 1);
        if (e.key === 'ArrowLeft') col = Math.max(0, col - 1);
        if (e.key === 'ArrowRight') col = Math.min(props.columnDefs.length - 1, col + 1);
        
        selectionEnd.value = { 
            row, 
            col, 
            rowData: gridApi.value.getDisplayedRowAtIndex(row)?.data 
        };
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

const isCellSelected = (rowIndex, colField) => {
    if (!selectionStart.value || !selectionEnd.value) return false;
    const colIndex = colIndexMap.value[colField];
    if (colIndex === undefined) return false;

    const sR = Math.min(selectionStart.value.row, selectionEnd.value.row);
    const eR = Math.max(selectionStart.value.row, selectionEnd.value.row);
    const sC = Math.min(selectionStart.value.col, selectionEnd.value.col);
    const eC = Math.max(selectionStart.value.col, selectionEnd.value.col);
    
    return rowIndex >= sR && rowIndex <= eR && colIndex >= sC && colIndex <= eC;
};

const isCellActive = (rowIndex, colField) => {
    return selectionStart.value && selectionStart.value.row === rowIndex && 
           props.columnDefs[selectionStart.value.col]?.field === colField;
};

const isCellOnEdge = (rowIndex, colField, edge) => {
    if (!selectionStart.value || !selectionEnd.value) return false;
    const colIndex = colIndexMap.value[colField];
    const sR = Math.min(selectionStart.value.row, selectionEnd.value.row);
    const eR = Math.max(selectionStart.value.row, selectionEnd.value.row);
    const sC = Math.min(selectionStart.value.col, selectionEnd.value.col);
    const eC = Math.max(selectionStart.value.col, selectionEnd.value.col);
    
    if (edge === 'top') return rowIndex === sR && colIndex >= sC && colIndex <= eC;
    if (edge === 'bottom') return rowIndex === eR && colIndex >= sC && colIndex <= eC;
    if (edge === 'left') return colIndex === sC && rowIndex >= sR && rowIndex <= eR;
    if (edge === 'right') return colIndex === eC && rowIndex >= sR && rowIndex <= eR;
    if (edge === 'corner') return rowIndex === eR && colIndex === eC; // Right-bottom corner for handle
    return false;
};

const cellClassRules = {
    'excel-range-selected': (params) => isCellSelected(params.node.rowIndex, params.colDef.field),
    'excel-active-cell': (params) => isCellActive(params.node.rowIndex, params.colDef.field),
    'excel-range-top': (params) => isCellOnEdge(params.node.rowIndex, params.colDef.field, 'top'),
    'excel-range-bottom': (params) => isCellOnEdge(params.node.rowIndex, params.colDef.field, 'bottom'),
    'excel-range-left': (params) => isCellOnEdge(params.node.rowIndex, params.colDef.field, 'left'),
    'excel-range-right': (params) => isCellOnEdge(params.node.rowIndex, params.colDef.field, 'right'),
    'excel-range-corner': (params) => isCellOnEdge(params.node.rowIndex, params.colDef.field, 'corner'),
};

const defaultColDef = {
    flex: 1, minWidth: 100, filter: true, sortable: true, resizable: true,
    editable: (params) => !props.readOnly,
    valueGetter: getCellValue,
    valueParser: (params) => params.newValue,
    headerClass: 'excel-column-header',
    suppressKeyboardEvent: (params) => {
        const key = params.event.key;
        // Полностью забираем управление Delete, Backspace и Del у таблицы
        if (key === 'Delete' || key === 'Del' || key === 'Backspace') return true;
        return false;
    },
    cellStyle: (params) => {
        if (!params.data || !params.colDef.field) return null;
        return params.data[params.colDef.field + '_style'] || null;
    }
};

const finalColumnDefs = computed(() => {
    // 1. Добавляем колонку с номерами строк
    const rowNumCol = {
        headerName: '', 
        valueGetter: "node.rowIndex + 1", 
        width: 45, minWidth: 45, maxWidth: 45,
        pinned: 'left', 
        editable: false,
        sortable: false,
        filter: false,
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

    // 2. Обрабатываем основные колонки (подсветка + правила классов)
    const mainCols = props.columnDefs.map((col, index) => {
        const isHighlighted = selectionStart.value && selectionEnd.value && 
            index >= Math.min(selectionStart.value.col, selectionEnd.value.col) &&
            index <= Math.max(selectionStart.value.col, selectionEnd.value.col);
            
        return {
            ...col,
            headerClass: (isHighlighted ? 'excel-header-highlight ' : '') + (col.headerClass || ''),
            cellClassRules: cellClassRules
        };
    });

    return [rowNumCol, ...mainCols];
});
</script>

<template>
    <div class="ag-theme-balham" style="width: 100%; height: 100%;" @contextmenu.prevent>
        <ag-grid-vue
            style="width: 100%; height: 100%;"
            theme="legacy"
            :columnDefs="finalColumnDefs"
            :rowData="rowData"
            :defaultColDef="defaultColDef"
            @grid-ready="onGridReady"
            @cell-value-changed="onCellValueChanged"
            @cell-focused="onCellFocused"
            @cell-mouse-down="onCellMouseDown"
            @cell-mouse-over="onCellMouseOver"
            @cell-context-menu="onCellContextMenu"
            @keydown="onGridKeyDown"
            :animateRows="true"
            :headerHeight="24"
            :rowHeight="25"
        />
    </div>
</template>

<style>
.ag-theme-balham {
    --ag-selected-row-background-color: transparent !important;
    --ag-header-background-color: #f3f2f1;
    --ag-border-color: #d1d4d8;
    --ag-grid-size: 2px;
    --ag-font-size: 12px;
    --ag-font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    user-select: none;
}

.ag-theme-balham .excel-header-highlight {
    background-color: #e2e2e2 !important;
    border-bottom: 2px solid #217346 !important;
    color: #217346 !important;
    font-weight: bold !important;
}

.ag-theme-balham .excel-range-selected {
    background-color: rgba(33, 115, 70, 0.08) !important;
    z-index: 2 !important;
}

.ag-theme-balham .excel-range-top { border-top: 2px solid #217346 !important; }
.ag-theme-balham .excel-range-bottom { border-bottom: 2px solid #217346 !important; }
.ag-theme-balham .excel-range-left { border-left: 2px solid #217346 !important; }
.ag-theme-balham .excel-range-right { border-right: 2px solid #217346 !important; }

.ag-theme-balham .excel-range-corner::after {
    content: '';
    position: absolute;
    bottom: -3.5px;
    right: -3.5px;
    width: 6px;
    height: 6px;
    background-color: #217346;
    border: 1.5px solid white;
    z-index: 100;
    cursor: crosshair;
}

/* Header highlight like in real Excel */
.ag-theme-balham .ag-header-cell.excel-header-highlight {
    background-color: #e2e2e2 !important;
    color: #217346 !important;
    font-weight: bold !important;
}
.ag-theme-balham .ag-cell.row-num-cell.excel-header-highlight {
    background-color: #e2e2e2 !important;
    color: #217346 !important;
    font-weight: bold !important;
    border-right: 2px solid #217346 !important;
}

.ag-theme-balham .excel-active-cell {
    background-color: white !important;
    z-index: 6 !important;
}

.ag-theme-balham .excel-row-number-cell {
    background-color: #f3f2f1 !important;
    border-right: 1px solid #d1d4d8 !important;
    text-align: center;
    color: #444;
}

.ag-theme-balham .ag-cell-focus {
    border: 2px solid #217346 !important;
    outline: none !important;
}
</style>

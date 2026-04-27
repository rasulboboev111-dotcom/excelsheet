import { ref, watch } from 'vue';

const KEY = (id) => `excel_sheet_meta_${id}`;

const empty = () => ({
    merges: [],          // [{ row, col, rowSpan, colSpan }]
    validations: {},     // { [field]: [list,of,values] }
    colWidths: {},       // { [field]: number }
    rowHeights: {},      // { [rowIndex]: number }
    hidden: false,
    freezeRow: false,    // закрепить первую строку
    freezeCol: false     // закрепить первую колонку
});

export function useSheetMeta(sheetIdRef) {
    const meta = ref(empty());

    const load = (id) => {
        if (!id) { meta.value = empty(); return; }
        try {
            const raw = localStorage.getItem(KEY(id));
            if (raw) {
                const parsed = JSON.parse(raw);
                meta.value = { ...empty(), ...parsed };
            } else {
                meta.value = empty();
            }
        } catch (_) { meta.value = empty(); }
    };

    const save = () => {
        const id = sheetIdRef.value;
        if (!id) return;
        try { localStorage.setItem(KEY(id), JSON.stringify(meta.value)); } catch (_) {}
    };

    watch(sheetIdRef, (id) => load(id), { immediate: true });
    watch(meta, save, { deep: true });

    const allMetaForExport = () => {
        const result = {};
        for (let i = 0; i < localStorage.length; i++) {
            const k = localStorage.key(i);
            if (k && k.startsWith('excel_sheet_meta_')) {
                try { result[k.replace('excel_sheet_meta_', '')] = JSON.parse(localStorage.getItem(k)); } catch (_) {}
            }
        }
        return result;
    };
    const setMetaFor = (id, data) => {
        try { localStorage.setItem(KEY(id), JSON.stringify({ ...empty(), ...data })); } catch (_) {}
    };

    return { meta, load, save, allMetaForExport, setMetaFor };
}

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
                // Чистим устаревшие поля от удалённых фич, чтобы не таскать мусор в localStorage.
                if (parsed && typeof parsed === 'object') delete parsed.conditionalRules;
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

    // Дебаунс: ввод значения в ячейку → ребилд rowHeights/colWidths… не должен на каждое
    // нажатие лезть в localStorage с JSON.stringify огромного объекта. Записываем не чаще раз/300мс.
    let _saveTimer = null;
    const scheduleSave = () => {
        if (_saveTimer) clearTimeout(_saveTimer);
        _saveTimer = setTimeout(() => { _saveTimer = null; save(); }, 300);
    };

    // Загружая, флагом блокируем обратное сохранение (которое иначе сразу же
    // запишет только что прочитанное значение из watch deep).
    let _loading = false;
    const safeLoad = (id) => { _loading = true; load(id); _loading = false; };

    watch(sheetIdRef, (id) => safeLoad(id), { immediate: true });
    watch(meta, () => { if (!_loading) scheduleSave(); }, { deep: true });

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

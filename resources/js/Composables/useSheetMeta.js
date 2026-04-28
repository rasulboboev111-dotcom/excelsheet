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

    // Запись на диск: захватываем id и снапшот меты СИНХРОННО на момент scheduleSave,
    // потом откладываем сам localStorage.setItem на idle. Это защищает от гонки при
    // быстром переключении листов: если в момент idle-callback'а meta.value уже
    // содержит данные другого листа — мы всё равно запишем правильный снимок под
    // правильным ключом.
    let _idleHandle = null;
    let _idleHandleType = null; // 'idle' | 'timeout'
    const _cancelIdle = () => {
        if (_idleHandle == null) return;
        if (_idleHandleType === 'idle' && typeof window !== 'undefined' && typeof window.cancelIdleCallback === 'function') {
            window.cancelIdleCallback(_idleHandle);
        } else if (_idleHandleType === 'timeout') {
            clearTimeout(_idleHandle);
        }
        _idleHandle = null;
        _idleHandleType = null;
    };
    const _idleSchedule = (cb) => {
        _cancelIdle();
        if (typeof window !== 'undefined' && typeof window.requestIdleCallback === 'function') {
            _idleHandleType = 'idle';
            _idleHandle = window.requestIdleCallback(cb, { timeout: 1000 });
        } else {
            _idleHandleType = 'timeout';
            _idleHandle = setTimeout(cb, 0);
        }
    };

    const save = () => {
        const id = sheetIdRef.value;
        if (!id) return;
        // СНАПШОТ — JSON.stringify тут, не в idle. Сериализация даёт стабильную
        // строку, идущую под зафиксированным ключом id, независимо от того, что
        // случится с meta.value до момента записи.
        let snapshot;
        try { snapshot = JSON.stringify(meta.value); } catch (_) { return; }
        _idleSchedule(() => {
            _idleHandle = null;
            _idleHandleType = null;
            try { localStorage.setItem(KEY(id), snapshot); } catch (_) {}
        });
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

    // Перед сменой листа — досылаем pending save СИНХРОННО, потом грузим новый.
    // Иначе debounce-таймер выстрелит уже после того, как meta.value заменится
    // на содержимое нового листа, и мы запишем чужие данные под старым ключом.
    watch(sheetIdRef, (newId, oldId) => {
        if (oldId !== undefined && oldId !== null) {
            if (_saveTimer) { clearTimeout(_saveTimer); _saveTimer = null; }
            _cancelIdle();
            // Прямая синхронная запись для предыдущего листа.
            try { localStorage.setItem(KEY(oldId), JSON.stringify(meta.value)); } catch (_) {}
        }
        safeLoad(newId);
    }, { immediate: true });
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

import { ref, watch } from 'vue';
import axios from 'axios';

// Per-sheet UI meta: merges, colWidths, rowHeights, freezeRow/Col, validations, hidden.
// Хранится В БД (sheets.meta jsonb). Раньше жило в localStorage отдельных юзеров —
// другие пользователи того же листа не видели объединений/закреплений → баг.
// Теперь shared: каждый меняет → виден всем после переоткрытия листа.

const empty = () => ({
    merges: [],          // [{ row, col, rowSpan, colSpan }]
    validations: {},     // { [field]: [list,of,values] }
    colWidths: {},       // { [field]: number }
    rowHeights: {},      // { [rowIndex]: number }
    hidden: false,
    freezeRow: false,    // закрепить первую строку
    freezeCol: false,    // закрепить первую колонку
});

/**
 * @param {import('vue').Ref<number|null>} sheetIdRef — id активного листа.
 * @param {import('vue').Ref<object|null>} initialMetaRef — meta, пришедшая от
 *   сервера в Inertia-render'е (props.activeSheet.meta). Меняется при смене
 *   листа — composable подхватит и заменит локальное состояние.
 */
export function useSheetMeta(sheetIdRef, initialMetaRef = null) {
    const meta = ref(empty());
    let _suppressSave = false;

    const load = (initial) => {
        _suppressSave = true;
        meta.value = { ...empty(), ...(initial && typeof initial === 'object' ? initial : {}) };
        // Снимаем флаг через микротик — иначе deep-watch успеет сработать на
        // ту же мутацию и зальёт meta обратно на сервер впустую.
        Promise.resolve().then(() => { _suppressSave = false; });
    };

    // Дебаунс PATCH: drag-resize колонки даёт десятки мутаций rowHeights/colWidths.
    // На каждый из них слать PATCH перебор. 400ms — баланс между «не теряем при
    // быстром закрытии» и «не дубасим сервер».
    let _saveTimer = null;
    const SAVE_DEBOUNCE_MS = 400;
    const flushSave = () => {
        if (_saveTimer) { clearTimeout(_saveTimer); _saveTimer = null; }
        const id = sheetIdRef.value;
        if (!id) return;
        const snapshot = JSON.parse(JSON.stringify(meta.value));
        // route()-helper доступен глобально через Ziggy.
        const url = (typeof route === 'function') ? route('sheets.updateMeta', id) : `/sheets/${id}/meta`;
        axios.patch(url, { meta: snapshot }).catch((e) => {
            // 403 — viewer без canEdit. 422 — валидация не прошла. И в том, и в
            // другом случае молча пропускаем: meta сохраняется только тем, кто
            // имеет право редактировать. Логируем чтобы было видно при дебаге.
            if (e?.response?.status !== 403) {
                console.warn('[useSheetMeta] PATCH failed:', e?.response?.status, e?.response?.data);
            }
        });
    };
    const scheduleSave = () => {
        if (_suppressSave) return;
        if (_saveTimer) clearTimeout(_saveTimer);
        _saveTimer = setTimeout(() => { _saveTimer = null; flushSave(); }, SAVE_DEBOUNCE_MS);
    };

    // При смене активного листа — досылаем pending save для старого, затем
    // загружаем meta нового. Без этого debounce-таймер срабатывал бы уже
    // после переключения, заливал бы новую meta под старым ID → каша.
    watch(sheetIdRef, (newId, oldId) => {
        if (oldId && oldId !== newId && _saveTimer) {
            clearTimeout(_saveTimer); _saveTimer = null;
            // Синхронный flush для старого листа (через axios — не блокирующе,
            // но Promise pending уже улетел до load'а нового).
            const oldUrl = (typeof route === 'function') ? route('sheets.updateMeta', oldId) : `/sheets/${oldId}/meta`;
            const snap = JSON.parse(JSON.stringify(meta.value));
            axios.patch(oldUrl, { meta: snap }).catch(() => {});
        }
        load(initialMetaRef ? initialMetaRef.value : null);
    }, { immediate: true });

    // Когда сервер отдаёт новый initialMeta (Inertia partial reload) — подхватываем.
    if (initialMetaRef) {
        watch(initialMetaRef, (newMeta) => {
            if (sheetIdRef.value) load(newMeta);
        });
    }

    watch(meta, scheduleSave, { deep: true });

    // Beforeunload — досылаем pending. Иначе drag-resize → мгновенное закрытие
    // вкладки → последняя ширина потеряна.
    if (typeof window !== 'undefined') {
        window.addEventListener('beforeunload', () => {
            if (_saveTimer) {
                clearTimeout(_saveTimer); _saveTimer = null;
                const id = sheetIdRef.value;
                if (id) {
                    const url = (typeof route === 'function') ? route('sheets.updateMeta', id) : `/sheets/${id}/meta`;
                    try {
                        const blob = new Blob([JSON.stringify({ meta: meta.value })], { type: 'application/json' });
                        navigator.sendBeacon?.(url, blob);
                    } catch (_) {}
                }
            }
        });
    }

    // Используется при импорте: только что созданный лист (только-что вернулся
    // id с сервера) → нужно сразу же залить ему сгенерированную meta (merges,
    // colWidths из xlsx). PATCH идёт сразу, без debounce.
    const setMetaFor = (id, data) => {
        const merged = { ...empty(), ...data };
        const url = (typeof route === 'function') ? route('sheets.updateMeta', id) : `/sheets/${id}/meta`;
        return axios.patch(url, { meta: merged }).catch((e) => {
            console.warn('[useSheetMeta] setMetaFor failed:', e?.response?.status);
        });
    };

    return { meta, setMetaFor };
}

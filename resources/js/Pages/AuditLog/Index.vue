<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { reactive, computed } from 'vue';

const props = defineProps({
    logs: Object,        // paginator { data, links, meta }
    filters: Object,     // активные фильтры
    users: Array,        // список пользователей
    sheets: Array,       // список листов (для фильтра)
    actions: Object,     // карта { ключ → русский label }
});

const form = reactive({
    user_id:  props.filters?.user_id  || '',
    sheet_id: props.filters?.sheet_id || '',
    action:   props.filters?.action   || '',
    from:     props.filters?.from     || '',
    to:       props.filters?.to       || '',
});

const applyFilters = () => {
    const query = {};
    Object.entries(form).forEach(([k, v]) => { if (v !== '' && v !== null) query[k] = v; });
    router.get(route('audit-log.index'), query, { preserveState: true, preserveScroll: false });
};

const resetFilters = () => {
    Object.keys(form).forEach(k => { form[k] = ''; });
    router.get(route('audit-log.index'), {}, { preserveState: true });
};

const clearAudit = () => {
    const total = props.logs?.total ?? 0;
    if (total === 0) {
        alert('Журнал уже пуст.');
        return;
    }
    if (!confirm(`Удалить ВСЕ записи журнала (${total} шт.)? Действие необратимо.\n\nПервой записью в чистом журнале появится «Очистка журнала» с вашим именем — чтобы был след.`)) return;
    router.delete(route('audit-log.clear'), {
        preserveScroll: true,
        onSuccess: () => { /* Inertia сама перезагрузит данные */ },
    });
};

const formatDateTime = (s) => {
    if (!s) return '';
    try {
        const d = new Date(s);
        return d.toLocaleString('ru-RU', { dateStyle: 'short', timeStyle: 'medium' });
    } catch (_) { return s; }
};

const actionLabel = (key) => props.actions?.[key] || key;

const actionBadgeClass = (key) => {
    if (key === 'sheet_deleted' || key === 'row_deleted' || key === 'gmail_disconnected') return 'bg-red-100 text-red-700';
    if (key === 'sheet_created' || key === 'sheet_imported' || key === 'row_inserted' || key === 'gmail_connected') return 'bg-green-100 text-green-700';
    if (key === 'sheet_renamed') return 'bg-yellow-100 text-yellow-700';
    if (key === 'audit_cleared') return 'bg-purple-100 text-purple-700';
    if (key === 'sheet_emailed') return 'bg-indigo-100 text-indigo-700';
    return 'bg-blue-100 text-blue-700';
};

// Превращает значение ячейки (число / строка / null) в человеко-читаемую форму.
const fmtVal = (v) => {
    if (v === null || v === undefined || v === '') return '—';
    let s = String(v);
    if (s.length > 60) s = s.slice(0, 60) + '…';
    return s;
};

const detailsSummary = (log) => {
    const d = log.details || {};
    if (log.action === 'sheet_renamed')  return `«${d.old_name ?? '?'}» → «${d.new_name ?? '?'}»`;
    if (log.action === 'sheet_deleted')  return `«${d.name ?? '?'}» (id #${d.sheet_id ?? '?'})`;
    if (log.action === 'sheet_created')  return `«${d.name ?? '?'}»`;
    if (log.action === 'sheet_imported') return `«${d.name ?? '?'}» · строк: ${d.rows_count ?? 0} · колонок: ${d.columns_count ?? 0}`;
    if (log.action === 'row_inserted' || log.action === 'row_deleted') return `строка ${(d.row_index ?? 0) + 1}`;
    if (log.action === 'audit_cleared') return `удалено записей: ${d.removed_entries ?? '?'}`;
    if (log.action === 'sheet_emailed')      return `→ ${d.to ?? '?'} (${d.attachment_kb ?? 0} КБ)`;
    if (log.action === 'gmail_connected')    return `✓ ${d.gmail_email ?? '?'}`;
    if (log.action === 'gmail_disconnected') return `${d.gmail_email ?? '?'}`;
    // cell_edit — рендерится отдельным компонентом-таблицей, см. шаблон.
    return null;
};

// true — для этого лога есть детальная таблица «было → стало»
const hasCellTable = (log) => log.action === 'cell_edit' && Array.isArray(log.details?.sample) && log.details.sample.length > 0;

// Laravel-paginator пишет лейблы как "&laquo; Previous"/"&raquo; Next" с HTML-сущностями.
// Раньше мы рендерили через v-html — это потенциальный XSS-footgun. Декодируем сущности
// безопасно (на лету) и выдаём как текст. Заодно переводим типовые лейблы на русский.
const paginationLabel = (raw) => {
    if (raw == null) return '';
    let s = String(raw)
        .replace(/&laquo;|&#171;/g, '«')
        .replace(/&raquo;|&#187;/g, '»')
        .replace(/&hellip;|&#8230;/g, '…')
        .replace(/&amp;/g, '&')
        .replace(/&lt;/g, '<')
        .replace(/&gt;/g, '>')
        .replace(/&quot;/g, '"')
        .replace(/&#039;|&apos;/g, "'");
    s = s.replace(/Previous/gi, 'Назад').replace(/Next/gi, 'Вперёд');
    return s.trim();
};
</script>

<template>
    <Head title="Журнал аудита" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">Журнал действий</h2>
                <button @click="clearAudit"
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm rounded border border-red-300 text-red-700 bg-white hover:bg-red-50 transition-colors"
                        :disabled="(logs?.total ?? 0) === 0"
                        :class="{ 'opacity-50 cursor-not-allowed': (logs?.total ?? 0) === 0 }"
                        :title="(logs?.total ?? 0) === 0 ? 'Журнал уже пуст' : 'Удалить все записи журнала'">
                    <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="3 6 5 6 21 6"/>
                        <path d="M19 6l-1.18 14.13A2 2 0 0 1 15.83 22H8.17a2 2 0 0 1-1.99-1.87L5 6"/>
                        <path d="M10 11v6M14 11v6"/>
                        <path d="M9 6V4a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2"/>
                    </svg>
                    Очистить журнал
                </button>
            </div>
        </template>

        <div class="py-8">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-4">
                <!-- Фильтры -->
                <div class="bg-white shadow rounded p-4">
                    <div class="grid grid-cols-1 md:grid-cols-5 gap-3">
                        <div>
                            <label class="block text-xs text-gray-600 mb-1">Пользователь</label>
                            <select v-model="form.user_id" class="w-full border border-gray-300 rounded px-2 py-1 text-sm">
                                <option value="">— все —</option>
                                <option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs text-gray-600 mb-1">Лист</label>
                            <select v-model="form.sheet_id" class="w-full border border-gray-300 rounded px-2 py-1 text-sm">
                                <option value="">— все —</option>
                                <option v-for="s in sheets" :key="s.id" :value="s.id">{{ s.name }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs text-gray-600 mb-1">Действие</label>
                            <select v-model="form.action" class="w-full border border-gray-300 rounded px-2 py-1 text-sm">
                                <option value="">— все —</option>
                                <option v-for="(label, key) in actions" :key="key" :value="key">{{ label }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs text-gray-600 mb-1">С даты</label>
                            <input type="datetime-local" v-model="form.from" class="w-full border border-gray-300 rounded px-2 py-1 text-sm" />
                        </div>
                        <div>
                            <label class="block text-xs text-gray-600 mb-1">По дату</label>
                            <input type="datetime-local" v-model="form.to" class="w-full border border-gray-300 rounded px-2 py-1 text-sm" />
                        </div>
                    </div>
                    <div class="mt-3 flex gap-2 justify-end">
                        <button @click="resetFilters" class="px-3 py-1.5 text-sm rounded bg-gray-100 hover:bg-gray-200">Сбросить</button>
                        <button @click="applyFilters" class="px-3 py-1.5 text-sm rounded bg-[#2563eb] hover:bg-[#1d4ed8] text-white">Применить</button>
                    </div>
                </div>

                <!-- Таблица -->
                <div class="bg-white shadow rounded overflow-hidden">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-100 text-left">
                            <tr>
                                <th class="px-4 py-2 w-44">Время</th>
                                <th class="px-4 py-2 w-44">Пользователь</th>
                                <th class="px-4 py-2 w-40">Лист</th>
                                <th class="px-4 py-2 w-44">Действие</th>
                                <th class="px-4 py-2">Подробности</th>
                                <th class="px-4 py-2 w-32">IP</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="!logs.data?.length">
                                <td colspan="6" class="px-4 py-10 text-center text-gray-500">Записей нет</td>
                            </tr>
                            <tr v-for="log in logs.data" :key="log.id" class="border-t hover:bg-gray-50">
                                <td class="px-4 py-2 text-gray-600 whitespace-nowrap">{{ formatDateTime(log.created_at) }}</td>
                                <td class="px-4 py-2">
                                    <div class="font-medium">{{ log.user?.name || '—' }}</div>
                                    <div class="text-xs text-gray-500">{{ log.user?.email || '' }}</div>
                                </td>
                                <td class="px-4 py-2 text-gray-700">{{ log.sheet?.name || (log.details?.name ? '«' + log.details.name + '»' : '—') }}</td>
                                <td class="px-4 py-2">
                                    <span :class="['inline-block px-2 py-0.5 text-xs rounded', actionBadgeClass(log.action)]">
                                        {{ actionLabel(log.action) }}
                                    </span>
                                </td>
                                <td class="px-4 py-2 text-gray-700 text-xs">
                                    <!-- Текстовое описание (для всех событий, кроме cell_edit) -->
                                    <span v-if="!hasCellTable(log)">{{ detailsSummary(log) }}</span>

                                    <!-- Детальная таблица «было → стало» для правок ячеек -->
                                    <div v-else>
                                        <div class="text-gray-500 mb-1">
                                            Изменено ячеек: <b>{{ log.details.cells_changed }}</b>
                                            <span v-if="log.details.cells_changed > log.details.sample.length">
                                                (показаны первые {{ log.details.sample.length }})
                                            </span>
                                        </div>
                                        <table class="border border-gray-200 rounded text-[11px] w-full">
                                            <thead class="bg-gray-50 text-gray-600">
                                                <tr>
                                                    <th class="px-2 py-1 text-left w-14">Строка</th>
                                                    <th class="px-2 py-1 text-left w-40">Колонка</th>
                                                    <th class="px-2 py-1 text-left">Было</th>
                                                    <th class="px-2 py-1 text-left">Стало</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <tr v-for="(c, i) in log.details.sample" :key="i" class="border-t">
                                                    <td class="px-2 py-1 text-gray-600">{{ c.row }}</td>
                                                    <td class="px-2 py-1">
                                                        <b>{{ c.col_name }}</b>
                                                        <span v-if="c.col_name !== c.col" class="text-gray-400 ml-1">({{ c.col }})</span>
                                                    </td>
                                                    <td class="px-2 py-1 text-red-700 line-through" :title="c.old">{{ fmtVal(c.old) }}</td>
                                                    <td class="px-2 py-1 text-green-700 font-medium" :title="c.new">{{ fmtVal(c.new) }}</td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </td>
                                <td class="px-4 py-2 text-gray-500 text-xs font-mono">{{ log.ip || '' }}</td>
                            </tr>
                        </tbody>
                    </table>

                    <!-- Пагинация -->
                    <div v-if="logs.links?.length > 3" class="px-4 py-3 border-t bg-gray-50 flex items-center justify-between text-sm">
                        <div class="text-gray-600">
                            Показано {{ logs.from || 0 }}–{{ logs.to || 0 }} из {{ logs.total || 0 }}
                        </div>
                        <div class="flex gap-1">
                            <template v-for="(link, i) in logs.links" :key="i">
                                <Link v-if="link.url"
                                      :href="link.url"
                                      preserve-state preserve-scroll
                                      :class="['px-2 py-1 rounded text-xs',
                                          link.active ? 'bg-[#2563eb] text-white' : 'bg-white border hover:bg-gray-100']">
                                    {{ paginationLabel(link.label) }}
                                </Link>
                                <span v-else
                                      class="px-2 py-1 rounded text-xs text-gray-400 cursor-default select-none">
                                    {{ paginationLabel(link.label) }}
                                </span>
                            </template>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    users: Array,
});

const showCreate = ref(false);
const showEditId = ref(null);

const createForm = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
    is_admin: false,
    can_send_mail: false,
});

const editForm = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
    is_admin: false,
    can_send_mail: false,
});

const submitCreate = () => {
    createForm.post(route('users.store'), {
        preserveScroll: true,
        onSuccess: () => {
            createForm.reset();
            showCreate.value = false;
        },
    });
};

const startEdit = (u) => {
    editForm.reset();
    editForm.clearErrors();
    editForm.name = u.name;
    editForm.email = u.email;
    editForm.is_admin = u.is_admin;
    editForm.can_send_mail = u.can_send_mail;
    showEditId.value = u.id;
};

const submitEdit = () => {
    editForm.patch(route('users.update', showEditId.value), {
        preserveScroll: true,
        onSuccess: () => {
            showEditId.value = null;
            editForm.reset();
        },
    });
};

const removeUser = (u) => {
    if (!confirm(`Удалить пользователя "${u.name}" (${u.email})? Действие необратимо.`)) return;
    router.delete(route('users.destroy', u.id), { preserveScroll: true });
};
</script>

<template>
    <Head title="Пользователи" />
    <AuthenticatedLayout>
        <template #header>
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">Пользователи</h2>
        </template>

        <div class="py-8">
            <div class="max-w-5xl mx-auto sm:px-6 lg:px-8">
                <div class="bg-white shadow rounded">
                    <div class="px-6 py-4 border-b flex items-center justify-between">
                        <h3 class="font-bold">Список пользователей</h3>
                        <button @click="showCreate = !showCreate"
                                class="px-3 py-1.5 text-sm rounded bg-[#2563eb] text-white hover:bg-[#1d4ed8]">
                            {{ showCreate ? 'Отмена' : '+ Добавить пользователя' }}
                        </button>
                    </div>

                    <!-- Форма создания -->
                    <div v-if="showCreate" class="px-6 py-4 border-b bg-gray-50">
                        <form @submit.prevent="submitCreate" class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs text-gray-600 mb-1">Имя</label>
                                <input v-model="createForm.name" type="text" required
                                       class="w-full border border-gray-300 rounded px-2 py-1 text-sm" />
                                <div v-if="createForm.errors.name" class="text-xs text-red-600 mt-1">{{ createForm.errors.name }}</div>
                            </div>
                            <div>
                                <label class="block text-xs text-gray-600 mb-1">Email</label>
                                <input v-model="createForm.email" type="email" required
                                       class="w-full border border-gray-300 rounded px-2 py-1 text-sm" />
                                <div v-if="createForm.errors.email" class="text-xs text-red-600 mt-1">{{ createForm.errors.email }}</div>
                            </div>
                            <div>
                                <label class="block text-xs text-gray-600 mb-1">Пароль</label>
                                <input v-model="createForm.password" type="password" required
                                       class="w-full border border-gray-300 rounded px-2 py-1 text-sm" />
                                <div v-if="createForm.errors.password" class="text-xs text-red-600 mt-1">{{ createForm.errors.password }}</div>
                            </div>
                            <div>
                                <label class="block text-xs text-gray-600 mb-1">Повтор пароля</label>
                                <input v-model="createForm.password_confirmation" type="password" required
                                       class="w-full border border-gray-300 rounded px-2 py-1 text-sm" />
                            </div>
                            <div class="col-span-2 flex items-center justify-between">
                                <div class="flex items-center gap-5">
                                    <label class="text-sm flex items-center gap-2 cursor-pointer">
                                        <input v-model="createForm.is_admin" type="checkbox" />
                                        Сделать администратором
                                    </label>
                                    <label class="text-sm flex items-center gap-2 cursor-pointer"
                                           :class="{ 'opacity-50': createForm.is_admin }"
                                           :title="createForm.is_admin ? 'Админу не нужно — у него уже есть все права' : null">
                                        <input v-model="createForm.can_send_mail" type="checkbox" :disabled="createForm.is_admin" />
                                        Может отправлять почту
                                    </label>
                                </div>
                                <button type="submit" :disabled="createForm.processing"
                                        class="px-4 py-1.5 text-sm rounded bg-[#2563eb] text-white hover:bg-[#1d4ed8] disabled:opacity-50">
                                    Создать
                                </button>
                            </div>
                        </form>
                    </div>

                    <!-- Таблица -->
                    <table class="w-full text-sm">
                        <thead class="bg-gray-100 text-left">
                            <tr>
                                <th class="px-6 py-2 w-12">ID</th>
                                <th class="px-6 py-2">Имя</th>
                                <th class="px-6 py-2">Email</th>
                                <th class="px-6 py-2 w-24">Роль</th>
                                <th class="px-6 py-2 w-56">Почта</th>
                                <th class="px-6 py-2 w-44 text-right">Действия</th>
                            </tr>
                        </thead>
                        <tbody>
                            <template v-for="u in users" :key="u.id">
                                <tr class="border-t">
                                    <td class="px-6 py-2 text-gray-500">{{ u.id }}</td>
                                    <td class="px-6 py-2 font-medium">{{ u.name }}</td>
                                    <td class="px-6 py-2 text-gray-600">{{ u.email }}</td>
                                    <td class="px-6 py-2">
                                        <span v-if="u.is_admin" class="inline-block px-2 py-0.5 bg-[#2563eb] text-white text-xs rounded">admin</span>
                                        <span v-else class="text-gray-500 text-xs">user</span>
                                    </td>
                                    <td class="px-6 py-2">
                                        <div v-if="u.is_admin" class="text-xs text-gray-500 italic">— разрешено всё —</div>
                                        <div v-else-if="u.can_send_mail" class="flex flex-col gap-0.5">
                                            <span class="inline-flex items-center gap-1 text-xs text-emerald-700">
                                                <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                                                Может отправлять
                                            </span>
                                            <span v-if="u.gmail_connected" class="text-[10px] text-gray-500 truncate" :title="u.gmail_email">
                                                ({{ u.gmail_email }})
                                            </span>
                                            <span v-else class="text-[10px] text-gray-400 italic">Gmail не подключён</span>
                                        </div>
                                        <span v-else class="text-xs text-gray-400">Запрещено</span>
                                    </td>
                                    <td class="px-6 py-2 text-right space-x-2">
                                        <button @click="startEdit(u)" class="text-xs text-blue-600 hover:underline">Изменить</button>
                                        <button @click="removeUser(u)" class="text-xs text-red-600 hover:underline">Удалить</button>
                                    </td>
                                </tr>
                                <!-- Inline edit form -->
                                <tr v-if="showEditId === u.id" class="bg-yellow-50 border-t">
                                    <td colspan="6" class="px-6 py-3">
                                        <form @submit.prevent="submitEdit" class="grid grid-cols-2 gap-3">
                                            <div>
                                                <label class="block text-xs text-gray-600 mb-1">Имя</label>
                                                <input v-model="editForm.name" type="text" required
                                                       class="w-full border border-gray-300 rounded px-2 py-1 text-sm" />
                                                <div v-if="editForm.errors.name" class="text-xs text-red-600 mt-1">{{ editForm.errors.name }}</div>
                                            </div>
                                            <div>
                                                <label class="block text-xs text-gray-600 mb-1">Email</label>
                                                <input v-model="editForm.email" type="email" required
                                                       class="w-full border border-gray-300 rounded px-2 py-1 text-sm" />
                                                <div v-if="editForm.errors.email" class="text-xs text-red-600 mt-1">{{ editForm.errors.email }}</div>
                                            </div>
                                            <div>
                                                <label class="block text-xs text-gray-600 mb-1">Новый пароль (пусто = не менять)</label>
                                                <input v-model="editForm.password" type="password"
                                                       class="w-full border border-gray-300 rounded px-2 py-1 text-sm" />
                                                <div v-if="editForm.errors.password" class="text-xs text-red-600 mt-1">{{ editForm.errors.password }}</div>
                                            </div>
                                            <div>
                                                <label class="block text-xs text-gray-600 mb-1">Повтор пароля</label>
                                                <input v-model="editForm.password_confirmation" type="password"
                                                       class="w-full border border-gray-300 rounded px-2 py-1 text-sm" />
                                            </div>
                                            <div class="col-span-2 flex items-center justify-between">
                                                <div class="flex items-center gap-5">
                                                    <label class="text-sm flex items-center gap-2 cursor-pointer">
                                                        <input v-model="editForm.is_admin" type="checkbox" />
                                                        Администратор
                                                    </label>
                                                    <label class="text-sm flex items-center gap-2 cursor-pointer"
                                                           :class="{ 'opacity-50': editForm.is_admin }"
                                                           :title="editForm.is_admin ? 'Админу не нужно — у него уже есть все права' : null">
                                                        <input v-model="editForm.can_send_mail" type="checkbox" :disabled="editForm.is_admin" />
                                                        Может отправлять почту
                                                    </label>
                                                </div>
                                                <div class="space-x-2">
                                                    <button type="button" @click="showEditId = null"
                                                            class="px-3 py-1.5 text-sm rounded bg-gray-200 hover:bg-gray-300">
                                                        Отмена
                                                    </button>
                                                    <button type="submit" :disabled="editForm.processing"
                                                            class="px-4 py-1.5 text-sm rounded bg-blue-600 text-white hover:bg-blue-700 disabled:opacity-50">
                                                        Сохранить
                                                    </button>
                                                </div>
                                            </div>
                                        </form>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import UpdatePasswordForm from './Partials/UpdatePasswordForm.vue';
import UpdateProfileInformationForm from './Partials/UpdateProfileInformationForm.vue';
import { Head, usePage, router } from '@inertiajs/vue3';
import { computed } from 'vue';

defineProps({
    mustVerifyEmail: Boolean,
    status: String,
});

const page = usePage();
const canSendMail    = computed(() => !!page.props.auth?.canSendMail);
const gmailConnected = computed(() => !!page.props.auth?.gmailConnected);
const gmailEmail     = computed(() => page.props.auth?.gmailEmail);
const flashSuccess   = computed(() => page.props.flash?.success);
const flashError     = computed(() => page.props.flash?.error);

const disconnectGmail = () => {
    if (!confirm('Отключить Gmail? Вы сможете подключить заново позже.')) return;
    router.delete(route('google.disconnect'), { preserveScroll: true });
};
</script>

<template>
    <Head title="Профиль" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">Профиль</h2>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
                <!-- Flash-сообщения после OAuth-callback'а -->
                <div v-if="flashSuccess" class="px-4 py-3 rounded bg-emerald-50 border border-emerald-200 text-emerald-800 text-sm">
                    {{ flashSuccess }}
                </div>
                <div v-if="flashError" class="px-4 py-3 rounded bg-red-50 border border-red-200 text-red-800 text-sm">
                    {{ flashError }}
                </div>

                <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                    <UpdateProfileInformationForm
                        :must-verify-email="mustVerifyEmail"
                        :status="status"
                        class="max-w-xl"
                    />
                </div>

                <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                    <UpdatePasswordForm class="max-w-xl" />
                </div>

                <!-- Секция Gmail OAuth — только если у юзера есть право send-mail.
                     Без права секция вообще не появляется (юзер не знает что фича существует). -->
                <div v-if="canSendMail" class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                    <header class="max-w-xl">
                        <h2 class="text-lg font-medium text-gray-900 flex items-center gap-2">
                            <svg class="w-5 h-5 text-[#2563eb]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                                <rect x="3" y="5" width="18" height="14" rx="2"/>
                                <path d="M3 7l9 6 9-6"/>
                            </svg>
                            Отправка писем через Gmail
                        </h2>
                        <p class="mt-1 text-sm text-gray-600">
                            Подключите свой Gmail — и письма с таблицами будут отправляться от вашего адреса.
                            Получатели увидят ваше имя в графе «От», и копия письма попадёт в вашу папку «Отправленные».
                        </p>
                    </header>

                    <div class="mt-6 max-w-xl">
                        <!-- Подключено -->
                        <div v-if="gmailConnected"
                             class="rounded-md border border-emerald-200 bg-emerald-50 p-4 flex items-start justify-between gap-4">
                            <div class="flex items-start gap-3 min-w-0">
                                <svg class="w-5 h-5 mt-0.5 text-emerald-600 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <polyline points="20 6 9 17 4 12"/>
                                </svg>
                                <div class="min-w-0">
                                    <div class="text-sm font-semibold text-emerald-900">Подключено</div>
                                    <div class="text-sm text-emerald-800 truncate">{{ gmailEmail }}</div>
                                    <div class="text-xs text-emerald-700 mt-1">Можно отправлять таблицы кнопкой «📧 Отправить» в Dashboard.</div>
                                </div>
                            </div>
                            <button @click="disconnectGmail"
                                    class="shrink-0 text-xs px-3 py-1.5 rounded border border-red-200 text-red-700 bg-white hover:bg-red-50 transition-colors">
                                Отключить
                            </button>
                        </div>

                        <!-- Не подключено -->
                        <div v-else>
                            <div class="rounded-md border border-gray-200 bg-gray-50 p-4">
                                <div class="text-sm text-gray-700">
                                    Gmail не подключён. После подключения откроется страница Google
                                    с запросом разрешения на отправку писем от вашего имени.
                                    Письма читать сайт не будет — только отправка.
                                </div>
                            </div>
                            <a :href="route('google.connect')"
                               class="mt-4 inline-flex items-center gap-2 px-4 py-2 rounded-md border border-gray-300 bg-white hover:bg-gray-50 text-sm font-medium text-gray-700 transition-colors shadow-sm">
                                <svg class="w-5 h-5" viewBox="0 0 48 48">
                                    <path fill="#FFC107" d="M43.6,20.5H42V20H24v8h11.3c-1.6,4.7-6.1,8-11.3,8c-6.6,0-12-5.4-12-12s5.4-12,12-12c3.1,0,5.8,1.2,7.9,3.1l5.7-5.7C34,6.1,29.3,4,24,4C12.9,4,4,12.9,4,24s8.9,20,20,20s20-8.9,20-20C44,22.7,43.9,21.6,43.6,20.5z"/>
                                    <path fill="#FF3D00" d="M6.3,14.7l6.6,4.8C14.7,15.1,18.9,12,24,12c3.1,0,5.8,1.2,7.9,3.1l5.7-5.7C34,6.1,29.3,4,24,4 C16.3,4,9.7,8.3,6.3,14.7z"/>
                                    <path fill="#4CAF50" d="M24,44c5.2,0,9.9-2,13.4-5.2l-6.2-5.2C29.2,35,26.7,36,24,36c-5.2,0-9.6-3.3-11.3-8l-6.5,5C9.5,39.6,16.2,44,24,44z"/>
                                    <path fill="#1976D2" d="M43.6,20.5H42V20H24v8h11.3c-0.8,2.2-2.2,4.2-4.1,5.6c0,0,0,0,0,0l6.2,5.2C37,39.2,44,34,44,24 C44,22.7,43.9,21.6,43.6,20.5z"/>
                                </svg>
                                Подключить Gmail
                            </a>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </AuthenticatedLayout>
</template>

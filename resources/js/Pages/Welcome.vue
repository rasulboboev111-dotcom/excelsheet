<script setup>
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    canLogin: Boolean,
    canRegister: Boolean,
});
</script>

<template>
    <Head title="Главная" />

    <div class="min-h-screen flex items-center justify-center bg-gradient-to-br from-blue-50 via-white to-blue-100 px-4">
        <div class="w-full max-w-xl bg-white rounded-2xl shadow-xl p-10 text-center">
            <img src="/img/tojiktelecom-logo.svg" alt="Tojiktelecom" class="h-16 w-auto mx-auto mb-4" />
            <h1 class="text-2xl font-bold text-gray-900 mb-1">Excel <span class="text-[#2563eb]">Tojiktelecom</span></h1>
            <p class="text-gray-500 text-sm mb-8">Онлайн-таблицы для совместной работы</p>

            <div v-if="canLogin" class="flex flex-col sm:flex-row justify-center gap-3">
                <Link v-if="$page.props.auth?.user"
                      :href="route('dashboard')"
                      class="px-6 py-2.5 rounded-lg bg-[#2563eb] hover:bg-[#1d4ed8] text-white font-semibold transition-colors">
                    Открыть таблицы
                </Link>

                <template v-else>
                    <Link :href="route('login')"
                          class="px-6 py-2.5 rounded-lg bg-[#2563eb] hover:bg-[#1d4ed8] text-white font-semibold transition-colors">
                        Войти
                    </Link>
                    <Link v-if="canRegister"
                          :href="route('register')"
                          class="px-6 py-2.5 rounded-lg border border-gray-300 hover:bg-gray-50 text-gray-700 font-semibold transition-colors">
                        Зарегистрироваться
                    </Link>
                </template>
            </div>

            <div class="mt-8 text-xs text-gray-400">
                © {{ new Date().getFullYear() }} Tojiktelecom
            </div>
        </div>
    </div>
</template>

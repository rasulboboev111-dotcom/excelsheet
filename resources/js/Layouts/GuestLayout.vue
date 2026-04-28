<script setup>
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import { Link } from '@inertiajs/vue3';
</script>

<template>
    <div class="min-h-screen flex items-center justify-center px-4 py-10 bg-gradient-to-br from-blue-50 via-white to-blue-100 relative overflow-hidden">
        <!-- Декор-окружности на фоне -->
        <div class="pointer-events-none absolute -top-32 -right-32 w-[420px] h-[420px] rounded-full bg-blue-200/40 blur-3xl"></div>
        <div class="pointer-events-none absolute -bottom-32 -left-32 w-[420px] h-[420px] rounded-full bg-blue-300/30 blur-3xl"></div>

        <div class="relative w-full max-w-md">
            <!-- Логотип над карточкой -->
            <div class="text-center mb-6">
                <Link href="/">
                    <ApplicationLogo class="h-16 w-auto mx-auto" />
                </Link>
                <h1 class="mt-3 text-xl font-bold text-gray-900">Excel <span class="text-[#2563eb]">Tojiktelecom</span></h1>
                <p class="mt-1 text-xs text-gray-500">Онлайн-таблицы для совместной работы</p>
            </div>

            <!-- Карточка с формой -->
            <div class="bg-white rounded-2xl shadow-xl border border-gray-100 px-7 py-7">
                <slot />
            </div>

            <p class="mt-6 text-center text-xs text-gray-400">
                © {{ new Date().getFullYear() }} Tojiktelecom
            </p>
        </div>
    </div>
</template>

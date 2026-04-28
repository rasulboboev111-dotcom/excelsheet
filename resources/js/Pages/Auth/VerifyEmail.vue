<script setup>
import { computed } from 'vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps({
    status: String,
});

const form = useForm({});

const submit = () => {
    form.post(route('verification.send'));
};

const verificationLinkSent = computed(() => props.status === 'verification-link-sent');
</script>

<template>
    <GuestLayout>
        <Head title="Подтверждение email" />

        <h2 class="text-lg font-semibold text-gray-900 mb-1">Подтвердите почту</h2>
        <p class="text-sm text-gray-500 mb-5">
            Спасибо за регистрацию! На вашу почту отправлена ссылка для подтверждения. Если письмо не пришло — мы можем выслать его повторно.
        </p>

        <div v-if="verificationLinkSent" class="mb-4 px-3 py-2 rounded-md bg-green-50 border border-green-200 text-sm text-green-700">
            Новая ссылка отправлена на email, указанный при регистрации.
        </div>

        <form @submit.prevent="submit" class="space-y-3">
            <button type="submit"
                    :disabled="form.processing"
                    :class="{ 'opacity-50 cursor-not-allowed': form.processing }"
                    class="w-full px-4 py-2.5 rounded-lg bg-[#2563eb] hover:bg-[#1d4ed8] text-white font-semibold transition-colors">
                {{ form.processing ? 'Отправка…' : 'Отправить ссылку повторно' }}
            </button>

            <Link
                :href="route('logout')"
                method="post"
                as="button"
                class="block w-full text-center text-sm text-gray-600 hover:text-gray-900 hover:underline">
                Выйти из аккаунта
            </Link>
        </form>
    </GuestLayout>
</template>

<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, useForm } from '@inertiajs/vue3';

const form = useForm({
    password: '',
});

const submit = () => {
    form.post(route('password.confirm'), {
        onFinish: () => form.reset(),
    });
};
</script>

<template>
    <GuestLayout>
        <Head title="Подтверждение пароля" />

        <h2 class="text-lg font-semibold text-gray-900 mb-1">Подтверждение</h2>
        <p class="text-sm text-gray-500 mb-5">
            Это защищённая область. Подтвердите пароль, чтобы продолжить.
        </p>

        <form @submit.prevent="submit" class="space-y-4">
            <div>
                <InputLabel for="password" value="Пароль" />
                <TextInput id="password" type="password" class="mt-1 block w-full" v-model="form.password" required autocomplete="current-password" autofocus placeholder="••••••••" />
                <InputError class="mt-1.5" :message="form.errors.password" />
            </div>

            <button type="submit"
                    :disabled="form.processing"
                    :class="{ 'opacity-50 cursor-not-allowed': form.processing }"
                    class="w-full px-4 py-2.5 rounded-lg bg-[#2563eb] hover:bg-[#1d4ed8] text-white font-semibold transition-colors">
                {{ form.processing ? 'Проверка…' : 'Подтвердить' }}
            </button>
        </form>
    </GuestLayout>
</template>

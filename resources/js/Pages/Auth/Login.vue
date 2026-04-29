<script setup>
import Checkbox from '@/Components/Checkbox.vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, useForm } from '@inertiajs/vue3';

defineProps({
    status: String,
});

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

const submit = () => {
    form.post(route('login'), {
        onFinish: () => form.reset('password'),
    });
};
</script>

<template>
    <GuestLayout>
        <Head title="Вход" />

        <h2 class="text-lg font-semibold text-gray-900 mb-1">Вход в систему</h2>
        <p class="text-xs text-gray-500 mb-5">Введите свои данные, чтобы продолжить</p>

        <div v-if="status" class="mb-4 px-3 py-2 rounded-md bg-green-50 border border-green-200 text-sm text-green-700">
            {{ status }}
        </div>

        <form @submit.prevent="submit" class="space-y-4">
            <div>
                <InputLabel for="email" value="Электронная почта" />
                <TextInput
                    id="email"
                    type="email"
                    class="mt-1 block w-full"
                    v-model="form.email"
                    required
                    autofocus
                    autocomplete="username"
                    placeholder="вы@example.com"
                />
                <InputError class="mt-1.5" :message="form.errors.email" />
            </div>

            <div>
                <InputLabel for="password" value="Пароль" />
                <TextInput
                    id="password"
                    type="password"
                    class="mt-1 block w-full"
                    v-model="form.password"
                    required
                    autocomplete="current-password"
                    placeholder="••••••••"
                />
                <InputError class="mt-1.5" :message="form.errors.password" />
            </div>

            <div class="flex items-center">
                <label class="flex items-center cursor-pointer">
                    <Checkbox name="remember" v-model:checked="form.remember" />
                    <span class="ml-2 text-sm text-gray-600">Запомнить меня</span>
                </label>
            </div>

            <button type="submit"
                    :disabled="form.processing"
                    :class="{ 'opacity-50 cursor-not-allowed': form.processing }"
                    class="w-full px-4 py-2.5 rounded-lg bg-[#2563eb] hover:bg-[#1d4ed8] text-white font-semibold transition-colors">
                {{ form.processing ? 'Вход…' : 'Войти' }}
            </button>
        </form>
    </GuestLayout>
</template>

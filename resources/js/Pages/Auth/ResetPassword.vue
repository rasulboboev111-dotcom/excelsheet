<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, useForm } from '@inertiajs/vue3';

const props = defineProps({
    email: String,
    token: String,
});

const form = useForm({
    token: props.token,
    email: props.email,
    password: '',
    password_confirmation: '',
});

const submit = () => {
    form.post(route('password.store'), {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
};
</script>

<template>
    <GuestLayout>
        <Head title="Сброс пароля" />

        <h2 class="text-lg font-semibold text-gray-900 mb-1">Сброс пароля</h2>
        <p class="text-xs text-gray-500 mb-5">Придумайте новый пароль для входа</p>

        <form @submit.prevent="submit" class="space-y-4">
            <div>
                <InputLabel for="email" value="Электронная почта" />
                <TextInput id="email" type="email" class="mt-1 block w-full" v-model="form.email" required autofocus autocomplete="username" />
                <InputError class="mt-1.5" :message="form.errors.email" />
            </div>

            <div>
                <InputLabel for="password" value="Новый пароль" />
                <TextInput id="password" type="password" class="mt-1 block w-full" v-model="form.password" required autocomplete="new-password" placeholder="••••••••" />
                <InputError class="mt-1.5" :message="form.errors.password" />
            </div>

            <div>
                <InputLabel for="password_confirmation" value="Подтверждение пароля" />
                <TextInput id="password_confirmation" type="password" class="mt-1 block w-full" v-model="form.password_confirmation" required autocomplete="new-password" placeholder="••••••••" />
                <InputError class="mt-1.5" :message="form.errors.password_confirmation" />
            </div>

            <button type="submit"
                    :disabled="form.processing"
                    :class="{ 'opacity-50 cursor-not-allowed': form.processing }"
                    class="w-full px-4 py-2.5 rounded-lg bg-[#2563eb] hover:bg-[#1d4ed8] text-white font-semibold transition-colors">
                {{ form.processing ? 'Сохранение…' : 'Сохранить новый пароль' }}
            </button>
        </form>
    </GuestLayout>
</template>

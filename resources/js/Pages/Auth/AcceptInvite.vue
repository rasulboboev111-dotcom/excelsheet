<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps({
    token: { type: String, required: true },
});

const form = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
});

const submit = () => {
    form.post(route('invitations.accept', props.token), {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
};
</script>

<template>
    <GuestLayout>
        <Head title="Регистрация по приглашению" />

        <h2 class="text-lg font-semibold text-gray-900 mb-1">Регистрация по приглашению</h2>
        <p class="text-xs text-gray-500 mb-5">Заполните форму, чтобы создать аккаунт</p>

        <form @submit.prevent="submit" class="space-y-4">
            <div>
                <InputLabel for="name" value="Имя" />
                <TextInput id="name" type="text" class="mt-1 block w-full" v-model="form.name" required autofocus autocomplete="name" placeholder="Иван Иванов" />
                <InputError class="mt-1.5" :message="form.errors.name" />
            </div>

            <div>
                <InputLabel for="email" value="Электронная почта" />
                <TextInput id="email" type="email" class="mt-1 block w-full" v-model="form.email" required autocomplete="username" placeholder="вы@example.com" />
                <InputError class="mt-1.5" :message="form.errors.email" />
            </div>

            <div>
                <InputLabel for="password" value="Пароль" />
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
                {{ form.processing ? 'Регистрация…' : 'Зарегистрироваться' }}
            </button>

            <div class="text-center text-sm text-gray-500 pt-2">
                Уже есть аккаунт?
                <Link :href="route('login')" class="text-[#2563eb] hover:text-[#1d4ed8] font-medium hover:underline ml-1">
                    Войти
                </Link>
            </div>
        </form>
    </GuestLayout>
</template>

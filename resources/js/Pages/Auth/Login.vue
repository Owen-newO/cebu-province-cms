<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AuthenticationCard from '@/Components/AuthenticationCard.vue';
import Checkbox from '@/Components/Checkbox.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';

defineProps({
    canResetPassword: Boolean,
    status: String,
});

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

const submit = () => {
    form.transform(data => ({
        ...data,
        remember: form.remember ? 'on' : '',
    })).post(route('login'), {
        onFinish: () => form.reset('password'),
    });
};
</script>

<template>
    <Head title="Log in" />

    <div
        style="
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            background-color: #f3f4f6;
            padding: 20px;
        "
    >
        <AuthenticationCard>
            <div v-if="status" style="margin-bottom: 16px; font-weight: 500; font-size: 14px; color: #16a34a;">
                {{ status }}
            </div>

            <form @submit.prevent="submit" style="width: 100%; max-width: 420px; min-width: 350px; ">
                <!-- Logo -->
                 
                <div style="display: flex; justify-content: center; margin-bottom: 24px;">
                    <img
                        src="/images/logo.png"
                        alt="Cebu Province CMS"
                        style="width: 200px; height: 180px; display: block; margin: 0 auto;"
                    />
                </div>

                <!-- Email -->
                <div>
                    <InputLabel for="email" value="Email" />
                    <TextInput
                        id="email"
                        v-model="form.email"
                        type="email"
                        required
                        autofocus
                        autocomplete="username"
                        style="
                            margin-top: 4px;
                            display: block;
                            width: 100%;
                            padding: 10px;
                            border: 1px solid #d1d5db;
                            border-radius: 6px;
                            outline: none;
                        "
                    />
                    <InputError style="margin-top: 8px;" :message="form.errors.email" />
                </div>

                <!-- Password -->
                <div style="margin-top: 16px;">
                    <InputLabel for="password" value="Password" />
                    <TextInput
                        id="password"
                        v-model="form.password"
                        type="password"
                        required
                        autocomplete="current-password"
                        style="
                            margin-top: 4px;
                            display: block;
                            width: 100%;
                            padding: 10px;
                            border: 1px solid #d1d5db;
                            border-radius: 6px;
                            outline: none;
                        "
                    />
                    <InputError style="margin-top: 8px;" :message="form.errors.password" />
                </div>

                <!-- Remember Me -->
                <div style="margin-top: 16px; display: flex; align-items: center;">
                    <Checkbox v-model:checked="form.remember" name="remember" />
                    <span style="margin-left: 8px; font-size: 14px; color: #4b5563;">Remember me</span>
                </div>

                <!-- Actions -->
                <div
                    style="
                        display: flex;
                        justify-content: space-between;
                        align-items: center;
                        margin-top: 20px;
                        flex-wrap: wrap;
                        gap: 10px;
                    "
                >
                    <!-- Forgot Password -->
                    <Link
                        v-if="canResetPassword"
                        :href="route('password.request')"
                        style="
                            font-size: 14px;
                            color: #4b5563;
                            text-decoration: underline;
                            transition: color 0.2s;
                        "
                        @mouseover="(e) => (e.target.style.color = '#111827')"
                        @mouseleave="(e) => (e.target.style.color = '#4b5563')"
                    >
                        Forgot your password?
                    </Link>
                    

                    <!-- Login Button -->
                    <PrimaryButton
                        style="
                            background-color: #2563eb;
                            color: white;
                            padding: 8px 20px;
                            border-radius: 6px;
                            font-weight: 600;
                            border: none;
                            cursor: pointer;
                            transition: opacity 0.2s;
                        "
                        :style="form.processing ? 'opacity:0.5;' : ''"
                        :disabled="form.processing"
                    >
                        Login
                    </PrimaryButton>
                </div>
            </form>
        </AuthenticationCard>
    </div>
</template>

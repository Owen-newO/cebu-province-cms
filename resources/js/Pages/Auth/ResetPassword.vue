<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import AuthenticationCard from '@/Components/AuthenticationCard.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';

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
    form.post(route('password.update'), {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
};
</script>

<template>
    <Head title="Reset Password" />

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
            <form @submit.prevent="submit" style="width: 100%; max-width: 420px; min-width: 350px;">
                <!-- Logo -->
                <div style="display: flex; justify-content: center; margin-bottom: 24px;">
                    <img
                        src="/images/logo.png"
                        alt="Suroy Cebu"
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
                        autocomplete="new-password"
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

                <!-- Confirm Password -->
                <div style="margin-top: 16px;">
                    <InputLabel for="password_confirmation" value="Confirm Password" />
                    <TextInput
                        id="password_confirmation"
                        v-model="form.password_confirmation"
                        type="password"
                        required
                        autocomplete="new-password"
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
                    <InputError style="margin-top: 8px;" :message="form.errors.password_confirmation" />
                </div>

                <!-- Action -->
                <div
                    style="
                        display: flex;
                        justify-content: flex-end;
                        align-items: center;
                        margin-top: 20px;
                    "
                >
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
                        Reset Password
                    </PrimaryButton>
                </div>
            </form>
        </AuthenticationCard>
    </div>
</template>

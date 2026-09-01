<script setup>
import { ref, nextTick, computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import AuthenticationCard from '@/Components/AuthenticationCard.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';

const props = defineProps({
    email: String,
    token: String,
});

const step = ref('email');            // 'email' | 'verify' | 'reset'
const email = ref(props.email || '');
const codeDigits = ref(['', '', '', '', '', '']);
const boxes = ref([]);
const password = ref('');
const passwordConfirm = ref('');

const loading = ref(false);
const emailError = ref('');
const codeError = ref('');
const passwordError = ref('');
const notice = ref('');

const code = computed(() => codeDigits.value.join(''));
const fieldStyle =
    'margin-top:4px; display:block; width:100%; padding:10px; border:1px solid #d1d5db; border-radius:6px; outline:none;';

// ---- CSRF-aware POST (keeps the page's step state) ----
const xsrf = () => {
    const m = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
    return m ? decodeURIComponent(m[1]) : '';
};
const post = (url, body) =>
    fetch(url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': xsrf(),
        },
        credentials: 'same-origin',
        body: JSON.stringify(body),
    });
const readError = async (res, fallback) => {
    try {
        const d = await res.json();
        return d.message || d.errors?.email?.[0] || d.errors?.code?.[0] || d.errors?.password?.[0] || fallback;
    } catch (_) {
        return fallback;
    }
};

// ---- Step 1: send code ----
const sendCode = async () => {
    emailError.value = '';
    if (!email.value) { emailError.value = 'Please enter your email.'; return; }
    loading.value = true;
    try {
        const res = await post(route('otp.code'), { email: email.value });
        if (res.ok) {
            step.value = 'verify';
            notice.value = `We sent a 6-digit code to ${email.value}.`;
            codeDigits.value = ['', '', '', '', '', ''];
            await nextTick();
            boxes.value[0]?.focus();
        } else {
            emailError.value = await readError(res, 'Could not send the code.');
        }
    } catch (_) {
        emailError.value = 'Network error. Please try again.';
    } finally {
        loading.value = false;
    }
};

const resend = async () => {
    codeError.value = '';
    await sendCode();
};

// ---- 6-box code input ----
const onInput = (i, e) => {
    const v = e.target.value.replace(/\D/g, '');
    codeDigits.value[i] = v ? v[v.length - 1] : '';
    e.target.value = codeDigits.value[i];
    if (codeDigits.value[i] && i < 5) boxes.value[i + 1]?.focus();
};
const onKeydown = (i, e) => {
    if (e.key === 'Backspace' && !codeDigits.value[i] && i > 0) boxes.value[i - 1]?.focus();
};
const onPaste = (e) => {
    const digits = (e.clipboardData?.getData('text') || '').replace(/\D/g, '').slice(0, 6).split('');
    if (!digits.length) return;
    e.preventDefault();
    for (let i = 0; i < 6; i++) codeDigits.value[i] = digits[i] || '';
    boxes.value[Math.min(digits.length, 5)]?.focus();
};

// ---- Step 2: verify code ----
const verify = async () => {
    codeError.value = '';
    if (code.value.length !== 6) { codeError.value = 'Enter the 6-digit code.'; return; }
    loading.value = true;
    try {
        const res = await post(route('otp.verify'), { email: email.value, code: code.value });
        if (res.ok) {
            step.value = 'reset';
            notice.value = '';
        } else {
            codeError.value = await readError(res, 'Invalid or expired code.');
        }
    } catch (_) {
        codeError.value = 'Network error. Please try again.';
    } finally {
        loading.value = false;
    }
};

// ---- Step 3: set new password ----
const complete = async () => {
    passwordError.value = '';
    if (password.value.length < 8) { passwordError.value = 'Password must be at least 8 characters.'; return; }
    if (password.value !== passwordConfirm.value) { passwordError.value = 'Passwords do not match.'; return; }
    loading.value = true;
    try {
        const res = await post(route('otp.complete'), {
            email: email.value,
            code: code.value,
            password: password.value,
            password_confirmation: passwordConfirm.value,
        });
        if (res.ok) {
            const d = await res.json().catch(() => ({}));
            window.location.href = d.redirect || route('login');
        } else {
            passwordError.value = await readError(res, 'Could not reset your password.');
        }
    } catch (_) {
        passwordError.value = 'Network error. Please try again.';
    } finally {
        loading.value = false;
    }
};
</script>

<template>
    <Head title="Reset Password" />

    <div style="min-height:100vh; display:flex; flex-direction:column; justify-content:center; align-items:center; background-color:#f3f4f6; padding:20px;">
        <AuthenticationCard>
            <div style="width:100%; max-width:420px; min-width:350px;">
                <!-- Logo -->
                <div style="display:flex; justify-content:center; margin-bottom:24px;">
                    <img src="/images/logo.png" alt="Suroy Cebu" style="width:200px; height:180px; display:block; margin:0 auto;" />
                </div>

                <!-- STEP 1: email -->
                <form v-if="step === 'email'" @submit.prevent="sendCode">
                    <p style="font-size:14px; color:#6b7280; margin:0 0 18px;">Enter your account email and we’ll send you a 6-digit verification code.</p>
                    <div>
                        <InputLabel for="email" value="Email" />
                        <TextInput id="email" v-model="email" type="email" required autofocus autocomplete="username" :style="fieldStyle" />
                        <p v-if="emailError" style="margin-top:8px; font-size:13px; color:#dc2626;">{{ emailError }}</p>
                    </div>
                    <PrimaryButton
                        style="margin-top:20px; width:100%; justify-content:center; background-color:#2563eb; color:#fff; padding:11px; border-radius:6px; font-weight:600; border:none; cursor:pointer; display:flex;"
                        :style="loading ? 'opacity:0.5;' : ''"
                        :disabled="loading"
                    >{{ loading ? 'Sending…' : 'Send code' }}</PrimaryButton>
                </form>

                <!-- STEP 2: verify code -->
                <form v-else-if="step === 'verify'" @submit.prevent="verify">
                    <p style="font-size:14px; color:#374151; margin:0 0 6px; font-weight:600;">Enter the 6-digit code</p>
                    <p v-if="notice" style="font-size:13px; color:#6b7280; margin:0 0 18px;">{{ notice }}</p>

                    <div style="display:flex; gap:8px; justify-content:space-between;" @paste="onPaste">
                        <input
                            v-for="(d, i) in codeDigits"
                            :key="i"
                            :ref="el => (boxes[i] = el)"
                            :value="codeDigits[i]"
                            @input="onInput(i, $event)"
                            @keydown="onKeydown(i, $event)"
                            inputmode="numeric"
                            maxlength="1"
                            autocomplete="one-time-code"
                            style="width:48px; height:56px; text-align:center; font-size:22px; font-weight:600; border:1px solid #d1d5db; border-radius:8px; outline:none; color:#111827;"
                        />
                    </div>
                    <p v-if="codeError" style="margin-top:10px; font-size:13px; color:#dc2626;">{{ codeError }}</p>

                    <PrimaryButton
                        style="margin-top:20px; width:100%; justify-content:center; background-color:#2563eb; color:#fff; padding:11px; border-radius:6px; font-weight:600; border:none; cursor:pointer; display:flex;"
                        :style="loading ? 'opacity:0.5;' : ''"
                        :disabled="loading"
                    >{{ loading ? 'Verifying…' : 'Verify' }}</PrimaryButton>

                    <div style="margin-top:16px; text-align:center; font-size:13px; color:#6b7280;">
                        Didn’t get it?
                        <a href="#" @click.prevent="resend" style="color:#2563eb; text-decoration:underline;">Resend code</a>
                    </div>
                </form>

                <!-- STEP 3: set password -->
                <form v-else @submit.prevent="complete">
                    <div style="display:flex; align-items:center; gap:8px; background:#ecfdf3; border:1px solid #bbf7d0; border-radius:8px; padding:10px 12px; margin-bottom:18px;">
                        <span style="display:inline-flex; width:20px; height:20px; border-radius:50%; background:#16a34a; color:#fff; align-items:center; justify-content:center; font-size:12px; font-weight:700;">✓</span>
                        <span style="font-size:13px; color:#166534;"><strong>Verified.</strong> Set a new password for <strong>{{ email }}</strong>.</span>
                    </div>

                    <div>
                        <InputLabel for="email_ro" value="Email" />
                        <TextInput id="email_ro" :model-value="email" type="email" readonly :style="fieldStyle + 'background:#f3f4f6; color:#6b7280;'" />
                    </div>

                    <div style="margin-top:16px;">
                        <InputLabel for="password" value="Password" />
                        <TextInput id="password" v-model="password" type="password" required autofocus autocomplete="new-password" :style="fieldStyle" />
                    </div>

                    <div style="margin-top:16px;">
                        <InputLabel for="password_confirmation" value="Confirm Password" />
                        <TextInput id="password_confirmation" v-model="passwordConfirm" type="password" required autocomplete="new-password" :style="fieldStyle" />
                    </div>
                    <p v-if="passwordError" style="margin-top:10px; font-size:13px; color:#dc2626;">{{ passwordError }}</p>

                    <PrimaryButton
                        style="margin-top:20px; width:100%; justify-content:center; background-color:#2563eb; color:#fff; padding:11px; border-radius:6px; font-weight:600; border:none; cursor:pointer; display:flex;"
                        :style="loading ? 'opacity:0.5;' : ''"
                        :disabled="loading"
                    >{{ loading ? 'Saving…' : 'Reset Password' }}</PrimaryButton>
                </form>
            </div>
        </AuthenticationCard>
    </div>
</template>

<script setup>
import { ref } from "vue";
import { Head, useForm } from "@inertiajs/vue3";

const props = defineProps({
  token: String,
  valid: Boolean,
  reason: String, // invalid | used | expired | deactivated | null
  municipal_name: String,
});

const submitted = ref(false);

const form = useForm({
  token: props.token,
  representative_name: "",
  email: "",
  phone: "",
});

const reasonText = {
  invalid: "This invitation link doesn't exist.",
  used: "This invitation link has already been used.",
  expired: "This invitation link has expired.",
  deactivated: "This invitation link has been deactivated.",
};

const submit = () => {
  form.post(route("lgu.signup.store"), {
    onSuccess: () => (submitted.value = true),
  });
};
</script>

<template>
  <Head title="LGU Sign-up" />
  <div style="min-height:100vh; display:flex; align-items:center; justify-content:center; background:#f5f6fa; font-family:'Inter', sans-serif; padding:24px;">
    <div style="background:#fff; border-radius:14px; padding:36px; width:100%; max-width:440px; border:1px solid #e5e7eb;">

      <template v-if="!valid && !submitted">
        <h1 style="font-size:20px; font-weight:700; margin-bottom:10px;">Link unavailable</h1>
        <p style="color:#6b7280; font-size:15px;">{{ reasonText[reason] || "This invitation link isn't valid." }}</p>
      </template>

      <template v-else-if="submitted">
        <h1 style="font-size:20px; font-weight:700; margin-bottom:10px;">Application submitted 🎉</h1>
        <p style="color:#6b7280; font-size:15px;">The province will review your application. You'll receive an email once it's approved.</p>
      </template>

      <template v-else>
        <h1 style="font-size:20px; font-weight:700; margin-bottom:4px;">LGU Sign-up</h1>
        <p style="color:#6b7280; font-size:14px; margin-bottom:20px;">Register on behalf of <b>{{ municipal_name }}</b>.</p>

        <form @submit.prevent="submit" style="display:flex; flex-direction:column; gap:14px;">
          <div>
            <label style="font-size:13px; font-weight:600; display:block; margin-bottom:6px;">Representative name</label>
            <input v-model="form.representative_name" type="text" required
              style="width:100%; padding:12px 14px; border-radius:10px; border:1px solid #d1d5db; font-size:15px;" />
            <p v-if="form.errors.representative_name" style="color:#ef4444; font-size:12px; margin-top:4px;">{{ form.errors.representative_name }}</p>
          </div>

          <div>
            <label style="font-size:13px; font-weight:600; display:block; margin-bottom:6px;">Email address</label>
            <input v-model="form.email" type="email" required
              style="width:100%; padding:12px 14px; border-radius:10px; border:1px solid #d1d5db; font-size:15px;" />
            <p v-if="form.errors.email" style="color:#ef4444; font-size:12px; margin-top:4px;">{{ form.errors.email }}</p>
          </div>

          <div>
            <label style="font-size:13px; font-weight:600; display:block; margin-bottom:6px;">Phone number</label>
            <input v-model="form.phone" type="text" required
              style="width:100%; padding:12px 14px; border-radius:10px; border:1px solid #d1d5db; font-size:15px;" />
            <p v-if="form.errors.phone" style="color:#ef4444; font-size:12px; margin-top:4px;">{{ form.errors.phone }}</p>
          </div>

          <button type="submit" :disabled="form.processing"
            style="margin-top:8px; padding:14px; border-radius:10px; border:none; background:#65a30d; color:#fff; font-weight:700; font-size:15px; cursor:pointer;">
            {{ form.processing ? "Submitting…" : "Submit application" }}
          </button>
        </form>
      </template>

    </div>
  </div>
</template>

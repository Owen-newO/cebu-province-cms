<script setup>
import { ref, computed } from "vue";
import { Head, router } from "@inertiajs/vue3";

const props = defineProps({
  municipalities: Array,   // [{ slug, name, already_registered }]
  invitations: Array,      // [{ id, municipal_slug, municipal_name, token, link, status, expires_at, created_at }]
  applications: Array,     // [{ id, municipal_slug, municipal_name, representative_name, email, phone, status, submitted_at, reviewed_at, reviewed_by }]
});

const selectedMunicipal = ref("");
const busy = ref(false);
const statusFilter = ref("all"); // all | pending | approved | declined
const selectedApplication = ref(null); // opens the modal when set

const sortedMunicipalities = computed(() =>
  [...(props.municipalities || [])].sort((a, b) => a.name.localeCompare(b.name))
);

const filteredApplications = computed(() => {
  if (statusFilter.value === "all") return props.applications || [];
  return (props.applications || []).filter((a) => a.status === statusFilter.value);
});

const statusColors = {
  active: "#16a34a",
  used: "#6b7280",
  expired: "#b91c1c",
  deactivated: "#6b7280",
  pending: "#b45309",
  approved: "#16a34a",
  declined: "#b91c1c",
};

const formatDate = (d) => (d ? new Date(d).toLocaleString() : "—");

const generateLink = () => {
  if (!selectedMunicipal.value) {
    alert("Select a municipality first.");
    return;
  }
  busy.value = true;
  router.post(
    route("superadmin.invitations.store"),
    { municipal_slug: selectedMunicipal.value },
    {
      preserveScroll: true,
      onSuccess: () => {
        selectedMunicipal.value = "";
      },
      onError: () => alert("Failed to generate the link."),
      onFinish: () => (busy.value = false),
    }
  );
};

const copyLink = async (link) => {
  try {
    await navigator.clipboard.writeText(link);
    alert("Link copied.");
  } catch (e) {
    alert(link); // clipboard unavailable — at least show it
  }
};

const deactivateLink = (invitation) => {
  if (!window.confirm(`Deactivate the invitation link for ${invitation.municipal_name}?`)) return;
  router.post(
    route("superadmin.invitations.deactivate", invitation.id),
    {},
    { preserveScroll: true, onError: () => alert("Failed to deactivate.") }
  );
};

const openApplication = (application) => {
  selectedApplication.value = application;
};
const closeModal = () => {
  selectedApplication.value = null;
};

const approve = (application) => {
  if (!window.confirm(`Approve ${application.representative_name} for ${application.municipal_name}? This creates their login account.`)) return;
  router.post(
    route("superadmin.applications.approve", application.id),
    {},
    {
      preserveScroll: true,
      onSuccess: closeModal,
      onError: () => alert("Failed to approve."),
    }
  );
};

const decline = (application) => {
  if (!window.confirm(`Decline ${application.representative_name}'s application?`)) return;
  router.post(
    route("superadmin.applications.decline", application.id),
    {},
    {
      preserveScroll: true,
      onSuccess: closeModal,
      onError: () => alert("Failed to decline."),
    }
  );
};

const logout = () => router.post(route("logout"));
</script>

<template>
  <Head title="Super Admin Dashboard" />
  <div style="min-height:100vh; background:#f5f6fa; font-family:'Inter', sans-serif; color:#222;">
    <header style="background:#0f172a; padding:20px 40px; display:flex; justify-content:space-between; align-items:center;">
      <h1 style="color:#fff; font-size:22px; font-weight:700;">SUPER ADMIN DASHBOARD</h1>
      <button @click="logout" style="padding:10px 16px; border-radius:10px; border:1px solid #334155; background:transparent; color:#fff; cursor:pointer; font-size:14px;">Logout</button>
    </header>

    <main style="max-width:1100px; margin:0 auto; padding:32px 24px; display:flex; flex-direction:column; gap:24px;">

      <!-- GENERATE INVITATION -->
      <section style="background:#fff; border-radius:12px; padding:24px; border:1px solid #e5e7eb;">
        <h2 style="font-size:12px; letter-spacing:.06em; color:#94a3b8; text-transform:uppercase; margin-bottom:14px;">Generate Invitation</h2>
        <label style="font-size:14px; font-weight:600; display:block; margin-bottom:6px;">Municipality</label>
        <div style="display:flex; gap:12px; flex-wrap:wrap;">
          <select v-model="selectedMunicipal" style="flex:1; min-width:240px; padding:12px 14px; border-radius:10px; border:1px solid #d1d5db; font-size:15px;">
            <option value="">Select a municipality</option>
            <option v-for="m in sortedMunicipalities" :key="m.slug" :value="m.slug">
              {{ m.name }}{{ m.already_registered ? " (already registered)" : "" }}
            </option>
          </select>
          <button @click="generateLink" :disabled="busy" style="padding:12px 24px; border-radius:10px; border:none; background:#65a30d; color:#fff; font-weight:600; cursor:pointer; font-size:15px;">
            {{ busy ? "Working…" : "🔄 Generate link" }}
          </button>
        </div>
        <p style="font-size:13px; color:#94a3b8; margin-top:10px;">
          <span v-if="!selectedMunicipal">ⓘ Select a municipality to generate an invitation link.</span>
          <span v-else>Link expires in 24 hours and can only be used once.</span>
        </p>
      </section>

      <!-- ACTIVE INVITATIONS -->
      <section style="background:#fff; border-radius:12px; padding:24px; border:1px solid #e5e7eb;">
        <h2 style="font-size:12px; letter-spacing:.06em; color:#94a3b8; text-transform:uppercase; margin-bottom:4px;">Application Links</h2>
        <p style="font-size:13px; color:#94a3b8; margin-bottom:16px;">Each municipality keeps its own invite. Generating a new one does not invalidate other municipalities' links.</p>

        <p v-if="!invitations.length" style="color:#94a3b8; font-size:14px;">No invitations generated yet.</p>

        <div style="display:flex; flex-direction:column; gap:10px;">
          <div v-for="inv in invitations" :key="inv.id" style="background:#f8fafc; border-radius:10px; padding:14px 16px; display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap;">
            <div>
              <div style="font-weight:700; font-size:14px;">{{ inv.municipal_name }}</div>
              <div style="font-size:13px; color:#2563eb; word-break:break-all;">{{ inv.link }}</div>
              <div style="font-size:12px; color:#94a3b8; margin-top:2px;">expires {{ formatDate(inv.expires_at) }}</div>
            </div>
            <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
              <span :style="`font-size:12px; font-weight:700; text-transform:capitalize; color:#fff; padding:4px 10px; border-radius:999px; background:${statusColors[inv.status]};`">{{ inv.status }}</span>
              <button @click="copyLink(inv.link)" style="padding:8px 14px; border-radius:8px; border:none; background:#0f172a; color:#fff; font-size:13px; cursor:pointer;">📋 Copy</button>
              <button v-if="inv.status === 'active'" @click="deactivateLink(inv)" style="padding:8px 14px; border-radius:8px; border:1px solid #ef4444; background:#fff; color:#ef4444; font-size:13px; cursor:pointer;">🗑️ Deactivate</button>
            </div>
          </div>
        </div>
      </section>

      <!-- APPLICATIONS -->
      <section style="background:#fff; border-radius:12px; padding:24px; border:1px solid #e5e7eb;">
        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:16px;">
          <div>
            <h2 style="font-size:12px; letter-spacing:.06em; color:#94a3b8; text-transform:uppercase;">Applications</h2>
            <p style="font-size:13px; color:#94a3b8;">Select a row to review details and approve or decline.</p>
          </div>
          <div style="display:flex; gap:6px;">
            <button v-for="f in ['all','pending','approved','declined']" :key="f" @click="statusFilter = f"
              :style="`padding:8px 16px; border-radius:999px; border:1px solid ${statusFilter===f ? '#0f172a' : '#d1d5db'}; background:${statusFilter===f ? '#0f172a' : '#fff'}; color:${statusFilter===f ? '#fff' : '#374151'}; font-size:13px; cursor:pointer; text-transform:capitalize;`">
              {{ f }}
            </button>
          </div>
        </div>

        <p v-if="!filteredApplications.length" style="color:#94a3b8; font-size:14px;">No applications here yet.</p>

        <table v-else style="width:100%; border-collapse:collapse; font-size:14px;">
          <thead>
            <tr style="text-align:left; color:#94a3b8; font-size:12px; text-transform:uppercase;">
              <th style="padding:8px;">Representative</th>
              <th style="padding:8px;">Municipality</th>
              <th style="padding:8px;">Date</th>
              <th style="padding:8px;">Status</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="app in filteredApplications" :key="app.id" @click="openApplication(app)"
              style="cursor:pointer; border-top:1px solid #f1f5f9;">
              <td style="padding:12px 8px;">
                <div style="font-weight:600;">{{ app.representative_name }}</div>
                <div style="font-size:12px; color:#94a3b8;">{{ app.email }}</div>
              </td>
              <td style="padding:12px 8px;">{{ app.municipal_name }}</td>
              <td style="padding:12px 8px;">{{ formatDate(app.submitted_at) }}</td>
              <td style="padding:12px 8px;">
                <span :style="`font-size:12px; font-weight:700; text-transform:capitalize; color:#fff; padding:4px 10px; border-radius:999px; background:${statusColors[app.status]};`">{{ app.status }}</span>
              </td>
            </tr>
          </tbody>
        </table>
      </section>
    </main>

    <!-- APPLICATION DETAIL MODAL -->
    <div v-if="selectedApplication" @click.self="closeModal" style="position:fixed; inset:0; background:rgba(15,23,42,.5); display:flex; align-items:center; justify-content:center; z-index:1000;">
      <div style="background:#fff; border-radius:14px; padding:28px; width:100%; max-width:380px; position:relative;">
        <button @click="closeModal" style="position:absolute; top:14px; right:14px; border:none; background:none; font-size:18px; cursor:pointer; color:#94a3b8;">✕</button>

        <div style="font-size:12px; color:#94a3b8; text-transform:uppercase; letter-spacing:.04em;">Representative</div>
        <div style="font-size:17px; font-weight:700; margin-bottom:10px;">{{ selectedApplication.representative_name }}</div>

        <div style="font-size:12px; color:#94a3b8; text-transform:uppercase; letter-spacing:.04em;">Municipality</div>
        <div style="font-size:15px; font-weight:600; margin-bottom:10px;">{{ selectedApplication.municipal_name }}</div>

        <span :style="`display:inline-block; margin-bottom:16px; font-size:12px; font-weight:700; text-transform:capitalize; color:#fff; padding:4px 10px; border-radius:999px; background:${statusColors[selectedApplication.status]};`">{{ selectedApplication.status }}</span>

        <label style="font-size:12px; color:#94a3b8;">Email address</label>
        <div style="padding:10px 12px; border:1px solid #e5e7eb; border-radius:8px; margin-bottom:10px; font-size:14px;">{{ selectedApplication.email }}</div>

        <label style="font-size:12px; color:#94a3b8;">Phone number</label>
        <div style="padding:10px 12px; border:1px solid #e5e7eb; border-radius:8px; margin-bottom:10px; font-size:14px;">{{ selectedApplication.phone }}</div>

        <label style="font-size:12px; color:#94a3b8;">Submitted</label>
        <div style="padding:10px 12px; border:1px solid #e5e7eb; border-radius:8px; margin-bottom:10px; font-size:14px;">{{ formatDate(selectedApplication.submitted_at) }}</div>

        <label style="font-size:12px; color:#94a3b8;">Reviewed</label>
        <div style="padding:10px 12px; border:1px solid #e5e7eb; border-radius:8px; margin-bottom:16px; font-size:14px;">
          {{ selectedApplication.reviewed_at ? formatDate(selectedApplication.reviewed_at) + " by " + selectedApplication.reviewed_by : "—" }}
        </div>

        <div v-if="selectedApplication.status === 'pending'" style="display:flex; gap:10px;">
          <button @click="decline(selectedApplication)" style="flex:1; padding:12px; border-radius:10px; border:1px solid #ef4444; background:#fff; color:#ef4444; font-weight:600; cursor:pointer;">Decline</button>
          <button @click="approve(selectedApplication)" style="flex:1; padding:12px; border-radius:10px; border:none; background:#16a34a; color:#fff; font-weight:600; cursor:pointer;">Approve</button>
        </div>
      </div>
    </div>
  </div>
</template>

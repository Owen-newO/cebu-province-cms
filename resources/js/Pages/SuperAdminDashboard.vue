<script setup>
import { ref, computed } from "vue";
import { Head, router } from "@inertiajs/vue3";

const props = defineProps({
  municipalities: Array, // [{ slug, name, already_registered }]
  invitations: Array,    // [{ id, municipal_slug, municipal_name, token, link, status, expires_at, created_at }]
  applications: Array,   // [{ id, municipal_slug, municipal_name, representative_name, email, phone, status, submitted_at, reviewed_at, reviewed_by }]
  stats: Array,          // [{ label, value }]
  activity: Array,       // [{ text, at }]
  municipalityOverview: Array, // [{ slug, name, registered, latestStatus, totalScenes, publishedScenes }]
});

// ------------------------------------------------------------------
// Nav — "Municipalities" is still unbuilt (a future dedicated page, not
// just the Overview table), so it's the only one left marked "Soon".
// Everything else here is real.
// ------------------------------------------------------------------
const NAV_ITEMS = [
  { key: "overview", label: "Overview", soon: false },
  { key: "municipalities", label: "Municipalities", soon: true },
  { key: "applications", label: "Applications", soon: false },
  { key: "scenes", label: "360° Scenes", soon: false },
  { key: "invitations", label: "Invitations", soon: false },
];
const activeNav = ref("overview");

// ------------------------------------------------------------------
// Applications: search / filter / selection
// ------------------------------------------------------------------
const query = ref("");
const filter = ref("All");
const selectedId = ref(props.applications[0]?.id ?? null);
const busy = ref("");

const STATUS_COLORS = {
  pending: { dot: "#F59E0B", text: "#B45309" },
  approved: { dot: "#22C55E", text: "#15803D" },
  declined: { dot: "#EF4444", text: "#B91C1C" },
};
const cap = (s) => s.charAt(0).toUpperCase() + s.slice(1);

const filteredApplications = computed(() => {
  const q = query.value.trim().toLowerCase();
  return props.applications.filter((a) => {
    const matchesFilter = filter.value === "All" || a.status === filter.value.toLowerCase();
    const matchesQuery =
      !q ||
      a.municipal_name.toLowerCase().includes(q) ||
      a.representative_name.toLowerCase().includes(q);
    return matchesFilter && matchesQuery;
  });
});

const selected = computed(() => props.applications.find((a) => a.id === selectedId.value) || null);

const filteredMunicipalityOverview = computed(() => {
  const q = query.value.trim().toLowerCase();
  if (!q) return props.municipalityOverview;
  return props.municipalityOverview.filter((r) => r.name.toLowerCase().includes(q));
});

const filteredInvitations = computed(() => {
  const q = query.value.trim().toLowerCase();
  if (!q) return props.invitations;
  return props.invitations.filter((i) => i.municipal_name.toLowerCase().includes(q));
});

const formatDate = (d) => (d ? new Date(d).toLocaleDateString() : "—");
const formatDateTime = (d) => (d ? new Date(d).toLocaleString() : "—");

const timeAgo = (d) => {
  if (!d) return "";
  const diffMs = Date.now() - new Date(d).getTime();
  const mins = Math.round(diffMs / 60000);
  if (mins < 60) return `${mins} minute${mins === 1 ? "" : "s"} ago`;
  const hrs = Math.round(mins / 60);
  if (hrs < 24) return `${hrs} hour${hrs === 1 ? "" : "s"} ago`;
  const days = Math.round(hrs / 24);
  if (days === 1) return "Yesterday";
  return `${days} days ago`;
};

// ------------------------------------------------------------------
// Actions (Inertia POSTs — same pattern as before)
// ------------------------------------------------------------------
const approve = (app) => {
  if (!window.confirm(`Approve ${app.representative_name} for ${app.municipal_name}? This creates their login account.`)) return;
  router.post(route("superadmin.applications.approve", app.id), {}, {
    preserveScroll: true,
    onError: () => alert("Failed to approve."),
  });
};
const decline = (app) => {
  if (!window.confirm(`Decline ${app.representative_name}'s application?`)) return;
  router.post(route("superadmin.applications.decline", app.id), {}, {
    preserveScroll: true,
    onError: () => alert("Failed to decline."),
  });
};

const exportCsv = () => {
  const rows = [["Municipality", "Representative", "Email", "Phone", "Submitted", "Status"]];
  filteredApplications.value.forEach((a) =>
    rows.push([a.municipal_name, a.representative_name, a.email, a.phone, formatDate(a.submitted_at), a.status])
  );
  const csv = rows.map((r) => r.map((v) => `"${String(v).replace(/"/g, '""')}"`).join(",")).join("\n");
  const blob = new Blob([csv], { type: "text/csv" });
  const url = URL.createObjectURL(blob);
  const a = document.createElement("a");
  a.href = url;
  a.download = "applications.csv";
  a.click();
  URL.revokeObjectURL(url);
};

// ------------------------------------------------------------------
// Generate Invitation
// ------------------------------------------------------------------
const selectedMunicipal = ref("");
const sortedMunicipalities = computed(() =>
  [...props.municipalities].sort((a, b) => a.name.localeCompare(b.name))
);

const generateLink = () => {
  if (!selectedMunicipal.value) return;
  busy.value = "generate";
  router.post(
    route("superadmin.invitations.store"),
    { municipal_slug: selectedMunicipal.value },
    {
      preserveScroll: true,
      onSuccess: () => (selectedMunicipal.value = ""),
      onError: () => alert("Failed to generate the link."),
      onFinish: () => (busy.value = ""),
    }
  );
};

// ------------------------------------------------------------------
// Invitation Links
// ------------------------------------------------------------------
const copiedLink = ref(null);
const copyLink = async (link) => {
  try {
    await navigator.clipboard.writeText(link);
  } catch (e) {
    /* clipboard unavailable — the link still shows on screen to copy manually */
  }
  copiedLink.value = link;
  setTimeout(() => {
    if (copiedLink.value === link) copiedLink.value = null;
  }, 2000);
};
const deactivateLink = (inv) => {
  if (!window.confirm(`Deactivate the invitation link for ${inv.municipal_name}?`)) return;
  router.post(route("superadmin.invitations.deactivate", inv.id), {}, {
    preserveScroll: true,
    onError: () => alert("Failed to deactivate."),
  });
};

const invitationRightLabel = (inv) => {
  if (inv.status === "used") return "Used";
  if (inv.status === "deactivated") return "Deactivated";
  if (inv.status === "expired") return "Expired";
  const hoursLeft = Math.max(0, Math.round((new Date(inv.expires_at) - Date.now()) / 3600000));
  return `Expires in ${hoursLeft}h`;
};
const invitationIsWarn = (inv) => {
  if (inv.status !== "active") return false;
  return new Date(inv.expires_at) - Date.now() < 6 * 3600000; // < 6h left
};

const logout = () => router.post(route("logout"));

// ------------------------------------------------------------------
// Overview: per-municipality breakdown
// ------------------------------------------------------------------
const overviewStatusLabel = (row) => {
  if (row.registered) return "Registered";
  if (row.latestStatus === "pending") return "Pending";
  if (row.latestStatus === "declined") return "Declined";
  return "Not registered";
};
const overviewStatusColor = (row) => {
  if (row.registered) return { dot: "#22C55E", text: "#15803D" };
  if (row.latestStatus === "pending") return { dot: "#F59E0B", text: "#B45309" };
  if (row.latestStatus === "declined") return { dot: "#EF4444", text: "#B91C1C" };
  return { dot: "#9CA3AF", text: "#6B7280" };
};

// ------------------------------------------------------------------
// 360° Scenes tab — read-only oversight. Nothing loads until a
// municipality is explicitly picked.
// ------------------------------------------------------------------
const scenesMunicipal = ref("");
const scenesList = ref([]);
const scenesLoading = ref(false);

const getImageUrl = (path) => {
  if (!path) return "/images/sample1.jpg";
  if (path.startsWith("http://") || path.startsWith("https://")) return path;
  return "/" + path.replace(/^\/+/, "");
};
const getThumbnail = (panoPath) => {
  if (!panoPath) return "";
  const parts = panoPath.split("/");
  const file = parts.pop();
  const sceneName = file.replace(/\.[^/.]+$/, "");
  const base = parts.join("/");
  return `${base}/panos/${sceneName}.tiles/thumb.jpg`;
};

const loadScenes = async () => {
  if (!scenesMunicipal.value) {
    scenesList.value = [];
    return;
  }
  scenesLoading.value = true;
  try {
    const res = await fetch(`/api/scenes/${scenesMunicipal.value}`);
    scenesList.value = res.ok ? await res.json() : [];
  } catch (e) {
    scenesList.value = [];
  } finally {
    scenesLoading.value = false;
  }
};

// Group scenes sharing the same title into one card (same convention as
// the municipal admin dashboard's own scene list).
const groupedScenes = computed(() => {
  const grouped = {};
  scenesList.value.forEach((scene) => {
    const title = scene.title?.trim() || "Untitled";
    if (!grouped[title]) grouped[title] = { title, cover: scene, items: [scene] };
    else grouped[title].items.push(scene);
  });
  return Object.values(grouped);
});

const openGroup = ref(null); // the group currently shown in the "view" modal
</script>

<template>
  <Head title="Super Admin Dashboard">
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin="" />
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
  </Head>

  <div style="display:flex; min-height:100vh; align-items:stretch; font-family:'Poppins', sans-serif; background:#f9fafb; color:#111827;">

    <!-- SIDEBAR -->
    <aside style="width:224px; flex:0 0 224px; background:#052e1a; color:#fff; display:flex; flex-direction:column;">
      <div style="height:64px; flex:0 0 64px; display:flex; align-items:center; gap:10px; padding:0 20px; border-bottom:1px solid rgba(255,255,255,.1);">
        <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" style="flex:0 0 26px;">
          <circle cx="12" cy="12" r="10"></circle><path d="M2 12h20"></path><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"></path>
        </svg>
        <span style="display:flex; align-items:baseline; gap:7px; min-width:0;">
          <span style="font-size:13.5px; font-weight:700; letter-spacing:-0.2px; white-space:nowrap;">360° Scenes</span>
          <span style="font-size:9px; font-weight:700; text-transform:uppercase; letter-spacing:1.2px; color:#fcd34d; background:rgba(251,191,36,.1); border:1px solid rgba(251,191,36,.3); padding:2px 4px; border-radius:4px; white-space:nowrap;">Admin</span>
        </span>
      </div>

      <nav style="flex:1; padding:16px 8px; display:flex; flex-direction:column; gap:4px;">
        <button
          v-for="item in NAV_ITEMS"
          :key="item.key"
          type="button"
          :disabled="item.soon"
          @click="activeNav = item.key"
          :style="`display:flex; align-items:center; gap:10px; width:100%; padding:10px 12px; border:none; border-radius:8px; font-size:13px; font-weight:600; transition:background .18s; background:${activeNav === item.key ? 'rgba(255,255,255,.15)' : 'transparent'}; color:${item.soon ? 'rgba(255,255,255,.35)' : (activeNav === item.key ? '#fff' : 'rgba(255,255,255,.85)')}; cursor:${item.soon ? 'default' : 'pointer'};`"
        >
          <span style="flex:1; text-align:left;">{{ item.label }}</span>
          <span v-if="item.key === 'applications' && applications.filter(a => a.status === 'pending').length" style="font-size:10px; font-weight:700; background:#fbbf24; color:#052e1a; border-radius:999px; padding:2px 6px; min-width:18px; text-align:center;">{{ applications.filter(a => a.status === 'pending').length }}</span>
          <span v-else-if="item.soon" style="font-size:9px; font-weight:600; color:rgba(255,255,255,.3);">Soon</span>
        </button>
      </nav>

      <div style="border-top:1px solid rgba(255,255,255,.1); padding:14px 16px; display:flex; align-items:center; gap:10px;">
        <span style="width:32px; height:32px; flex:0 0 32px; border-radius:999px; background:linear-gradient(135deg, #fcd34d, #f59e0b); color:#052e1a; display:grid; place-items:center; font-size:12px; font-weight:700;">S</span>
        <span style="min-width:0;">
          <span style="display:block; font-size:12px; font-weight:600;">Super Admin</span>
          <span style="display:block; font-size:10px; color:rgba(167,243,208,.6);">Platform owner</span>
        </span>
      </div>
    </aside>

    <!-- MAIN -->
    <main style="flex:1; min-width:0; display:flex; flex-direction:column;">

      <header style="background:#fff; border-bottom:1px solid #e5e7eb; min-height:64px; padding:12px 32px; display:flex; align-items:center; gap:16px; flex-wrap:wrap; position:sticky; top:0; z-index:20;">
        <div style="min-width:0;">
          <h1 style="margin:0; font-size:15px; font-weight:600; color:#111827;">Super Admin Dashboard</h1>
          <p style="margin:2px 0 0; font-size:12px; color:#6b7280;">Municipality onboarding and 360° scene oversight</p>
        </div>
        <div style="flex:1;"></div>
        <div style="position:relative; width:256px;">
          <span style="position:absolute; left:12px; top:50%; transform:translateY(-50%); color:#9ca3af; display:flex;">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><path d="m21 21-4.3-4.3"></path></svg>
          </span>
          <input v-model="query" type="text" placeholder="Search municipalities…" style="width:100%; padding:9px 14px 9px 34px; border:1px solid #e5e7eb; border-radius:8px; font-size:13px; font-family:inherit;" />
        </div>
        <button type="button" @click="logout" style="padding:9px 18px; border:1px solid #d1d5db; border-radius:8px; background:#fff; font-size:13px; font-weight:500; color:#374151; cursor:pointer;">Logout</button>
      </header>

      <div style="flex:1; padding:24px 32px 44px; display:flex; flex-direction:column; gap:20px; max-width:1440px; width:100%; margin:0 auto; box-sizing:border-box;">

        <!-- ================= OVERVIEW ================= -->
        <template v-if="activeNav === 'overview'">
          <section style="display:grid; grid-template-columns:repeat(auto-fit, minmax(210px, 1fr)); gap:16px;">
            <div v-for="s in stats" :key="s.label" style="background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:18px 20px;">
              <div style="font-size:11px; font-weight:600; letter-spacing:0.6px; text-transform:uppercase; color:#6b7280;">{{ s.label }}</div>
              <div style="margin-top:4px; font-size:26px; font-weight:700; color:#111827; letter-spacing:-0.6px;">{{ s.value }}</div>
            </div>
          </section>

          <section style="display:flex; flex-wrap:wrap; gap:20px; align-items:flex-start;">
            <div style="flex:1 1 640px; min-width:0; background:#fff; border:1px solid #e5e7eb; border-radius:8px; overflow:hidden;">
              <div style="padding:14px 16px; border-bottom:1px solid #f3f4f6;">
                <div style="font-size:11px; font-weight:600; letter-spacing:0.6px; text-transform:uppercase; color:#6b7280;">Municipalities</div>
                <div style="margin-top:2px; font-size:12px; color:#9ca3af;">Every LGU in the province and where it stands.</div>
              </div>
              <div style="display:grid; grid-template-columns:1.6fr 1fr 0.9fr 0.9fr; gap:8px; padding:10px 16px; background:#f9fafb; font-size:11px; font-weight:700; letter-spacing:0.6px; text-transform:uppercase; color:#111827;">
                <span>Municipality</span><span>Status</span><span>Scenes</span><span>Published</span>
              </div>
              <div style="padding:8px; display:flex; flex-direction:column; gap:6px; max-height:560px; overflow-y:auto;">
                <div
                  v-for="row in filteredMunicipalityOverview"
                  :key="row.slug"
                  style="display:grid; grid-template-columns:1.6fr 1fr 0.9fr 0.9fr; gap:8px; align-items:center; padding:10px 12px; border-radius:6px; background:#f9fafb;"
                >
                  <span style="font-size:12px; font-weight:600; color:#111827;">{{ row.name }}</span>
                  <span style="display:inline-flex; align-items:center; gap:6px; font-size:11.5px; font-weight:500;" :style="{ color: overviewStatusColor(row).text }">
                    <span :style="`width:8px; height:8px; border-radius:999px; background:${overviewStatusColor(row).dot};`"></span>{{ overviewStatusLabel(row) }}
                  </span>
                  <span style="font-size:11.5px; color:#374151;">{{ row.totalScenes }}</span>
                  <span style="font-size:11.5px; color:#374151;">{{ row.publishedScenes }}</span>
                </div>
                <div v-if="!filteredMunicipalityOverview.length" style="padding:32px 16px; text-align:center; font-size:13px; color:#9ca3af;">No municipalities match your search.</div>
              </div>
            </div>

            <div style="flex:1 1 340px; max-width:380px; min-width:300px; background:#052e1a; border-radius:8px; padding:18px 20px; color:#fff;">
              <div style="font-size:11px; font-weight:600; letter-spacing:0.6px; text-transform:uppercase; color:rgba(167,243,208,.65);">Recent Activity</div>
              <div style="margin-top:14px; display:flex; flex-direction:column; gap:13px;">
                <p v-if="!activity.length" style="font-size:12.5px; color:rgba(167,243,208,.6);">Nothing yet.</p>
                <div v-for="(a, i) in activity" :key="i" style="display:flex; gap:10px; align-items:flex-start;">
                  <span style="width:8px; height:8px; flex:0 0 8px; border-radius:999px; margin-top:5px; background:#22c55e;"></span>
                  <span style="min-width:0;">
                    <span style="display:block; font-size:12.5px; line-height:1.45;">{{ a.text }}</span>
                    <span style="display:block; margin-top:2px; font-size:11px; color:rgba(167,243,208,.6);">{{ timeAgo(a.at) }}</span>
                  </span>
                </div>
              </div>
            </div>
          </section>
        </template>

        <!-- ================= APPLICATIONS ================= -->
        <template v-else-if="activeNav === 'applications'">
          <section style="display:flex; flex-wrap:wrap; gap:20px; align-items:flex-start;">
            <div style="flex:1 1 640px; min-width:0;">
              <div style="display:flex; align-items:center; gap:8px; margin-bottom:12px; flex-wrap:wrap;">
                <div style="display:flex; gap:6px;">
                  <button
                    v-for="f in ['All', 'Pending', 'Approved', 'Declined']"
                    :key="f"
                    type="button"
                    @click="filter = f"
                    :style="`padding:6px 13px; border:none; border-radius:6px; font-size:12px; font-weight:500; cursor:pointer; transition:all .18s; background:${filter === f ? '#111827' : 'transparent'}; color:${filter === f ? '#fff' : '#4b5563'};`"
                  >{{ f }}</button>
                </div>
                <div style="flex:1;"></div>
                <button type="button" @click="exportCsv" style="padding:7px 13px; border:1px solid #e5e7eb; border-radius:6px; background:#fff; display:flex; align-items:center; gap:6px; font-size:12px; font-weight:500; color:#4b5563; cursor:pointer;">
                  <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                  Export
                </button>
              </div>

              <div style="background:#fff; border:1px solid #e5e7eb; border-radius:8px; overflow:hidden;">
                <div style="padding:14px 16px; border-bottom:1px solid #f3f4f6;">
                  <div style="font-size:11px; font-weight:600; letter-spacing:0.6px; text-transform:uppercase; color:#6b7280;">Applications</div>
                  <div style="margin-top:2px; font-size:12px; color:#9ca3af;">Select a row to review details and approve or decline.</div>
                </div>

                <div style="display:grid; grid-template-columns:1.7fr 1.1fr 0.9fr 0.9fr; gap:8px; padding:10px 16px; background:#f9fafb; font-size:11px; font-weight:700; letter-spacing:0.6px; text-transform:uppercase; color:#111827;">
                  <span>Municipality</span><span>Contact</span><span>Submitted</span><span>Status</span>
                </div>

                <div style="padding:8px; display:flex; flex-direction:column; gap:6px;">
                  <div
                    v-for="a in filteredApplications"
                    :key="a.id"
                    @click="selectedId = a.id"
                    :style="`display:grid; grid-template-columns:1.7fr 1.1fr 0.9fr 0.9fr; gap:8px; align-items:center; padding:10px 12px; border-radius:6px; cursor:pointer; transition:all .15s; background:${a.id === selectedId ? '#f0fdf4' : '#f9fafb'}; box-shadow:${a.id === selectedId ? 'inset 0 0 0 1px #86efac' : 'none'};`"
                  >
                    <div style="display:flex; align-items:center; gap:10px; min-width:0;">
                      <span style="width:28px; height:28px; flex:0 0 28px; border-radius:999px; background:linear-gradient(135deg, #22c55e, #15803d); color:#fff; display:grid; place-items:center; font-size:11px; font-weight:700;">{{ a.municipal_name[0] }}</span>
                      <span style="min-width:0;">
                        <span style="display:block; font-size:12px; font-weight:600; color:#111827; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">{{ a.municipal_name }}</span>
                        <span style="display:block; font-size:10px; color:#6b7280; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">{{ a.representative_name }}</span>
                      </span>
                    </div>
                    <span style="font-size:11.5px; color:#374151; min-width:0; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">{{ a.email }}</span>
                    <span style="font-size:11.5px; color:#374151;">{{ formatDate(a.submitted_at) }}</span>
                    <span style="display:inline-flex; align-items:center; gap:6px; font-size:11.5px; font-weight:500;" :style="{ color: STATUS_COLORS[a.status].text }">
                      <span :style="`width:8px; height:8px; border-radius:999px; background:${STATUS_COLORS[a.status].dot};`"></span>{{ cap(a.status) }}
                    </span>
                  </div>

                  <div v-if="!filteredApplications.length" style="padding:32px 16px; text-align:center; font-size:13px; color:#9ca3af;">No applications found.</div>
                </div>
              </div>
            </div>

            <div style="flex:1 1 340px; max-width:380px; min-width:300px; background:#fff; border:1px solid #e5e7eb; border-radius:8px; overflow:hidden;">
              <div style="padding:14px 16px; border-bottom:1px solid #e5e7eb; display:flex; align-items:center; gap:10px;">
                <span style="font-size:11px; font-weight:600; letter-spacing:0.6px; text-transform:uppercase; color:#6b7280;">Review</span>
                <span style="flex:1;"></span>
                <span v-if="selected" style="display:inline-flex; align-items:center; gap:6px; font-size:11.5px; font-weight:500;" :style="{ color: STATUS_COLORS[selected.status].text }">
                  <span :style="`width:8px; height:8px; border-radius:999px; background:${STATUS_COLORS[selected.status].dot};`"></span>{{ cap(selected.status) }}
                </span>
              </div>

              <template v-if="selected">
                <div style="padding:18px 20px 0; display:flex; flex-direction:column; gap:16px;">
                  <div style="display:flex; align-items:center; gap:12px;">
                    <span style="width:40px; height:40px; flex:0 0 40px; border-radius:999px; background:linear-gradient(135deg, #22c55e, #15803d); color:#fff; display:grid; place-items:center; font-size:14px; font-weight:700;">{{ selected.municipal_name[0] }}</span>
                    <span style="min-width:0;">
                      <span style="display:block; font-size:15px; font-weight:700; color:#111827; letter-spacing:-0.2px;">{{ selected.municipal_name }}</span>
                      <span style="display:block; font-size:12px; color:#6b7280;">{{ selected.representative_name }}</span>
                    </span>
                  </div>

                  <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
                    <div>
                      <div style="font-size:11px; font-weight:600; letter-spacing:0.6px; text-transform:uppercase; color:#6b7280;">Email</div>
                      <div style="font-size:12.5px; font-weight:500; color:#111827; overflow-wrap:anywhere;">{{ selected.email }}</div>
                    </div>
                    <div>
                      <div style="font-size:11px; font-weight:600; letter-spacing:0.6px; text-transform:uppercase; color:#6b7280;">Phone</div>
                      <div style="font-size:12.5px; font-weight:500; color:#111827;">{{ selected.phone }}</div>
                    </div>
                    <div>
                      <div style="font-size:11px; font-weight:600; letter-spacing:0.6px; text-transform:uppercase; color:#6b7280;">Submitted</div>
                      <div style="font-size:12.5px; font-weight:500; color:#111827;">{{ formatDateTime(selected.submitted_at) }}</div>
                    </div>
                    <div>
                      <div style="font-size:11px; font-weight:600; letter-spacing:0.6px; text-transform:uppercase; color:#6b7280;">Reviewed</div>
                      <div style="font-size:12.5px; font-weight:500; color:#111827;">{{ selected.reviewed_at ? formatDateTime(selected.reviewed_at) + " by " + selected.reviewed_by : "—" }}</div>
                    </div>
                  </div>
                </div>

                <div v-if="selected.status === 'pending'" style="margin-top:18px; padding:14px 16px; border-top:1px solid #e5e7eb; display:flex; gap:8px;">
                  <button type="button" @click="decline(selected)" style="flex:1; padding:10px; border:1px solid #fecaca; border-radius:6px; background:#fff; color:#b91c1c; font-size:13px; font-weight:500; cursor:pointer;">Decline</button>
                  <button type="button" @click="approve(selected)" style="flex:1; padding:10px; border:none; border-radius:6px; background:#15803d; color:#fff; font-size:13px; font-weight:600; cursor:pointer;">Approve</button>
                </div>
                <div v-else style="height:14px;"></div>
              </template>

              <div v-else style="padding:34px 24px; text-align:center; display:flex; flex-direction:column; align-items:center; gap:8px;">
                <span style="width:38px; height:38px; border-radius:999px; background:#f3f4f6; display:grid; place-items:center; color:#9ca3af;">
                  <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="8" y="2" width="8" height="4" rx="1"></rect><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"></path><path d="M9 13h6M9 17h4"></path></svg>
                </span>
                <div style="font-size:13px; font-weight:600;">No application selected</div>
                <div style="font-size:12px; color:#9ca3af; max-width:220px;">Select a row from the applications table to review it.</div>
              </div>
            </div>
          </section>
        </template>

        <!-- ================= INVITATIONS ================= -->
        <template v-else-if="activeNav === 'invitations'">
          <section style="display:flex; flex-wrap:wrap; gap:20px; align-items:flex-start;">
            <div style="flex:1 1 640px; min-width:0; background:#fff; border:1px solid #e5e7eb; border-radius:8px; overflow:hidden;">
              <div style="padding:14px 16px; border-bottom:1px solid #f3f4f6;">
                <div style="font-size:11px; font-weight:600; letter-spacing:0.6px; text-transform:uppercase; color:#6b7280;">Invitation Links</div>
                <div style="margin-top:2px; font-size:12px; color:#9ca3af;">Each municipality keeps its own invite. Generating a new one does not invalidate other municipalities' links.</div>
              </div>
              <div style="padding:8px; display:flex; flex-direction:column; gap:6px;">
                <p v-if="!filteredInvitations.length" style="padding:16px; text-align:center; font-size:13px; color:#9ca3af;">{{ invitations.length ? "No invitations match your search." : "No invitations generated yet." }}</p>
                <div v-for="inv in filteredInvitations" :key="inv.id" style="display:flex; align-items:center; gap:12px; padding:10px 12px; border-radius:6px; background:#f9fafb; flex-wrap:wrap;">
                  <span style="width:130px; flex:0 0 130px; font-size:12px; font-weight:600; color:#111827;">{{ inv.municipal_name }}</span>
                  <code style="flex:1 1 220px; min-width:0; font-family:ui-monospace, SFMono-Regular, Menlo, monospace; font-size:11px; color:#4b5563; background:#fff; border:1px solid #e5e7eb; border-radius:6px; padding:6px 9px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">{{ inv.link }}</code>
                  <span :style="`flex:0 0 auto; font-size:11px; font-weight:500; color:${invitationIsWarn(inv) ? '#b45309' : '#9ca3af'};`">{{ invitationRightLabel(inv) }}</span>
                  <button
                    type="button"
                    @click="copyLink(inv.link)"
                    :style="`flex:0 0 auto; padding:6px 13px; border:1px solid ${copiedLink === inv.link ? '#86efac' : '#e5e7eb'}; border-radius:6px; background:${copiedLink === inv.link ? '#f0fdf4' : '#fff'}; color:${copiedLink === inv.link ? '#15803d' : '#374151'}; font-size:11.5px; font-weight:500; cursor:pointer;`"
                  >{{ copiedLink === inv.link ? "Copied" : "Copy" }}</button>
                  <button
                    v-if="inv.status === 'active'"
                    type="button"
                    @click="deactivateLink(inv)"
                    style="flex:0 0 auto; padding:6px 13px; border:1px solid #fecaca; border-radius:6px; background:#fff; color:#b91c1c; font-size:11.5px; font-weight:500; cursor:pointer;"
                  >Deactivate</button>
                </div>
              </div>
            </div>

            <div style="flex:1 1 340px; max-width:380px; min-width:300px; background:#fff; border:1px solid #e5e7eb; border-radius:8px; padding:18px 20px 20px;">
              <div style="font-size:11px; font-weight:600; letter-spacing:0.6px; text-transform:uppercase; color:#6b7280;">Generate Invitation</div>
              <label style="display:block; margin:14px 0 6px; font-size:12px; font-weight:600;">Municipality</label>
              <select v-model="selectedMunicipal" style="width:100%; padding:10px 12px; border:1px solid #e5e7eb; border-radius:8px; font-size:13px; background:#fff; color:#111827; font-family:inherit;">
                <option value="">Select a municipality</option>
                <option v-for="m in sortedMunicipalities" :key="m.slug" :value="m.slug">{{ m.name }}{{ m.already_registered ? " (already registered)" : "" }}</option>
              </select>

              <button
                type="button"
                @click="generateLink"
                :disabled="!selectedMunicipal || busy === 'generate'"
                :style="`margin-top:16px; width:100%; padding:11px; border:none; border-radius:6px; display:flex; align-items:center; justify-content:center; gap:8px; font-size:13px; font-weight:600; cursor:${selectedMunicipal ? 'pointer' : 'not-allowed'}; background:${selectedMunicipal ? '#15803d' : '#cbd5c9'}; color:#fff;`"
              >
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"></path><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"></path></svg>
                {{ busy === "generate" ? "Working…" : "Generate link" }}
              </button>
              <div style="margin-top:10px; font-size:11.5px; color:#9ca3af; display:flex; gap:6px; align-items:flex-start;">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex:0 0 13px; margin-top:1px;"><circle cx="12" cy="12" r="10"></circle><path d="M12 16v-4M12 8h.01"></path></svg>
                <span>{{ selectedMunicipal ? "Link is valid for 24 hours and can be used once." : "Select a municipality to generate an invitation link." }}</span>
              </div>
            </div>
          </section>
        </template>

        <!-- ================= 360° SCENES ================= -->
        <template v-else-if="activeNav === 'scenes'">
          <section style="background:#fff; border:1px solid #e5e7eb; border-radius:8px; padding:18px 20px;">
            <div style="font-size:11px; font-weight:600; letter-spacing:0.6px; text-transform:uppercase; color:#6b7280; margin-bottom:10px;">360° Scenes</div>
            <select v-model="scenesMunicipal" @change="loadScenes" style="width:280px; max-width:100%; padding:10px 12px; border:1px solid #e5e7eb; border-radius:8px; font-size:13px; background:#fff; color:#111827; font-family:inherit;">
              <option value="">Select a municipality</option>
              <option v-for="m in sortedMunicipalities" :key="m.slug" :value="m.slug">{{ m.name }}</option>
            </select>
          </section>

          <section v-if="!scenesMunicipal" style="background:#fff; border:1px solid #e5e7eb; border-radius:8px; padding:44px 24px; text-align:center;">
            <div style="font-size:13px; font-weight:600; color:#111827;">Select a municipality to view its scenes</div>
            <div style="margin-top:4px; font-size:12px; color:#9ca3af;">Nothing loads until one is picked.</div>
          </section>

          <section v-else style="background:#fff; border:1px solid #e5e7eb; border-radius:8px; padding:18px 20px;">
            <div v-if="scenesLoading" style="text-align:center; padding:24px; font-size:13px; color:#9ca3af;">Loading…</div>
            <div v-else-if="!scenesList.length" style="text-align:center; padding:24px; font-size:13px; color:#9ca3af;">No scenes found for this municipality.</div>
            <div v-else style="display:grid; grid-template-columns:repeat(auto-fill, minmax(180px, 1fr)); gap:14px;">
              <div v-for="group in groupedScenes" :key="group.title" style="border:1px solid #e5e7eb; border-radius:8px; overflow:hidden; position:relative;">
                <img :src="getImageUrl(getThumbnail(group.cover.panorama_path))" :alt="group.title" style="width:100%; height:110px; object-fit:cover; display:block; background:#f3f4f6;" />
                <span v-if="group.items.length > 1" style="position:absolute; top:8px; right:8px; font-size:10px; font-weight:700; padding:2px 8px; border-radius:999px; background:rgba(5,46,26,.75); color:#fff;">×{{ group.items.length }}</span>
                <div style="padding:8px 10px;">
                  <div style="font-size:12px; font-weight:600; color:#111827; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">{{ group.title }}</div>
                  <div style="display:flex; align-items:center; justify-content:space-between; margin-top:6px; gap:6px;">
                    <span :style="`font-size:10px; font-weight:600; padding:2px 8px; border-radius:999px; background:${group.cover.is_published ? '#f0fdf4' : '#f3f4f6'}; color:${group.cover.is_published ? '#15803d' : '#6b7280'};`">{{ group.items.length > 1 ? group.items.length + " scenes" : (group.cover.is_published ? "Published" : "Draft") }}</span>
                    <button v-if="group.items.length > 1" type="button" @click="openGroup = group" style="font-size:11px; font-weight:600; padding:4px 10px; border:1px solid #e5e7eb; border-radius:6px; background:#fff; color:#15803d; cursor:pointer;">View</button>
                  </div>
                </div>
              </div>
            </div>
          </section>
        </template>
      </div>
    </main>

    <!-- GROUPED SCENES MODAL -->
    <div v-if="openGroup" @click.self="openGroup = null" style="position:fixed; inset:0; background:rgba(15,23,42,.5); display:flex; align-items:center; justify-content:center; z-index:1000; padding:24px;">
      <div style="background:#fff; border-radius:12px; padding:24px; width:100%; max-width:620px; max-height:80vh; overflow-y:auto; position:relative;">
        <button @click="openGroup = null" style="position:absolute; top:16px; right:16px; border:none; background:none; font-size:18px; cursor:pointer; color:#9ca3af;">✕</button>
        <div style="font-size:15px; font-weight:700; color:#111827; margin-bottom:14px;">{{ openGroup.title }} <span style="font-weight:500; color:#9ca3af; font-size:13px;">({{ openGroup.items.length }} scenes)</span></div>
        <div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(160px, 1fr)); gap:12px;">
          <div v-for="scene in openGroup.items" :key="scene.id" style="border:1px solid #e5e7eb; border-radius:8px; overflow:hidden;">
            <img :src="getImageUrl(getThumbnail(scene.panorama_path))" :alt="scene.title" style="width:100%; height:100px; object-fit:cover; display:block; background:#f3f4f6;" />
            <div style="padding:6px 8px;">
              <span :style="`font-size:10px; font-weight:600; padding:2px 8px; border-radius:999px; background:${scene.is_published ? '#f0fdf4' : '#f3f4f6'}; color:${scene.is_published ? '#15803d' : '#6b7280'};`">{{ scene.is_published ? "Published" : "Draft" }}</span>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

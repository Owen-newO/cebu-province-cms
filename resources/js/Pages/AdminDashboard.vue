<script setup>
import { ref, computed, onMounted } from "vue";
import { Head, router } from "@inertiajs/vue3";
import addSceneModal from "./addSceneModal.vue";

const props = defineProps({
  municipalities: Object, // { slug: "Display Name" } — 44 municipalities
  barangays: Object,      // { slug: ["Barangay ...", ...] }
});

// ------------------------------------------------------------------
// State
// ------------------------------------------------------------------
const selectedMunicipal = ref(""); // slug, "" = none selected
const activeTab = ref("scene");    // "scene" | "drafts"
const scenes = ref([]);            // grouped published
const drafts = ref([]);            // grouped drafts
const allPublishedScenes = ref([]);
const activeGroupTitle = ref(null);
const activeGroupCount = ref(0);
const searchQuery = ref("");
const loading = ref(false);
const busy = ref("");
// One-time bulk actions (Inject to Cebu, Fix HTGT). Set true to show them again.
const showBulkTools = ref(false);
const sceneModal = ref(null);
const imageFailed = ref({});
const imageCheckIntervals = {};

const municipalDisplay = computed(
  () => props.municipalities?.[selectedMunicipal.value] || ""
);
const selectedBarangays = computed(
  () => props.barangays?.[selectedMunicipal.value] || []
);

const municipalOptions = computed(() =>
  Object.entries(props.municipalities || {})
    .map(([slug, name]) => ({ slug, name }))
    .sort((a, b) => a.name.localeCompare(b.name))
);

const filteredScenes = computed(() => {
  const q = (searchQuery.value || "").trim().toLowerCase();
  if (!q) return scenes.value;
  return scenes.value.filter((s) =>
    String(s.title || "").toLowerCase().includes(q)
  );
});

// ------------------------------------------------------------------
// Helpers (mirrors Dashboard.vue)
// ------------------------------------------------------------------
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

const checkThumbnailReady = (sceneId, src) => {
  if (imageCheckIntervals[sceneId]) return;
  imageCheckIntervals[sceneId] = setInterval(() => {
    const img = new Image();
    img.src = src + "?t=" + Date.now();
    img.onload = () => {
      imageFailed.value[sceneId] = false;
      clearInterval(imageCheckIntervals[sceneId]);
      delete imageCheckIntervals[sceneId];
    };
  }, 4000);
};

const groupByTitle = (list) => {
  const grouped = {};
  list.forEach((scene) => {
    const title = scene.title?.trim() || "Untitled";
    if (!grouped[title]) grouped[title] = { ...scene, count: 1 };
    else grouped[title].count++;
  });
  return Object.values(grouped).map((scene) => ({
    ...scene,
    img: getImageUrl(scene.panorama_path),
    date: new Date(scene.created_at).toLocaleDateString(),
  }));
};

// ------------------------------------------------------------------
// CSRF-aware fetch (keeps admin on the page — no Inertia navigation)
// ------------------------------------------------------------------
const xsrf = () => {
  const m = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
  return m ? decodeURIComponent(m[1]) : "";
};
const send = (method, url, body, timeoutMs = 180000) => {
  // Abort after timeoutMs so a stalled request can never leave the UI stuck.
  const ctrl = new AbortController();
  const t = setTimeout(() => ctrl.abort(), timeoutMs);
  return fetch(url, {
    method,
    headers: {
      "Content-Type": "application/json",
      Accept: "application/json",
      "X-Requested-With": "XMLHttpRequest",
      "X-XSRF-TOKEN": xsrf(),
    },
    credentials: "same-origin",
    body: body ? JSON.stringify(body) : undefined,
    signal: ctrl.signal,
  }).finally(() => clearTimeout(t));
};

// ------------------------------------------------------------------
// Load a municipality's scenes
// ------------------------------------------------------------------
const refresh = async () => {
  if (!selectedMunicipal.value) {
    scenes.value = [];
    drafts.value = [];
    allPublishedScenes.value = [];
    return;
  }
  loading.value = true;
  try {
    const res = await fetch(`/api/scenes/${selectedMunicipal.value}`);
    if (!res.ok) return;
    const data = await res.json();
    const mapped = data.map((s) => ({
      ...s,
      img: getImageUrl(s.panorama_path),
      date: new Date(s.created_at).toLocaleDateString(),
    }));
    allPublishedScenes.value = mapped.filter((s) => Number(s.is_published) === 1);
    drafts.value = groupByTitle(mapped.filter((s) => Number(s.is_published) !== 1));

    if (activeGroupTitle.value) {
      const f = allPublishedScenes.value.filter(
        (s) => (s.title || "").trim() === activeGroupTitle.value.trim()
      );
      activeGroupCount.value = f.length;
      scenes.value = f;
    } else {
      scenes.value = groupByTitle(allPublishedScenes.value);
    }
  } finally {
    loading.value = false;
  }
};

const selectMunicipal = async (slug) => {
  selectedMunicipal.value = slug;
  activeGroupTitle.value = null;
  activeGroupCount.value = 0;
  searchQuery.value = "";
  // keep the selection in the URL so the addSceneModal's page reload restores it
  const url = new URL(window.location.href);
  if (slug) url.searchParams.set("m", slug);
  else url.searchParams.delete("m");
  window.history.replaceState({}, "", url);
  await refresh();
};

// ------------------------------------------------------------------
// Group filter
// ------------------------------------------------------------------
const filterGroup = (title) => {
  const f = allPublishedScenes.value.filter(
    (s) => (s.title || "").trim() === title.trim()
  );
  activeGroupTitle.value = title;
  activeGroupCount.value = f.length;
  scenes.value = f;
};
const clearGroupFilter = () => {
  activeGroupTitle.value = null;
  activeGroupCount.value = 0;
  scenes.value = groupByTitle(allPublishedScenes.value);
};

// ------------------------------------------------------------------
// Scene actions
// ------------------------------------------------------------------
const handleView = (scene) => {
  if (!scene.panorama_path) return;
  const url = scene.panorama_path
    .replace("https://s3.ap-southeast-1.amazonaws.com/mata.ph/", "https://www.mata.ph/")
    .replace(/\/[^/]+$/, "/tour.html");
  window.open(url, "_blank");
};

const deleteScene = async (id) => {
  if (!confirm("Delete this scene?")) return;
  await send("DELETE", route("scenes.destroy", id));
  await refresh();
};

const publishScene = async (id) => {
  await send("POST", route("scenes.publish", id));
  await refresh();
};

// ------------------------------------------------------------------
// Admin maintenance actions
// ------------------------------------------------------------------
const runAction = async (url, { municipal = false, label, confirmMsg }) => {
  if (municipal && !selectedMunicipal.value) {
    alert("Select a municipality first.");
    return;
  }
  if (!window.confirm(confirmMsg || `Run "${label}"?`)) return;
  busy.value = label;
  try {
    const body = municipal ? { municipal: selectedMunicipal.value } : {};
    const res = await send("POST", url, body);
    // Show the server's real message (with the count) when it returns JSON.
    let msg = res.ok ? `${label}: done.` : `${label}: failed. Check logs.`;
    try {
      const data = await res.clone().json();
      if (data && data.message) msg = data.message;
    } catch (_) { /* not JSON (redirect/HTML) — keep the fallback */ }
    alert(msg);
    if (municipal) await refresh();
  } catch (e) {
    alert(`${label}: failed.`);
  } finally {
    busy.value = "";
  }
};

// ------------------------------------------------------------------
// Init
// ------------------------------------------------------------------
onMounted(() => {
  const m = new URL(window.location.href).searchParams.get("m");
  if (m && props.municipalities?.[m]) selectMunicipal(m);
});

const logout = () => router.post(route("logout"));
</script>

<template>
  <Head title="MATA Admin Dashboard" />
  <div style="display:flex; height:100vh; background:#f5f6fa; font-family:'Inter', sans-serif; color:#222;">
    <!-- Sidebar -->
    <aside style="width:280px; background-color:#0f172a; color:white; display:flex; flex-direction:column; padding:32px 20px;">
      <div style="display:flex; align-items:center; gap:12px;">
        <div style="width:56px; height:56px; border-radius:50%; background:#1e293b; display:flex; justify-content:center; align-items:center; font-size:20px; font-weight:bold;">M</div>
        <div>
          <p style="font-size:16px; font-weight:700; line-height:1.1;">MATA ADMIN</p>
          <p style="font-size:12px; color:#94a3b8;">Province of Cebu</p>
        </div>
      </div>

      <!-- Municipality selector -->
      <label style="margin-top:36px; font-size:13px; color:#94a3b8; letter-spacing:.04em;">MUNICIPAL</label>
      <select
        :value="selectedMunicipal"
        @change="selectMunicipal($event.target.value)"
        style="margin-top:8px; width:100%; padding:12px; border-radius:10px; border:1px solid #334155; background:#1e293b; color:#fff; font-size:15px;"
      >
        <option value="">— Select municipality —</option>
        <option v-for="m in municipalOptions" :key="m.slug" :value="m.slug">{{ m.name }}</option>
      </select>

      <nav style="width:100%; margin-top:36px; display:flex; flex-direction:column; gap:10px;">
        <button
          @click="activeTab='scene'"
          :style="`text-align:left; padding:12px 16px; border:none; border-radius:10px; cursor:pointer; font-size:15px; ${activeTab==='scene' ? 'background:#2563eb; color:#fff;' : 'background:transparent; color:#cbd5e1;'}`"
        >Dashboard</button>
        <button
          @click="activeTab='drafts'"
          :style="`text-align:left; padding:12px 16px; border:none; border-radius:10px; cursor:pointer; font-size:15px; ${activeTab==='drafts' ? 'background:#2563eb; color:#fff;' : 'background:transparent; color:#cbd5e1;'}`"
        >Save Draft</button>
      </nav>

      <button
        @click="logout"
        style="margin-top:auto; padding:10px 16px; border-radius:10px; border:1px solid #334155; background:transparent; color:#fff; cursor:pointer; font-size:15px;"
      >Logout</button>
    </aside>

    <!-- Main -->
    <main style="flex:1; display:flex; flex-direction:column; overflow-y:auto;">
      <!-- Header -->
      <header style="background:white; padding:20px 40px; display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid #e5e7eb; flex-wrap:wrap; gap:12px;">
        <h1 style="font-size:25px; font-weight:700;">MATA ADMIN DASHBOARD</h1>
        <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
          <button @click.prevent="runAction(route('scenes.fixLayerNames'), { municipal:true, label:'Fix Layer Names', confirmMsg:`Fix child layer names in ${municipalDisplay || 'the selected municipality'} only?` })" :disabled="busy === 'Fix Layer Names'" style="font-size:14px; padding:8px 16px; border-radius:20px; border:1px solid #d1d5db; background:#0f172a; color:#fff; cursor:pointer;">{{ busy === 'Fix Layer Names' ? 'Working…' : '🏷️ Fix Layer Names' }}</button>
          <button @click.prevent="runAction(route('scenes.hlookat180'), { municipal:true, label:'hlookat 180', confirmMsg:`Set ALL scene views in ${municipalDisplay} to hlookat 180?` })" :disabled="busy === 'hlookat 180'" style="font-size:14px; padding:8px 16px; border-radius:20px; border:1px solid #d1d5db; background:#1d4ed8; color:#fff; cursor:pointer;">{{ busy === 'hlookat 180' ? 'Working…' : '🔭 hlookat 180' }}</button>
          <button @click.prevent="runAction(route('scenes.hlookat0'), { municipal:true, label:'hlookat 0', confirmMsg:`Set ALL scene views in ${municipalDisplay} to hlookat 0?` })" :disabled="busy === 'hlookat 0'" style="font-size:14px; padding:8px 16px; border-radius:20px; border:1px solid #d1d5db; background:#475569; color:#fff; cursor:pointer;">{{ busy === 'hlookat 0' ? 'Working…' : '🔭 hlookat 0' }}</button>
          <button @click.prevent="runAction(route('scenes.fixTopni'), { label:'Fixed Topni', confirmMsg:'Rewrite the topni layer in ALL tour.xml files (every municipality + province)? This affects the whole province.' })" :disabled="busy === 'Fixed Topni'" style="font-size:14px; padding:8px 16px; border-radius:20px; border:1px solid #d1d5db; background:#b45309; color:#fff; cursor:pointer;">{{ busy === 'Fixed Topni' ? 'Working…' : '🧱 Fixed Topni' }}</button>
          <button @click.prevent="runAction(route('scenes.layPrefix'), { label:'Add lay_ to Thumbs', confirmMsg:'Add the lay_ prefix to thumbnails in ALL municipal tour.xml files? (cebu/tour.xml is handled by Inject to Cebu.)' })" :disabled="busy === 'Add lay_ to Thumbs'" style="font-size:14px; padding:8px 16px; border-radius:20px; border:1px solid #d1d5db; background:#7c3aed; color:#fff; cursor:pointer;">{{ busy === 'Add lay_ to Thumbs' ? 'Working…' : '🏷️ Add lay_' }}</button>
          <template v-if="showBulkTools">
          <button @click.prevent="runAction(route('scenes.injectCebu'), { label:'Inject to Cebu Tour', confirmMsg:'Rebuild the province cebu/tour.xml thumbnail rail from ALL published scenes across every municipality?' })" :disabled="busy === 'Inject to Cebu Tour'" style="font-size:14px; padding:8px 16px; border-radius:20px; border:1px solid #d1d5db; background:#047857; color:#fff; cursor:pointer;">{{ busy === 'Inject to Cebu Tour' ? 'Working…' : '🏙️ Inject to Cebu' }}</button>
          <button @click.prevent="runAction(route('scenes.fixModalHtgt'), { label:'Fix HTGT Button', confirmMsg:'Update the How-to-get-there button in ALL modal.xml files so it stays visible-but-disabled on mobile (instead of hidden) when a scene has no directions?' })" :disabled="busy === 'Fix HTGT Button'" style="font-size:14px; padding:8px 16px; border-radius:20px; border:1px solid #d1d5db; background:#0891b2; color:#fff; cursor:pointer;">{{ busy === 'Fix HTGT Button' ? 'Working…' : '🧭 Fix HTGT Button' }}</button>
          </template>
        </div>
      </header>

      <!-- Empty state: no municipality selected -->
      <section v-if="!selectedMunicipal" style="flex:1; display:flex; align-items:center; justify-content:center; text-align:center; color:#6b7280;">
        <div>
          <div style="font-size:60px; margin-bottom:12px;">🗺️</div>
          <p style="font-size:20px; font-weight:600; color:#374151;">Select a municipality to begin</p>
          <p style="font-size:15px; margin-top:6px;">Use the <b>MUNICIPAL</b> dropdown on the left.</p>
        </div>
      </section>

      <!-- SCENES -->
      <section v-else-if="activeTab==='scene'" style="flex:1; overflow-y:auto; padding-bottom:40px;">
        <div style="margin:20px 40px; display:flex; align-items:center; gap:16px; flex-wrap:wrap;">
          <h2 style="font-size:20px; font-weight:600;">{{ municipalDisplay }} 360° Scenes</h2>
          <input v-model="searchQuery" placeholder="Search scenes…" style="flex:1; min-width:220px; max-width:420px; padding:10px 14px; border-radius:10px; border:1px solid #d1d5db; background:#fff; font-size:15px;" />
          <button
            @click="sceneModal && sceneModal.openModal()"
            style="margin-left:auto; background:#2563eb; color:white; padding:10px 20px; border-radius:8px; border:none; cursor:pointer; font-size:16px;"
          >+ Add Scene</button>
        </div>

        <!-- Group filter header -->
        <div v-if="activeGroupTitle" style="position:relative; margin:0 40px 10px; display:flex; justify-content:center; align-items:center; min-height:48px;">
          <button @click="clearGroupFilter" style="position:absolute; left:0; top:50%; transform:translateY(-50%); background:#101828; color:white; padding:8px 16px; border:none; border-radius:8px; cursor:pointer; font-size:16px;">← All Scenes</button>
          <div style="text-align:center;">
            <p style="font-weight:600; font-size:20px;">{{ activeGroupTitle }} Scenes</p>
            <p style="color:#101828; font-size:15px;">{{ activeGroupCount }} Scenes</p>
          </div>
        </div>

        <p v-if="loading" style="margin:0 40px; color:#6b7280;">Loading…</p>
        <p v-else-if="!filteredScenes.length" style="margin:0 40px; color:#6b7280;">No published scenes for {{ municipalDisplay }}.</p>

        <!-- Scene Cards — same layout/onclick as the municipal dashboard -->
        <div style="padding:30px 40px; display:flex; flex-flow:row wrap; gap:30px; width:100%; justify-content:left; max-width:1600px; margin:0 auto;">
          <div
            v-for="scene in filteredScenes"
            :key="scene.id"
            style="background:#fff; border-radius:16px; box-shadow:0 2px 8px rgba(0,0,0,0.1); padding:16px; width:32%; min-width:450px; flex-direction:column; justify-content:space-between;"
          >
            <div style="position:relative;">
              <div style="position:relative; width:100%; height:180px; border-radius:12px; overflow:hidden; margin-bottom:12px;">
                <img
                  v-if="!imageFailed[scene.id]"
                  :src="getImageUrl(getThumbnail(scene.panorama_path || scene.img))"
                  loading="lazy" alt=""
                  style="width:100%; height:100%; object-fit:cover;"
                  @error="imageFailed[scene.id]=true; checkThumbnailReady(scene.id, getImageUrl(getThumbnail(scene.panorama_path || scene.img)))"
                />
                <div v-else style="width:100%; height:100%; background:#f3f4f6; display:flex; align-items:center; justify-content:center; font-size:16px; font-weight:600; color:#6b7280;">⏳ Generating panorama…</div>
              </div>
              <div v-if="scene.count > 1" style="position:absolute; top:10px; right:10px; background:#facc15; color:#000; font-weight:600; font-size:13px; border-radius:20px; padding:4px 10px; display:flex; align-items:center; justify-content:center; box-shadow:0 1px 4px rgba(0,0,0,0.25);">{{ scene.count }} Scenes</div>
            </div>

            <div style="display:flex; justify-content:space-between; align-items:center;">
              <h2 style="font-size:18px; font-weight:600; color:#000;">{{ scene.title }}</h2>
              <span style="background:#f9fafb; border-radius:20px; font-size:12px; padding:4px 12px; color:#111827; border:1px solid #e5e7eb;">{{ scene.date }}</span>
            </div>
            <div style="display:flex; justify-content:space-between; align-items:center;">
              <h2 style="font-size:14px; color:#000;margin-right: 50px;font-style: italic;">{{ scene.location }}</h2>
            </div>

            <div style="display:flex; align-items:center; gap:16px; margin-top:6px; color:#6b7280; font-size:14px;padding-bottom: 15px;">
              <div style="display:flex; align-items:center; gap:6px;">
                <img src="/images/barangay_pin.png" style="width:16px; height:18px;" />
                <span>Brgy. {{ scene.barangay }}</span>
              </div>
              <div style="display:flex; align-items:center; gap:6px;">
                <img src="/images/barangay_tag.png" style="width:16px; height:18px;" />
                <span>{{ scene.category }}</span>
              </div>
            </div>

            <div style="display:flex; justify-content:center; align-items:center; gap:10px; margin:14px 50px 0;">
              <!-- Group (multiple scenes) -->
              <template v-if="scene.count > 1">
                <button
                  @click="filterGroup(scene.title)"
                  style="flex:1; display:flex; align-items:center; margin-left: 125px; margin-right: 125px; justify-content:center; gap:6px; background:none; border:1px solid #d1d5db; border-radius:10px; padding:8px 0; font-size:15px; cursor:pointer;"
                >
                  <img src="/images/show_eye.png" style="width:20px; height:20px;" /> View
                </button>
              </template>

              <!-- Single scene -->
              <template v-else>
                <button
                  @click="sceneModal && sceneModal.openForEdit(scene)"
                  style="flex:1; display:flex; align-items:center; justify-content:center; gap:6px; background:none; border:1px solid #d1d5db; border-radius:10px; padding:8px 0; font-size:15px; cursor:pointer;"
                >
                  <img src="/images/edit_pen.png" style="width:20px; height:18px;" /> Edit
                </button>
                <button
                  @click="handleView(scene)"
                  style="flex:1; display:flex; align-items:center; justify-content:center; gap:6px; background:#2383E2; color:#fff; border:1px solid #d1d5db; border-radius:10px; padding:8px 0; font-size:15px; cursor:pointer;"
                >
                  <img src="/images/show_eye.png" style="width:20px; height:20px;" /> View
                </button>
                <button
                  @click="deleteScene(scene.id)"
                  style="flex:1; display:flex; align-items:center; justify-content:center; gap:6px; background:#e5094a; color:#fff; border:none; border-radius:10px; padding:8px 0; font-size:15px; cursor:pointer;"
                >
                  <img src="/images/delete_trash.png" style="width:15px; height:15px;" /> Delete
                </button>
              </template>
            </div>
          </div>
        </div>
      </section>

      <!-- DRAFTS -->
      <section v-else style="flex:1; overflow-y:auto; padding-bottom:40px;">
        <h2 style="margin:20px 40px; font-size:20px; font-weight:600;">{{ municipalDisplay }} — Draft Scenes</h2>
        <p v-if="!drafts.length" style="margin:0 40px; color:#6b7280;">No drafts for {{ municipalDisplay }}.</p>
        <div style="padding:20px 40px; display:flex; flex-flow:row wrap; gap:24px;">
          <div v-for="scene in drafts" :key="scene.id" style="background:#fff; border-radius:16px; box-shadow:0 2px 8px rgba(0,0,0,0.1); padding:16px; width:32%; min-width:420px;">
            <div style="width:100%; height:180px; border-radius:12px; overflow:hidden; margin-bottom:12px; background:#f3f4f6;">
              <img :src="getImageUrl(getThumbnail(scene.panorama_path || scene.img))" alt="" style="width:100%; height:100%; object-fit:cover;" />
            </div>
            <h2 style="font-size:18px; font-weight:600;">{{ scene.title }}</h2>
            <p style="color:#6b7280; font-size:13px; margin:6px 0 12px;">Brgy. {{ scene.barangay || "—" }}</p>
            <div style="display:flex; gap:8px;">
              <button @click="sceneModal && sceneModal.openForEdit(scene)" style="flex:1; padding:8px; border:1px solid #d1d5db; background:#fff; border-radius:8px; cursor:pointer; font-size:14px;">Edit</button>
              <button @click="publishScene(scene.id)" style="flex:1; padding:8px; border:none; background:#16a34a; color:#fff; border-radius:8px; cursor:pointer; font-size:14px;">Publish</button>
              <button @click="deleteScene(scene.id)" style="flex:1; padding:8px; border:none; background:#dc2626; color:#fff; border-radius:8px; cursor:pointer; font-size:14px;">Delete</button>
            </div>
          </div>
        </div>
      </section>

      <!-- Single always-mounted Add/Edit modal (trigger hidden; parent controls
           it via sceneModal.openModal() / openForEdit()). Re-keyed per
           municipality so its data + submitted municipal stay correct. -->
      <addSceneModal
        v-if="selectedMunicipal"
        :key="selectedMunicipal"
        ref="sceneModal"
        :municipal="selectedMunicipal"
        :barangays="selectedBarangays"
        :show-trigger="false"
      />
    </main>
  </div>
</template>

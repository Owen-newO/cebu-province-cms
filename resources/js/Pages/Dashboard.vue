<script setup>
import { ref, onMounted } from "vue";
import { Head } from "@inertiajs/vue3";
import { router } from "@inertiajs/vue3";
import addSceneModal from "./addSceneModal.vue";
import { computed } from "vue";

const props = defineProps({
  scenes: Array,
  drafts: Array,
  barangays: Array,
  municipal: String,
});
const activeBarangay = ref(null);
const activeCategory = ref(null);
const showBarangayDropdown = ref(false);
const showCategoryDropdown = ref(false);
const scenes = ref([]);
const drafts = ref([]);
const allPublishedScenes = ref([]);
const activeTab = ref("scene");
const activeGroupTitle = ref(null);
const activeGroupCount = ref(0);
const showModal = ref(true);

const sceneModal = ref(null);
const imageFailed = ref({});
const imageCheckIntervals = {};

const checkThumbnailReady = (sceneId, src) => {
  if (imageCheckIntervals[sceneId]) return;

  imageCheckIntervals[sceneId] = setInterval(() => {
    const img = new Image();
    img.src = src + "?t=" + Date.now(); // bust cache

    img.onload = () => {
      imageFailed.value[sceneId] = false;
      clearInterval(imageCheckIntervals[sceneId]);
      delete imageCheckIntervals[sceneId];
    };
  }, 4000); // check every 4s
};


const filteredScenes = computed(() => {
  return scenes.value.filter((scene) => {
    const barangayMatch = activeBarangay.value
      ? scene.barangay === activeBarangay.value
      : true;
    const categoryMatch = activeCategory.value
      ? scene.category === activeCategory.value
      : true;
    return barangayMatch && categoryMatch;
  });
});

// ✅ Helper to normalize image URLs (S3 or local)
const getImageUrl = (path) => {
  if (!path) return "/images/sample1.jpg";
  // If already full URL (S3), return as-is
  if (path.startsWith("http://") || path.startsWith("https://")) {
    return path;
  }
  // Otherwise treat as relative path
  return "/" + path.replace(/^\/+/, "");
};

const logout = () => {
  router.post(route("logout"));
};

const deleteScene = async (id) => {
  if (confirm("Are you sure you want to delete this scene?")) {
    router.delete(route("scenes.destroy", id), {
      preserveScroll: true,
      onSuccess: async () => {
        console.log("🗑 Scene deleted successfully!");
        window.location.reload(); //
        // ✅ Fetch only scenes from current municipality
        const res = await fetch(`/api/scenes/${props.municipal}`);
        const data = await res.json();

        scenes.value = data.map((scene) => ({
          ...scene,
          img: getImageUrl(scene.panorama_path),
          date: new Date(scene.created_at).toLocaleDateString(),
        }));
      },
      onError: (e) => console.error("❌ Delete failed:", e),
    });
  }
};

const groupByTitle = (list) => {
  const grouped = {};
  list.forEach((scene) => {
    const title = scene.title?.trim() || "Untitled";
    if (!grouped[title]) {
      grouped[title] = { ...scene, count: 1 };
    } else {
      grouped[title].count++;
    }
  });
  return Object.values(grouped).map((scene) => ({
    ...scene,
    img: getImageUrl(scene.panorama_path),
    date: new Date(scene.created_at).toLocaleDateString(),
  }));
};

// ✅ Initialize data on load
onMounted(() => {
  allPublishedScenes.value = props.scenes.map((s) => ({
    ...s,
    img: getImageUrl(s.panorama_path),
    date: new Date(s.created_at).toLocaleDateString(),
  }));
  scenes.value = groupByTitle(allPublishedScenes.value);

  drafts.value = props.drafts.map((s) => ({
    ...s,
    img: getImageUrl(s.panorama_path),
    date: new Date(s.created_at).toLocaleDateString(),
  }));
});

// ✅ Group filter functions
const filterGroup = (title) => {
  const filtered = allPublishedScenes.value.filter(
    (s) => s.title.trim() === title.trim()
  );

  // Set active title and count
  activeGroupTitle.value = title;
  activeGroupCount.value = filtered.length;

  // Format image + date for each scene
  scenes.value = filtered.map((scene) => ({
    ...scene,
    img: getImageUrl(scene.panorama_path),
    date: new Date(scene.created_at).toLocaleDateString(),
  }));
};

const clearGroupFilter = () => {
  activeGroupTitle.value = null;
  activeGroupCount.value = 0;
  scenes.value = groupByTitle(allPublishedScenes.value);
};

// ✅ Modal event handlers
const handlePublishScene = (newScene) => {
  scenes.value.unshift({
    ...newScene,
    date: new Date().toLocaleDateString(),
    img: getImageUrl(newScene.panorama_path),
  });
  setTimeout(async () => {
    const response = await fetch("/dashboard");
  }, 800);
};
const getThumbnail = (panoPath) => {
  if (!panoPath) return "";

  const parts = panoPath.split("/");

  const file = parts.pop();

  const sceneName = file.replace(/\.[^/.]+$/, ""); 

  const base = parts.join("/");

  return `${base}/panos/${sceneName}.tiles/thumb.jpg`;
};
const handleSaveDraft = (draftScene) => {
  // Add new draft
  const newDraft = {
    ...draftScene,
    date: new Date().toLocaleDateString(),
    img: getImageUrl(draftScene.previewUrl || draftScene.panorama_path),
  };

  // Add + regroup
  drafts.value.push(newDraft);
  drafts.value = groupByTitle(drafts.value);

  console.log("💾 Draft Saved:", newDraft);
};

// ✅ Barangays and Categories
const categories = ["Tourist Spot", "Accommodation & Restaurant", "Others"];
</script>

<template>
  <Head title="Cebu CMS" />

  <div
    style="display:flex; height:100vh; background:#f5f6fa; font-family:'Inter', sans-serif; color:#222;"
  >
    <!-- Sidebar -->
    <aside
      style="width:300px; background-color:#0f172a; color:white; display:flex; flex-direction:column; align-items:center; padding:40px 20px;"
    >
      <div
        style="width:70px; height:70px; border-radius:50%; background-color:#1e293b; display:flex; justify-content:center; align-items:center; font-size:22px; font-weight:bold;"
      >
        {{ municipal.charAt(0).toUpperCase() }}
      </div>
      <p style="margin-top:10px; font-size:18px;">Hi {{ municipal }}</p>

      <nav
        style="width:100%; margin-top:40px; display:flex; flex-direction:column; gap:10px;"
      >
        <button
          @click="activeTab='scene'"
          :style="activeTab==='scene'?activeBtn:btn"
        >
          Manage 360° Scene
        </button>
        <button
          @click="activeTab='barangay'"
          :style="activeTab==='barangay'?activeBtn:btn"
        >
          Barangay
        </button>
        <button
          @click="activeTab='category'"
          :style="activeTab==='category'?activeBtn:btn"
        >
          Category
        </button>
        <button
          @click="activeTab='drafts'"
          :style="activeTab==='drafts'?activeBtn:btn"
        >
          Save Draft
        </button>
      </nav>
    </aside>

    <!-- Main Section -->
    <main style="flex:1; display:flex; flex-direction:column; overflow-y:auto;">
      <header
        style="background-color:white; padding:20px 40px; display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid #e5e7eb;"
      >
        <h1 style="font-size:25px; font-weight:600;">
          {{
            activeTab === 'scene' ? `Municipality of ${ municipal } 360° Scenes` :
            activeTab === 'barangay' ? 'Barangays' :
            activeTab === 'category' ? 'Categories' :
            'Save Draft 360° Scenes'
          }}
        </h1>
        <button
          @click.prevent="logout"
          style="
                font-size: 16px;
                padding: 8px 20px;
                border-radius: 20px;
                border: 1px solid #d1d5db;
                background-color: #ffffff;
                color: #000;
                cursor: pointer;
                transition: all 0.2s ease;
            "
          @mouseover="(e) => (e.target.style.backgroundColor = '#f3f4f6')"
          @mouseleave="(e) => (e.target.style.backgroundColor = '#ffffff')"
        >
          Logout
        </button>
      </header>

      <!-- SCENES -->
      <section v-if="activeTab==='scene'" style="flex:1; overflow-y:auto;">
        <div
          style="background-color:white; margin:20px 40px; padding:10px 10px; display:flex; align-items:center; flex-wrap:wrap; gap:12px; border-bottom:1px solid #e5e7eb; border-radius:10px;"
        >
          <input
            type="text"
            placeholder="Search scene..."
            style="width:900px; padding:10px 14px; border:1px solid #d1d5db; border-radius:12px; outline:none;"
          />
          <div style="display:flex; gap:10px;">
            <button
              @click="
                activeBarangay = null;
                activeCategory = null;
                showBarangayDropdown = false;
                showCategoryDropdown = false;
              "
              style="font-size:18px; padding:8px 30px; background-color:#f3f4f6; border-radius:20px; border:none; cursor:pointer;"
            >
              All
            </button>
            <div style="position:relative;">
            <button
              @click="
                showBarangayDropdown = !showBarangayDropdown;
                showCategoryDropdown = false;
              "
              style="display:flex; font-size:18px; padding:8px 30px; background-color:#f3f4f6; border-radius:20px; border:none; cursor:pointer;"
            >
              <img
                src="/images/barangay_pin.png"
                style="width:26px; height:18px; padding-right:8px;"
              />
              Barangay
            </button>

            <!-- Dropdown -->
            <div
              v-if="showBarangayDropdown"
              style="position:absolute; top:50px; left:0; background:#fff; border:1px solid #e5e7eb; border-radius:10px; box-shadow:0 8px 20px rgba(0,0,0,0.15); width:220px; z-index:50;"
            >
              <div
                  v-for="b in barangays"
                  :key="b"
                  @click="
                    activeBarangay = b;
                    showBarangayDropdown = false;
                  "
                  style="padding:10px 14px; cursor:pointer;"
                >
                  {{ b }}
                </div>
            </div>
          </div>
            <div style="position:relative;">
              <button
                @click="
                  showCategoryDropdown = !showCategoryDropdown;
                  showBarangayDropdown = false;
                "
                style="display:flex; font-size:18px; padding:8px 30px; background-color:#f3f4f6; border-radius:20px; border:none; cursor:pointer;"
              >
                <img
                  src="/images/barangay_tag.png"
                  style="width:26px; height:18px; padding-right:8px;"
                />
                Category
              </button>

              <!-- Dropdown -->
              <div
                v-if="showCategoryDropdown"
                style="position:absolute; top:50px; left:0; background:#fff; border:1px solid #e5e7eb; border-radius:10px; box-shadow:0 8px 20px rgba(0,0,0,0.15); width:260px; z-index:50;"
              >
                <div
                v-for="c in categories"
                :key="c"
                @click="
                  activeCategory = c;
                  showCategoryDropdown = false;
                "
                style="padding:10px 14px; cursor:pointer;"
              >
                {{ c }}
              </div>
              </div>
            </div>
            
          </div>

          <addSceneModal
            v-if="showModal"
            @close="showModal = true"
            @saveDraft="handleSaveDraft"
            @publishScene="handlePublishScene"
            :barangays="barangays"
            :municipal="municipal"
            ref="sceneModal"
          />
        </div>
        <div style="margin:10px 0 0 0; display:flex; gap:10px; padding-left: 5%;">
            <span
              v-if="activeBarangay"
              style="background:#2563eb; color:white; padding:6px 14px; border-radius:20px; display:inline-flex; align-items:center;"
            >
              Barangay: {{ activeBarangay }}
              <span
                style="margin-left:8px; cursor:pointer; font-weight:bold;"
                @click="activeBarangay = null"
              >✕</span>
            </span>

            <span
              v-if="activeCategory"
              style="background:#2563eb; color:white; padding:6px 14px; border-radius:20px; display:inline-flex; align-items:center;"
            >
              Category: {{ activeCategory }}
              <span
                style="margin-left:8px; cursor:pointer; font-weight:bold;"
                @click="activeCategory = null"
              >✕</span>
            </span>
          </div>
        <!-- Back Button for Filtered Group -->
        <div
          v-if="activeGroupTitle"
          style="margin:0 40px 20px;display: flex;justify-content:space-between; "
        >
          <button
            @click="clearGroupFilter"
            style="background:#101828; color:white; padding:8px 16px; border:none; border-radius:8px; cursor:pointer; font-size:18px; font-weight:500;"
          >
            ← All Scenes
          </button>
          <div
            style="display: flex;justify-content:space-between; flex-direction: column; align-items: center;"
          >
            <p style=" font-weight:600;font-size: 20px;">
              {{ activeGroupTitle }} Scenes
            </p>
            <p
              style="font-family: 'Roboto', sans-serif; color:#101828; font-weight:500;font-size: 17px;"
            >
              {{ activeGroupCount }} Scenes
            </p>
          </div>
          <addSceneModal
            v-if="showModal"
            @close="showModal = true"
            @saveDraft="handleSaveDraft"
            @publishScene="handlePublishScene"
          />
        </div>

        <!-- Scene Cards -->
        <div
          style="padding:30px 40px; display:flex; flex-flow:row wrap; gap:30px; width:100%; justify-content:left; max-width:1600px; margin:0 auto;"
        >
          <div
            v-for="scene in filteredScenes"
            :key="scene.id"
            style="background:#fff; border-radius:16px; box-shadow:0 2px 8px rgba(0,0,0,0.1); padding:16px; width:32%; min-width:450px; flex-direction:column; justify-content:space-between;"
          >
            <div style="position:relative;">
              <!-- ✅ Use S3 or local via helper -->
              <div
              style="
                position:relative;
                width:100%;
                height:180px;
                border-radius:12px;
                overflow:hidden;
                margin-bottom:12px;
              "
            >
              <img
                v-if="!imageFailed[scene.id]"
                :src="getImageUrl(getThumbnail(scene.panorama_path || scene.img))"
                loading="lazy"
                alt=""
                style="width:100%; height:100%; object-fit:cover;"
                @error="
                  imageFailed[scene.id] = true;
                  checkThumbnailReady(
                    scene.id,
                    getImageUrl(getThumbnail(scene.panorama_path || scene.img))
                  );
                "
              />

              <div
                v-else
                style="
                  width:100%;
                  height:100%;
                  background:#f3f4f6;
                  display:flex;
                  align-items:center;
                  justify-content:center;
                  font-size:16px;
                  font-weight:600;
                  color:#6b7280;
                "
              >
                ⏳ Generating…
              </div>
                </div>
              <div
                v-if="scene.count > 1"
                style="position:absolute; top:10px; right:10px; background:#facc15; color:#000; font-weight:600; font-size:13px; border-radius:20px; padding:4px 10px; display:flex; align-items:center; justify-content:center; box-shadow:0 1px 4px rgba(0,0,0,0.25);"
              >
                {{ scene.count }} Scenes
              </div>
            </div>

            <div
              style="display:flex; justify-content:space-between; align-items:center;"
            >
              <h2 style="font-size:18px; font-weight:600; color:#000;">
                {{ scene.title }}
              </h2>
              <span
                style="background:#f9fafb; border-radius:20px; font-size:12px; padding:4px 12px; color:#111827; border:1px solid #e5e7eb;"
                >{{ scene.date }}</span
              >
            </div>
            <div
              style="display:flex; justify-content:space-between; align-items:center;"
            >
              <h2
                style="font-size:14px; color:#000;margin-right: 50px;font-style: italic;"
              >
                {{ scene.location }}
              </h2>
            </div>

            <div
              style="display:flex; align-items:center; gap:16px; margin-top:6px; color:#6b7280; font-size:14px;padding-bottom: 15px;"
            >
              <div style="display:flex; align-items:center; gap:6px;">
                <img
                  src="/images/barangay_pin.png"
                  style="width:16px; height:18px;"
                />
                <span>Brgy. {{ scene.barangay }}</span>
              </div>
              <div style="display:flex; align-items:center; gap:6px;">
                <img
                  src="/images/barangay_tag.png"
                  style="width:16px; height:18px;"
                />
                <span>{{ scene.category }}</span>
              </div>
            </div>

            <div
              style="display:flex; justify-content:center; align-items:center; gap:10px; margin:14px 50px 0;"
            >
              <!-- If multiple scenes -->
              <template v-if="scene.count > 1">
                <button
                  @click="filterGroup(scene.title)"
                  style="flex:1; display:flex; align-items:center; margin-left: 125px; margin-right: 125px; justify-content:center; gap:6px; background:none; border:1px solid #d1d5db; border-radius:10px; padding:8px 0; font-size:15px; cursor:pointer;"
                >
                  <img
                    src="/images/show_eye.png"
                    style="width:20px; height:20px;"
                  />
                  View
                </button>
              </template>

              <!-- Single Scene Buttons -->
              <template v-else>
                <button
                  @click="sceneModal && sceneModal.openForEdit(scene)"
                  style="flex:1; display:flex; align-items:center; justify-content:center; gap:6px; background:none; border:1px solid #d1d5db; border-radius:10px; padding:8px 0; font-size:15px; cursor:pointer;"
                >
                  <img src="/images/edit_pen.png" style="width:20px; height:18px;" /> Edit
                </button>
                <button
                  style="flex:1; display:flex; align-items:center; justify-content:center; gap:6px; background:none; border:1px solid #d1d5db; border-radius:10px; padding:8px 0; font-size:15px; cursor:pointer;"
                >
                  <img src="/images/show_eye.png" style="width:20px; height:20px;" /> View
                </button>
                <button
                  @click="deleteScene(scene.id)"
                  style="flex:1; display:flex; align-items:center; justify-content:center; gap:6px; background:#e5094a; border:none; color:#fff; border-radius:10px; padding:8px 0; font-size:15px; cursor:pointer;"
                >
                  <img src="/images/delete_trash.png" style="width:15px; height:15px;" /> Delete
                </button>
              </template>
            </div>
          </div>
        </div>
      </section>

      <!-- BARANGAYS -->
      <section v-if="activeTab==='barangay'" style="padding:30px 40px;">
        <div
          v-for="(b, i) in props.barangays"
          :key="i"
          style="font-size: 20px; border-bottom:1px solid #D7D7D7; padding:12px 16px; display:flex; justify-content:space-between;"
        >
          <span>{{ b }}</span>
        </div>
      </section>

      <!-- CATEGORIES -->
      <section v-if="activeTab==='category'" style="padding:30px 40px;">
        <ul style=" padding:0; list-style:none;">
          <li
            v-for="(c,i) in categories"
            :key="i"
            style="padding:12px 16px; border-bottom:1px solid #D7D7D7;display:flex; justify-content:space-between;"
          >
            {{ c }}<span>{{ b }}</span>
            <span style="color:#9ca3af;">⋮</span>
          </li>
        </ul>
      </section>

      <!-- DRAFTS -->
      <section v-if="activeTab==='drafts'" style="padding:30px 40px;">
        <div
          style="display:flex; align-items:center; gap:12px; margin-bottom:20px;"
        >
          <input
            type="text"
            placeholder="Search scene..."
            style="flex:1; padding:10px 14px; border:1px solid #d1d5db; border-radius:8px;"
          />
          <button
            style="padding:10px 20px; background:#2563eb; color:white; border:none; border-radius:8px;"
          >
            + Add Scene
          </button>
        </div>

        <div
          style="display:grid; grid-template-columns:repeat(auto-fill, minmax(500px, 1fr)); gap:20px;"
        >
          <div
            v-for="scene in drafts"
            :key="scene.id"
            style="background:white; border-radius:16px; box-shadow:0 2px 8px rgba(0,0,0,0.1); padding:16px;"
          >
            <img
<<<<<<< HEAD
                  :src="getImageUrl(scene.draft_panorama_path || scene.img)"
                  loading="lazy"
                  alt=""
                  style="width:100%; height:180px; border-radius:12px; object-fit:cover; margin-bottom:12px;"
                />

=======
                :src="getImageUrl(getThumbnail(scene.panorama_path || scene.img))"
                loading="lazy"
                alt=""
                style="width: 100%; height: 180px; border-radius: 12px; object-fit: cover; margin-bottom: 12px;"
              />
>>>>>>> parent of 9fe05ca (Fixed the save drafts)
            <h2 style="font-size:18px; font-weight:600;">{{ scene.title }}</h2>
            <span
              style="background:#f9fafb; border-radius:20px; font-size:12px; padding:4px 12px; color:#111827; border:1px solid #e5e7eb;"
              >{{ scene.date}}</span
            >

            <div
              style="display:flex; align-items:center; gap:16px; margin-top:6px; color:#6b7280; font-size:14px;"
            >
              <div style="display:flex; align-items:center; gap:6px;">
                <img
                  src="/images/barangay_pin.png"
                  style="width:16px; height:18px;"
                />
                <span>Brgy. {{ scene.barangay }}</span>
              </div>
              <div style="display:flex; align-items:center; gap:6px;">
                <img
                  src="/images/barangay_tag.png"
                  style="width:16px; height:18px;"
                />
                <span>{{ scene.category }}</span>
              </div>
            </div>

            <div
              style="display:flex; justify-content:center; align-items:center; gap:10px; margin:14px 50px 0;"
            >
              <button
                style="flex:1; display:flex; align-items:center; justify-content:center; gap:6px; background:none; border:1px solid #d1d5db; border-radius:10px; padding:8px 0; font-size:15px; cursor:pointer;"
              >
                <img
                  src="/images/edit_pen.png"
                  style="width:20px; height:18px;"
                />
                Edit
              </button>
              <button
                @click="deleteScene(scene.id)"
                style="flex:1; display:flex; align-items:center; justify-content:center; gap:6px; background:#e5094a; border:none; color:#fff; border-radius:10px; padding:8px 0; font-size:15px; cursor:pointer;"
              >
                <img
                  src="/images/delete_trash.png"
                  style="width:15px; height:15px;"
                />
                Delete
              </button>
              <button
<<<<<<< HEAD
                @click="publishDraft(scene.id)"
                style="flex:1; display:flex; align-items:center; justify-content:center;color:#FFF; gap:6px;background:#2383E2; border:1px solid #d1d5db; border-radius:10px; padding:8px 0; font-size:15px; cursor:pointer;"
              >
                <img src="/images/plane.png" style="width:15px; height:15px;" />
=======
                style="flex:1; display:flex; align-items:center; justify-content:center;color:#FFF; gap:6px;background: #2383E2; border:1px solid #d1d5db; border-radius:10px; padding:8px 0; font-size:15px; cursor:pointer;"
              >
                <img
                  src="/images/plane.png"
                  style="width:15px; height:15px;"
                />
>>>>>>> parent of 9fe05ca (Fixed the save drafts)
                Publish
              </button>
            </div>
          </div>
        </div>
      </section>
    </main>
  </div>
</template>

<script>
const btn = {
  fontSize: "20px",
  padding: "10px 20px",
  textAlign: "left",
  background: "none",
  border: "none",
  color: "white",
  cursor: "pointer",
  borderRadius: "6px",
};

const activeBtn = {
  ...btn,
  backgroundColor: "#1e293b",
};
</script>

<style scoped>
::-webkit-scrollbar {
  width: 8px;
}
::-webkit-scrollbar-thumb {
  background: #d1d5db;
  border-radius: 4px;
}
::-webkit-scrollbar-thumb:hover {
  background: #9ca3af;
}
</style>

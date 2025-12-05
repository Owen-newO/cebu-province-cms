<script setup>
import { ref, onMounted, watch, computed } from "vue";
import { router } from "@inertiajs/vue3";
import { nextTick } from "vue";

const emit = defineEmits(["close", "saveDraft", "publishScene"]);

const props = defineProps({
  barangays: Array,
  municipal: String,
});

const categories = ["Tourist Spot", "Accommodation & Restaurant", "Others"];

const showModal = ref(false);
const existingScenes = ref([]);
const existingScenesFull = ref({}); // 👈 ADDED — store full existing scenes

// -----------------------------------------------------------
// mode + editing
// -----------------------------------------------------------
const mode = ref("create");
const editingId = ref(null);

// -----------------------------------------------------------
// default scene
// -----------------------------------------------------------
const makeEmptyScene = () => ({
  existingScene: "",
  title: "",
  location: "",
  barangay: "",
  category: "",
  address: "",
  panorama: null,
  previewUrl: null,
  google_map_link: "",
  contact_number: "",
  email: "",
  website: "",
  facebook: "",
  instagram: "",
  tiktok: "",
});

const scene = ref(makeEmptyScene());

// -----------------------------------------------------------
// Load existing scenes
// -----------------------------------------------------------
onMounted(async () => {
  try {
    const res = await fetch("/api/scenes");
    const data = await res.json();

    const filtered = data.filter(
      (s) =>
        s.municipal?.toLowerCase().trim() ===
        props.municipal?.toLowerCase().trim()
    );

    const titles = [...new Set(filtered.map((s) => s.title))];

    existingScenes.value = titles;

    // 👇 store full data for auto-fill
    filtered.forEach((s) => {
      existingScenesFull.value[s.title] = s;
    });

  } catch (error) {
    console.error("❌ Failed to load existing scenes:", error);
  }
});

// -----------------------------------------------------------
// Modal helpers
// -----------------------------------------------------------
const openModal = () => {
  mode.value = "create";
  editingId.value = null;
  scene.value = makeEmptyScene();
  showModal.value = true;
};

const openForEdit = (initialScene) => {
  if (!initialScene) return;

  mode.value = "edit";
  editingId.value = initialScene.id ?? null;

  scene.value = {
    existingScene: "",
    title: initialScene.title || "",
    location: initialScene.location || "",
    barangay: initialScene.barangay || "",
    category: initialScene.category || "",
    address: initialScene.address || "",
    google_map_link: initialScene.google_map_link || "",
    contact_number: initialScene.contact_number || "",
    email: initialScene.email || "",
    website: initialScene.website || "",
    facebook: initialScene.facebook || "",
    instagram: initialScene.instagram || "",
    tiktok: initialScene.tiktok || "",
    panorama: null,
    previewUrl:
      initialScene.img ||
      initialScene.panorama_path ||
      null,
  };

  showModal.value = true;
};

const closeModal = () => {
  showModal.value = false;
  emit("close");
};

defineExpose({ openModal, openForEdit });

// -----------------------------------------------------------
// File Upload
// -----------------------------------------------------------
const handleFileUpload = (e) => {
  const file = e.target.files[0];
  if (file) {
    scene.value.panorama = file;
    scene.value.previewUrl = URL.createObjectURL(file);
  }
};

// -----------------------------------------------------------
// Disable ALL DATA fields when selecting an existing scene
// -----------------------------------------------------------
const isUsingExisting = computed(() => {
  return mode.value === "create" &&
         existingScenes.value.includes(scene.value.existingScene);
});

// Title disable only
const isTitleDisabled = ref(false);

watch(() => scene.value.existingScene, (val) => {
  // NEW SCENE → DO NOTHING, DO NOT RESET
  if (val === "__new__" || val === "") return;

  const data = existingScenesFull.value[val];
  if (!data) return;

  // Autofill locked fields
  scene.value.title = data.title || "";
  scene.value.barangay = data.barangay || "";
  scene.value.category = data.category || "";
  scene.value.address = data.address || "";
  scene.value.google_map_link = data.google_map_link || "";
  scene.value.contact_number = data.contact_number || "";
  scene.value.email = data.email || "";
  scene.value.website = data.website || "";
  scene.value.facebook = data.facebook || "";
  scene.value.instagram = data.instagram || "";
  scene.value.tiktok = data.tiktok || "";

  // Editable fields reset
  scene.value.location = "";
  scene.value.panorama = null;
  scene.value.previewUrl = null;
});

// -----------------------------------------------------------
// Submit create
// -----------------------------------------------------------
const submitScene = (isPublished) => {
  const formData = new FormData();

  // Title must ALWAYS come from the input
  formData.append("title", scene.value.title);

  formData.append("municipal", props.municipal);
  formData.append("location", scene.value.location);
  formData.append("barangay", scene.value.barangay);
  formData.append("category", scene.value.category);
  formData.append("address", scene.value.address);
  formData.append("google_map_link", scene.value.google_map_link);
  formData.append("contact_number", scene.value.contact_number);
  formData.append("email", scene.value.email);
  formData.append("website", scene.value.website);
  formData.append("facebook", scene.value.facebook);
  formData.append("instagram", scene.value.instagram);
  formData.append("tiktok", scene.value.tiktok);
  formData.append("is_published", isPublished ? "true" : "false");

  if (scene.value.panorama)
    formData.append("panorama", scene.value.panorama);

  router.post(route("scenes.store"), formData, {
    preserveScroll: true,
    onSuccess: () => {
      closeModal();
      window.location.reload();
    },
  });
};

const saveDraft = () => submitScene(false);
const publishScene = () => submitScene(true);

// -----------------------------------------------------------
// Update Scene
// -----------------------------------------------------------
const updateScene = () => {
  if (!editingId.value) return;

  const formData = new FormData();
  formData.append("_method", "PUT");

  formData.append("title", scene.value.title);
  formData.append("municipal", props.municipal);
  formData.append("location", scene.value.location);
  formData.append("barangay", scene.value.barangay);
  formData.append("category", scene.value.category);
  formData.append("address", scene.value.address);
  formData.append("google_map_link", scene.value.google_map_link);
  formData.append("contact_number", scene.value.contact_number);
  formData.append("email", scene.value.email);
  formData.append("website", scene.value.website);
  formData.append("facebook", scene.value.facebook);
  formData.append("instagram", scene.value.instagram);
  formData.append("tiktok", scene.value.tiktok);
  formData.append("is_published", "true");

  router.visit(route("scenes.update", editingId.value), {
    method: "post",
    data: formData,
    preserveScroll: true,
    onSuccess: () => {
      closeModal();
      window.location.reload();
    },
  });
};
</script>

<template>
  <!-- OPEN MODAL BUTTON -->
  <button
    @click="openModal"
    style="
      background-color:#2563eb;
      color:white;
      padding:10px 20px;
      border-radius:8px;
      border:none;
      cursor:pointer;
      font-size:16px;
    "
  >
    + Add Scene
  </button>

  <!-- OVERLAY -->
  <div
    v-if="showModal"
    style="
      position:fixed;top:0;left:0;width:100vw;height:100vh;
      background:rgba(0,0,0,0.45);
      backdrop-filter:blur(4px);
      display:flex;
      justify-content:center;
      align-items:center;
      z-index:10000;
    "
  >
    <!-- MODAL BOX -->
    <div
      style="
        background:white;
        width:1000px;
        border-radius:14px;
        padding:35px 40px;
        position:relative;
        display:flex;
        flex-direction:column;
        gap:18px;
        max-height:92vh;
        overflow-y:auto;
      "
    >
      <!-- CLOSE BUTTON -->
      <button
        @click="closeModal"
        style="
          position:absolute;
          top:16px;
          right:20px;
          background:none;
          border:none;
          font-size:26px;
          cursor:pointer;
          color:#444;
        "
      >
        ✕
      </button>

      <!-- HEADER -->
      <h2
        style="
          font-size:24px;
          font-weight:600;
          margin-bottom:6px;
          color:#111;
        "
      >
        {{ mode === 'create' ? 'Add New 360° Scenes' : 'Edit 360° Scene' }}
      </h2>

      <!-- 2 COLUMN GRID -->
      <div
        style="
          display:grid;
          grid-template-columns:1.1fr 0.9fr;
          gap:28px;
        "
      >

        <!-- LEFT COLUMN -->
        <div style="display:flex;flex-direction:column;gap:30px;">

          <!-- EXISTING SCENE (CREATE ONLY) -->
          <div v-if="mode==='create'">
            <label style="font-size:15px;font-weight:600;color:#111;">
              Add to Existing Scene (Optional)
            </label>
            <select
              v-model="scene.existingScene"
              style="
                width:100%;
                padding:12px;
                border-radius:10px;
                border:1px solid #d1d5db;
                background:#EEEDED;
                font-size:15px;
              "
            >
              <option value="__new__">Add New Scene</option>
              <option v-for="(s,i) in existingScenes" :key="i" :value="s">
                {{ s }}
              </option>
            </select>
          </div>

          <!-- TITLE -->
          <div>
            <label style="font-size:15px;font-weight:600;color:#111;">
              {{ mode==='create' ? 'Add New Scene Title' : 'Scene Title' }}
            </label>
            <input
                v-model="scene.title"
                placeholder="e.g., Magellan’s Cross"
                :disabled="isUsingExisting"
                :style="
                  isUsingExisting
                    ? 'width:100%;padding:12px;border-radius:10px;border:1px solid #d1d5db;background:#f3f4f6;cursor:not-allowed;font-size:15px;'
                    : 'width:100%;padding:12px;border-radius:10px;border:1px solid #d1d5db;background:#EEEDED;font-size:15px;'
                "
              />

          </div>

          <!-- LOCATION -->
          <div>
            <label style="font-size:15px;font-weight:600;color:#111;">Scene Location</label>
            <input
              v-model="scene.location"
              placeholder="e.g., Streetview, Entrance"
              style="
                width:100%;padding:12px;border-radius:10px;background-color:#EEEDED;
                border:1px solid #d1d5db;font-size:15px;
              "
            />
          </div>

          <!-- BARANGAY -->
          <div>
            <label style="font-size:15px;font-weight:600;">Barangay</label>
<select
  v-model="scene.barangay"
  :disabled="isUsingExisting"
  :style="
    isUsingExisting
      ? 'width:100%;padding:12px;border-radius:10px;background:#f3f4f6;border:1px solid #d1d5db;cursor:not-allowed;font-size:15px;'
      : 'width:100%;padding:12px;border-radius:10px;background:#EEEDED;border:1px solid #d1d5db;font-size:15px;'
  "
>

              <option value="">Select Barangay</option>
              <option v-for="b in props.barangays" :key="b">{{ b }}</option>
            </select>
          </div>

          <!-- CATEGORY -->
          <div>
            <label style="font-size:15px;font-weight:600;">Category</label>
<select
  v-model="scene.category"
  :disabled="isUsingExisting"
  :style="
    isUsingExisting
      ? 'width:100%;padding:12px;border-radius:10px;background:#f3f4f6;border:1px solid #d1d5db;cursor:not-allowed;font-size:15px;'
      : 'width:100%;padding:12px;border-radius:10px;background:#EEEDED;border:1px solid #d1d5db;font-size:15px;'
  "
>


              <option value="">Select Category</option>
              <option v-for="c in categories" :key="c">{{ c }}</option>
            </select>
            
          </div>

          <!-- GOOGLE MAP -->
          <div>
            <label style="font-size:15px;font-weight:600;">Google Map Link (Optional)</label>
            <input
  v-model="scene.google_map_link"
              placeholder="e.g., https://maps.app.goo.gl/..."

  :disabled="isUsingExisting"
  :style="
    isUsingExisting
      ? 'width:100%;padding:12px;border-radius:10px;background:#f3f4f6;border:1px solid #d1d5db;cursor:not-allowed;font-size:15px;'
      : 'width:100%;padding:12px;border-radius:10px;background:#EEEDED;border:1px solid #d1d5db;font-size:15px;'
  "
/>

          </div>

        </div>

        <!-- RIGHT COLUMN -->
        <div style="display:flex;flex-direction:column;gap:18px;">

          <!-- UPLOAD BOX -->
           <label style="margin-top:-22px;font-size:15px;font-weight:600;color:#111;">
              Add to Existing Scene 
            </label>
          <label
            style="background-color:#EEEDED;
              width:100%;height:150px;border:2px dashed #d1d5db;
              border-radius:12px;display:flex;align-items:center;
              justify-content:center;flex-direction:column;
              cursor:pointer;color:#666;overflow:hidden;
            "
          >
            <input
              type="file"
              accept="image/jpeg,image/jpg"
              @change="handleFileUpload"
              style="display:none;"
            />

            <img
              v-if="scene.previewUrl"
              :src="scene.previewUrl"
              style="width:100%;height:100%;object-fit:cover;"
            />

            <template v-else >
              <img src="/images/360_icon.png" style="width:65px;opacity:0.7;margin-bottom:6px;" />
              <p style="font-size:14px;text-align:center;line-height:20px;">
                Upload 360° Panorama<br>JPEG/JPG Only
              </p>
            </template>
          </label>

          <!-- ADDRESS -->
          <div>
            <label style="font-size:15px;font-weight:600;">Details & Description (Optional)</label>

            <textarea
  rows="4"
  v-model="scene.address"
              placeholder="e.g. A scenic tourist spot known for its peaceful surroundings and beautiful views. Visitors can enjoy a short 50-meter trek to reach the main area and are required to pay an environmental fee of ₱50 upon entry. Open Monday to Sunday, 9:00 AM to 5:00 PM."

  :disabled="isUsingExisting"
  :style="
    isUsingExisting
      ? 'background-color:#f3f4f6;width:100%;padding:4px;border-radius:10px;font-style:italic;border:1px solid #d1d5db;font-size:12px;cursor:not-allowed;'
      : 'background-color:#EEEDED;width:100%;padding:4px;border-radius:10px;font-style:italic;border:1px solid #d1d5db;font-size:12px;'
  "
></textarea>

          </div>

          <!-- CONTACT + EMAIL -->
          <div style="display:flex;gap:12px;">
            <div style="flex:1;">
              <label style="font-size:15px;font-weight:600;">Contact # (Optional)</label>

              <input
  v-model="scene.contact_number"
  maxlength="11"
                placeholder="e.g., 09123456789"
  @input="scene.contact_number = scene.contact_number.replace(/[^0-9]/g, '')"
  :disabled="isUsingExisting"
  :style="
    isUsingExisting
      ? 'width:100%;padding:12px;border-radius:10px;background:#f3f4f6;border:1px solid #d1d5db;cursor:not-allowed;font-size:15px;'
      : 'width:100%;padding:12px;border-radius:10px;background:#EEEDED;border:1px solid #d1d5db;font-size:15px;'
  "
/>

            </div>

            <div style="flex:1;">
              <label style="font-size:15px;font-weight:600;">Email (Optional)</label>

              <input
  v-model="scene.email"
  :disabled="isUsingExisting"
                placeholder="e.g., cebu@mail.com"

  :style="
    isUsingExisting
      ? 'width:100%;padding:12px;border-radius:10px;background:#f3f4f6;border:1px solid #d1d5db;cursor:not-allowed;font-size:15px;'
      : 'width:100%;padding:12px;border-radius:10px;background:#EEEDED;border:1px solid #d1d5db;font-size:15px;'
  "
/>

            </div>
          </div>

          <!-- WEBSITE + FACEBOOK -->
          <div style="display:flex;gap:12px;">
            <div style="flex:1;">
              <label style="font-size:15px;font-weight:600;">Website (Optional)</label>
              <input
  v-model="scene.website"
  :disabled="isUsingExisting"
                placeholder="e.g., www.cebu.com"
  :style="
    isUsingExisting
      ? 'width:100%;padding:12px;border-radius:10px;background:#f3f4f6;border:1px solid #d1d5db;cursor:not-allowed;font-size:15px;'
      : 'width:100%;padding:12px;border-radius:10px;background:#EEEDED;border:1px solid #d1d5db;font-size:15px;'
  "
/>

            </div>

            <div style="flex:1;">
              <label style="font-size:15px;font-weight:600;">Facebook (Optional)</label>
              <input
            v-model="scene.facebook"
            placeholder="e.g., facebook.com/cebu"
            :disabled="isUsingExisting"
            :style="
              isUsingExisting
                ? 'width:100%;padding:12px;border-radius:10px;background:#f3f4f6;border:1px solid #d1d5db;cursor:not-allowed;font-size:15px;'
                : 'width:100%;padding:12px;border-radius:10px;background:#EEEDED;border:1px solid #d1d5db;font-size:15px;'
            "
          />

            </div>
          </div>

          <!-- INSTAGRAM + TIKTOK -->
          <div style="display:flex;gap:12px;">
            <div style="flex:1;">
              <label style="font-size:15px;font-weight:600;">Instagram (Optional)</label>
              <input
                  v-model="scene.instagram"
                  :disabled="isUsingExisting"
                  placeholder="e.g., instagram.com/cebu"
                  :style="
                    isUsingExisting
                      ? 'width:100%;padding:12px;border-radius:10px;background:#f3f4f6;border:1px solid #d1d5db;cursor:not-allowed;font-size:15px;'
                      : 'width:100%;padding:12px;border-radius:10px;background:#EEEDED;border:1px solid #d1d5db;font-size:15px;'
                  "
                />

            </div>

            <div style="flex:1;">
              <label style="font-size:15px;font-weight:600;">Tiktok (Optional)</label>
              <input
              v-model="scene.tiktok"
              placeholder="e.g., tiktok.com/@cebu"
              :disabled="isUsingExisting"
              :style="
                isUsingExisting
                  ? 'width:100%;padding:12px;border-radius:10px;background:#f3f4f6;border:1px solid #d1d5db;cursor:not-allowed;font-size:15px;'
                  : 'width:100%;padding:12px;border-radius:10px;background:#EEEDED;border:1px solid #d1d5db;font-size:15px;'
              "
            />
            </div>
          </div>

        </div>
      </div>

      <!-- FOOTER BUTTONS -->
      <div style="display:flex;justify-content:space-between;gap:12px;margin-top:15px;">
        
        <template v-if="mode==='create'">
          <div style="display:flex;justify-content:space-between;gap:15px;">
          <button
          @click="closeModal"
          style="
            padding:10px 30px;border:1px solid #d1d5db;
            border-radius:30px;background:white;cursor:pointer;color:black;
            font-size:15px;
          "
        >
          Cancel
        </button>

          <button
            @click="saveDraft"
            style="
              padding:10px 30px;background:#22c55e;
              border:none;color:black;border-radius:30px;
              font-size:15px;cursor:pointer;
            "
          >
            Save Draft
          </button>
         </div>
          <button
            @click="publishScene"
            style="
              padding:10px 30px;background:#2563eb; 
              border:none;color:white;border-radius:30px;
              font-size:15px;cursor:pointer;
            "
          >
          <span style="flex-direction: row; display: flex; gap: 5px; ">
            <img src="/images/plane.png" style="width:15px;opacity:1;height: 15px;align-self: center;" /> <span>Publish</span>
           </span>
          </button>
        </template>

        <template v-else>
          <button
            @click="updateScene"
            style="
              padding:12px 20px;background:#2563eb;
              border:none;color:white;border-radius:10px;
              font-size:15px;cursor:pointer;
            "
          >
            Update
          </button>
        </template>
      </div>

    </div>
  </div>
</template>
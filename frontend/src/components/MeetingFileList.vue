<script setup>
import { onMounted, ref, watch } from "vue";
import Button from "primevue/button";
import DataView from "primevue/dataview";
import Message from "primevue/message";
import ProgressSpinner from "primevue/progressspinner";
import { downloadMeetingFile, listMeetingFiles } from "../api/files.js";

const props = defineProps({
  meetingId: { type: Number, required: true },
});

const files = ref([]);
const loading = ref(false);
const errorMessage = ref("");
const downloadingId = ref(null);

async function load() {
  loading.value = true;
  errorMessage.value = "";
  try {
    files.value = await listMeetingFiles(props.meetingId);
  } catch (err) {
    if (err.status === 401) {
      errorMessage.value = "Session expired. Please log in again.";
    } else {
      errorMessage.value = err.message || "Failed to load files";
    }
    files.value = [];
  } finally {
    loading.value = false;
  }
}

async function onDownload(file) {
  downloadingId.value = file.id;
  try {
    await downloadMeetingFile(props.meetingId, file.id);
  } catch (err) {
    errorMessage.value = err.message || "Download failed";
  } finally {
    downloadingId.value = null;
  }
}

function formatSize(bytes) {
  if (!bytes && bytes !== 0) return "";
  if (bytes < 1024) return `${bytes} B`;
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
  return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
}

function formatDate(value) {
  if (!value) return "";
  const d = new Date(value.replace(" ", "T"));
  if (Number.isNaN(d.getTime())) return value;
  return d.toLocaleString(undefined, {
    day: "2-digit",
    month: "short",
    hour: "2-digit",
    minute: "2-digit",
  });
}

function mimeIcon(mime) {
  if (!mime) return "pi pi-file";
  if (mime.startsWith("image/")) return "pi pi-image";
  if (mime.startsWith("video/")) return "pi pi-video";
  if (mime.startsWith("audio/")) return "pi pi-volume-up";
  if (mime === "application/pdf") return "pi pi-file-pdf";
  if (mime === "application/zip") return "pi pi-box";
  if (mime.startsWith("text/")) return "pi pi-file-edit";
  if (mime.includes("word") || mime.includes("officedocument")) {
    return "pi pi-file-word";
  }
  if (mime.includes("excel") || mime.includes("spreadsheet")) {
    return "pi pi-file-excel";
  }
  return "pi pi-file";
}

onMounted(load);
watch(() => props.meetingId, load);
</script>

<template>
  <div class="files">
    <div v-if="loading" class="files-state">
      <ProgressSpinner style="width: 28px; height: 28px" strokeWidth="4" />
    </div>

    <Message
      v-else-if="errorMessage"
      severity="error"
      :closable="false"
      class="files-message"
    >
      {{ errorMessage }}
    </Message>

    <div v-else-if="files.length === 0" class="files-empty">
      <i class="pi pi-folder-open"></i>
      <p class="files-empty-title">Файлов пока нет</p>
      <p class="files-empty-hint">Загрузите первый файл</p>
    </div>

    <DataView v-else :value="files" data-key="id" class="files-list">
      <template #list="slotProps">
        <div v-for="file in slotProps.items" :key="file.id" class="file-row">
          <i :class="mimeIcon(file.mime_type)" class="file-icon"></i>
          <div class="file-meta">
            <div class="file-name" :title="file.original_name">
              {{ file.original_name }}
            </div>
            <div class="file-sub">
              <span>{{ formatSize(file.size) }}</span>
              <span v-if="file.user_id">·</span>
              <span v-if="file.user_id">user #{{ file.user_id }}</span>
              <span>·</span>
              <span>{{ formatDate(file.created_at) }}</span>
            </div>
            <div v-if="file.label" class="file-label">{{ file.label }}</div>
          </div>
          <Button
            icon="pi pi-download"
            label="Скачать"
            size="small"
            severity="secondary"
            :loading="downloadingId === file.id"
            @click="onDownload(file)"
          />
        </div>
      </template>
    </DataView>
  </div>
</template>

<style scoped>
.files {
  margin-top: 0.75rem;
}

.files-state {
  display: flex;
  justify-content: center;
  padding: 0.75rem 0;
}

.files-message {
  margin: 0;
}

.files-empty {
  text-align: center;
  padding: 0.75rem 0;
  color: var(--p-text-muted-color, #64748b);
}

.files-empty i {
  font-size: 1.5rem;
  margin-bottom: 0.25rem;
  display: block;
}

.files-empty-title {
  margin: 0;
  font-size: 0.9rem;
  font-weight: 600;
  color: var(--p-text-color, #1e293b);
}

.files-empty-hint {
  margin: 0.15rem 0 0;
  font-size: 0.8rem;
}

.files-list {
  border: 1px solid var(--p-content-border-color, #e2e8f0);
  border-radius: 6px;
  overflow: hidden;
}

.file-row {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  padding: 0.6rem 0.75rem;
  border-bottom: 1px solid var(--p-content-border-color, #e2e8f0);
}

.file-row:last-child {
  border-bottom: none;
}

.file-icon {
  font-size: 1.4rem;
  color: var(--p-primary-color, #3b82f6);
  flex-shrink: 0;
}

.file-meta {
  flex: 1;
  min-width: 0;
}

.file-name {
  font-size: 0.9rem;
  font-weight: 500;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.file-sub {
  font-size: 0.75rem;
  color: var(--p-text-muted-color, #64748b);
  display: flex;
  gap: 0.4rem;
  flex-wrap: wrap;
  margin-top: 0.15rem;
}

.file-label {
  font-size: 0.75rem;
  color: var(--p-text-muted-color, #64748b);
  font-style: italic;
  margin-top: 0.1rem;
}
</style>

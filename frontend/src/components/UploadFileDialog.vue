<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from "vue";
import Button from "primevue/button";
import Dialog from "primevue/dialog";
import FileUpload from "primevue/fileupload";
import InputText from "primevue/inputtext";
import Message from "primevue/message";
import ProgressBar from "primevue/progressbar";
import { useToast } from "primevue/usetoast";
import {
  isAllowedMime,
  maxSizeBytesFor,
  maxSizeMbFor,
} from "../api/mimeLimits.js";
import { uploadMeetingFile } from "../api/files.js";

const props = defineProps({
  visible: { type: Boolean, required: true },
  meetingId: { type: Number, required: true },
});

const emit = defineEmits(["update:visible", "uploaded"]);

const toast = useToast();

const selectedFile = ref(null);
const label = ref("");
const inlineError = ref("");
const uploading = ref(false);
const progress = ref(0);
const clearing = ref(false);
const dropActive = ref(false);

const fileUploadRef = ref(null);

let dragDepth = 0;

const selectedMime = computed(() => {
  if (!selectedFile.value) return null;
  return selectedFile.value.type || "";
});

const maxMb = computed(() => maxSizeMbFor(selectedMime.value));
const maxBytes = computed(() => maxSizeBytesFor(selectedMime.value));
const allowed = computed(() =>
  selectedMime.value ? isAllowedMime(selectedMime.value) : true,
);

const limitLabel = computed(() => {
  if (!selectedFile.value) return "";
  if (!selectedMime.value) return "Неизвестный тип";
  if (!allowed.value) return "Неподдерживаемый тип файла";
  if (maxMb.value == null) return "";
  return `Лимит: ${maxMb.value} МБ`;
});

const sizeMb = computed(() => {
  if (!selectedFile.value) return 0;
  return selectedFile.value.size / 1024 / 1024;
});

const overSize = computed(() => {
  if (!selectedFile.value || !maxBytes.value) return false;
  return selectedFile.value.size > maxBytes.value;
});

const canSubmit = computed(
  () =>
    !uploading.value &&
    !!selectedFile.value &&
    allowed.value &&
    !overSize.value,
);

const blockingReason = computed(() => {
  if (!selectedFile.value) return "";
  if (!allowed.value) return "Неподдерживаемый тип файла";
  if (overSize.value) {
    return `Файл превышает допустимый лимит (${maxMb.value} МБ)`;
  }
  return "";
});

const displayedError = computed(
  () => inlineError.value || blockingReason.value,
);

watch(selectedFile, () => {
  inlineError.value = "";
});

function hasFiles(e) {
  const types = e.dataTransfer && e.dataTransfer.types;
  if (!types) return false;
  return Array.from(types).includes("Files");
}

function onDragEnter(e) {
  if (uploading.value) return;
  if (!hasFiles(e)) return;
  e.preventDefault();
  dragDepth += 1;
  dropActive.value = true;
}

function onDragOver(e) {
  if (uploading.value) return;
  if (!hasFiles(e)) return;
  e.preventDefault();
  if (e.dataTransfer) e.dataTransfer.dropEffect = "copy";
}

function onDragLeave(e) {
  if (uploading.value) return;
  if (!hasFiles(e)) return;
  e.preventDefault();
  dragDepth = Math.max(0, dragDepth - 1);
  if (dragDepth === 0) dropActive.value = false;
}

function onDrop(e) {
  if (uploading.value) return;
  e.preventDefault();
  dragDepth = 0;
  dropActive.value = false;
  const files = e.dataTransfer && e.dataTransfer.files;
  if (!files || files.length === 0) return;
  if (files.length > 1) {
    inlineError.value = "Можно загрузить только один файл за раз";
    return;
  }
  selectedFile.value = files[0];
}

function onSelect(event) {
  const file = event.files && event.files[0];
  selectedFile.value = file || null;
}

function onClear() {
  if (clearing.value) return;
  clearing.value = true;
  try {
    selectedFile.value = null;
    label.value = "";
    inlineError.value = "";
    progress.value = 0;
    dragDepth = 0;
    dropActive.value = false;
    fileUploadRef.value?.clear?.();
  } finally {
    clearing.value = false;
  }
}

function onCancel() {
  if (uploading.value) return;
  onClear();
  emit("update:visible", false);
}

async function onUpload() {
  if (!selectedFile.value) return;
  inlineError.value = "";

  if (!allowed.value) {
    inlineError.value = "Неподдерживаемый тип файла";
    return;
  }
  if (overSize.value) {
    inlineError.value = `Файл превышает допустимый лимит (${maxMb.value} МБ)`;
    return;
  }

  uploading.value = true;
  progress.value = 0;
  try {
    await uploadMeetingFile(props.meetingId, selectedFile.value, label.value, {
      onProgress: (p) => {
        progress.value = p;
      },
    });
    toast.add({
      severity: "success",
      summary: "Файл загружен",
      detail: selectedFile.value.name,
      life: 3000,
    });
    emit("uploaded");
    onClear();
    emit("update:visible", false);
  } catch (err) {
    if (err.errors && err.errors.file && err.errors.file[0]) {
      inlineError.value = err.errors.file[0];
    } else if (err.errors && err.errors.label && err.errors.label[0]) {
      inlineError.value = err.errors.label[0];
    } else {
      inlineError.value = err.message || "Не удалось загрузить файл";
    }
    toast.add({
      severity: "error",
      summary: "Ошибка загрузки",
      detail: inlineError.value,
      life: 4000,
    });
  } finally {
    uploading.value = false;
  }
}

onMounted(() => {
  const stopBrowserDefault = (e) => {
    if (hasFiles(e)) e.preventDefault();
  };
  document.addEventListener("dragover", stopBrowserDefault);
  document.addEventListener("drop", stopBrowserDefault);
  onBeforeUnmount(() => {
    document.removeEventListener("dragover", stopBrowserDefault);
    document.removeEventListener("drop", stopBrowserDefault);
  });
});
</script>

<template>
  <Dialog
    :visible="visible"
    @update:visible="(v) => emit('update:visible', v)"
    header="Загрузить файл"
    modal
    :closable="!uploading"
    :close-on-escape="!uploading"
    :style="{ width: '480px' }"
  >
    <div class="upload-dialog">
      <div
        class="dropzone"
        :class="{
          'dropzone--active': dropActive,
          'dropzone--disabled': uploading,
        }"
        @dragenter="onDragEnter"
        @dragover="onDragOver"
        @dragleave="onDragLeave"
        @drop="onDrop"
      >
        <div v-if="!selectedFile" class="dropzone-empty">
          <i class="pi pi-cloud-upload"></i>
          <span class="dropzone-hint">Перетащите файл сюда</span>
        </div>
        <div v-else class="dropzone-file">
          <i class="pi pi-file"></i>
          <span class="dropzone-name">{{ selectedFile.name }}</span>
        </div>
        <FileUpload
          ref="fileUploadRef"
          mode="basic"
          :auto="false"
          :custom-upload="true"
          :max-file-size="maxBytes || undefined"
          :choose-label="selectedFile ? 'Заменить файл' : 'Выберите файл'"
          choose-icon="pi pi-paperclip"
          :disabled="uploading"
          @select="onSelect"
          @clear="onClear"
        />
      </div>

      <div v-if="selectedFile" class="upload-meta">
        <span v-if="limitLabel" :class="{ 'upload-limit-error': !allowed }">
          {{ limitLabel }}
        </span>
        <span class="upload-size">{{ sizeMb.toFixed(1) }} МБ</span>
      </div>

      <Message
        v-if="displayedError"
        severity="error"
        :closable="false"
        class="upload-error"
      >
        {{ displayedError }}
      </Message>

      <div class="upload-label">
        <label for="file-label">Подпись (необязательно)</label>
        <InputText
          id="file-label"
          v-model="label"
          :disabled="uploading"
          maxlength="255"
          placeholder="Краткое описание"
          class="upload-label-input"
        />
      </div>

      <ProgressBar v-if="uploading" :value="progress" class="upload-progress" />
    </div>

    <template #footer>
      <Button
        label="Отмена"
        severity="secondary"
        text
        :disabled="uploading"
        @click="onCancel"
      />
      <Button
        label="Загрузить"
        icon="pi pi-upload"
        :disabled="!canSubmit"
        :loading="uploading"
        @click="onUpload"
      />
    </template>
  </Dialog>
</template>

<style scoped>
.upload-dialog {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}

.dropzone {
  position: relative;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 0.6rem;
  padding: 1.25rem 1rem;
  border: 2px dashed var(--p-content-border-color, #cbd5e1);
  border-radius: 8px;
  background: var(--p-surface-50, #f8fafc);
  transition:
    background 0.15s,
    border-color 0.15s;
  min-height: 120px;
}

.dropzone--active {
  border-color: var(--p-primary-color, #10b981);
  background: var(--p-primary-50, #ecfdf5);
}

.dropzone--disabled {
  opacity: 0.5;
  pointer-events: none;
}

.dropzone-empty,
.dropzone-file {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.35rem;
  color: var(--p-text-muted-color, #64748b);
  font-size: 0.85rem;
}

.dropzone-empty i,
.dropzone-file i {
  font-size: 1.6rem;
  color: var(--p-text-muted-color, #64748b);
}

.dropzone-name {
  max-width: 100%;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-weight: 500;
  color: var(--p-text-color, #1e293b);
}

.upload-meta {
  display: flex;
  justify-content: space-between;
  font-size: 0.8rem;
  color: var(--p-text-muted-color, #64748b);
}

.upload-limit-error {
  color: var(--p-red-500, #ef4444);
  font-weight: 500;
}

.upload-size {
  font-variant-numeric: tabular-nums;
}

.upload-error {
  margin: 0;
}

.upload-label {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
}

.upload-label label {
  font-size: 0.85rem;
  color: var(--p-text-muted-color, #64748b);
}

.upload-label-input {
  width: 100%;
}

.upload-progress {
  margin-top: 0.25rem;
}
</style>

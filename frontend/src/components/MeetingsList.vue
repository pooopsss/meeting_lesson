<script setup>
import { onMounted, ref } from "vue";
import Card from "primevue/card";
import Tag from "primevue/tag";
import Message from "primevue/message";
import ProgressSpinner from "primevue/progressspinner";
import Button from "primevue/button";
import MeetingFileList from "./MeetingFileList.vue";
import UploadFileDialog from "./UploadFileDialog.vue";
import { listMeetings } from "../api/meetings.js";

const meetings = ref([]);
const loading = ref(true);
const errorMessage = ref("");
const expandedId = ref(null);
const uploadMeetingId = ref(null);
const fileListKey = ref(0);

function formatDate(value) {
  if (!value) return "";
  const date = new Date(value.replace(" ", "T"));
  if (Number.isNaN(date.getTime())) return value;
  return date.toLocaleString(undefined, {
    day: "2-digit",
    month: "short",
    year: "numeric",
    hour: "2-digit",
    minute: "2-digit",
  });
}

async function load() {
  loading.value = true;
  errorMessage.value = "";
  try {
    const data = await listMeetings();
    meetings.value = [...data].sort((a, b) => b.id - a.id).slice(0, 3);
  } catch (err) {
    errorMessage.value = err.message || "Не удалось загрузить встречи";
  } finally {
    loading.value = false;
  }
}

function toggleFiles(meetingId) {
  expandedId.value = expandedId.value === meetingId ? null : meetingId;
}

function openUpload(meetingId) {
  uploadMeetingId.value = meetingId;
}

function onUploaded() {
  fileListKey.value += 1;
}

onMounted(load);
</script>

<template>
  <div class="meetings">
    <h3 class="meetings-title">Latest meetings</h3>

    <div v-if="loading" class="meetings-state">
      <ProgressSpinner style="width: 40px; height: 40px" strokeWidth="4" />
    </div>

    <Message v-else-if="errorMessage" severity="error" :closable="false">{{
      errorMessage
    }}</Message>

    <p v-else-if="meetings.length === 0" class="meetings-empty">
      Встреч пока нет.
    </p>

    <div v-else class="meetings-list">
      <Card v-for="meeting in meetings" :key="meeting.id" class="meeting-card">
        <template #title>
          <span class="meeting-name">{{ meeting.title }}</span>
        </template>
        <template #content>
          <Tag
            :value="formatDate(meeting.scheduled_at)"
            icon="pi pi-calendar"
            severity="info"
          />
          <p v-if="meeting.description" class="meeting-desc">
            {{ meeting.description }}
          </p>
          <div class="meeting-actions">
            <Button
              :label="
                expandedId === meeting.id ? 'Скрыть файлы' : 'Показать файлы'
              "
              :icon="
                expandedId === meeting.id
                  ? 'pi pi-chevron-up'
                  : 'pi pi-paperclip'
              "
              size="small"
              severity="secondary"
              text
              @click="toggleFiles(meeting.id)"
            />
            <Button
              label="Загрузить файл"
              icon="pi pi-upload"
              size="small"
              severity="primary"
              @click="openUpload(meeting.id)"
            />
          </div>
          <MeetingFileList
            v-if="expandedId === meeting.id"
            :key="`${meeting.id}-${fileListKey}`"
            :meeting-id="meeting.id"
          />
        </template>
      </Card>
    </div>

    <UploadFileDialog
      v-if="uploadMeetingId != null"
      :visible="uploadMeetingId != null"
      :meeting-id="uploadMeetingId"
      @update:visible="
        (v) => {
          if (!v) uploadMeetingId = null;
        }
      "
      @uploaded="onUploaded"
    />
  </div>
</template>

<style scoped>
.meetings {
  margin-top: 1.5rem;
}

.meetings-title {
  margin: 0 0 0.75rem;
  font-size: 1rem;
}

.meetings-state {
  display: flex;
  justify-content: center;
  padding: 1rem 0;
}

.meetings-empty {
  color: var(--p-text-muted-color, #64748b);
  margin: 0;
}

.meetings-list {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}

.meeting-card {
  border: 1px solid var(--p-content-border-color, #e2e8f0);
}

.meeting-name {
  font-size: 1rem;
}

.meeting-desc {
  margin: 0.75rem 0 0;
  font-size: 0.9rem;
  color: var(--p-text-muted-color, #64748b);
}

.meeting-actions {
  display: flex;
  gap: 0.5rem;
  flex-wrap: wrap;
  margin-top: 0.75rem;
}
</style>

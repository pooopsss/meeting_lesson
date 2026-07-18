<script setup>
import { computed, onBeforeUnmount, reactive, ref, watch } from "vue";
import Avatar from "primevue/avatar";
import Button from "primevue/button";
import Dialog from "primevue/dialog";
import FileUpload from "primevue/fileupload";
import InputText from "primevue/inputtext";
import Message from "primevue/message";
import Password from "primevue/password";
import ProgressBar from "primevue/progressbar";
import TabView from "primevue/tabview";
import TabPanel from "primevue/tabpanel";
import { useToast } from "primevue/usetoast";
import {
  changePassword,
  deleteAvatar,
  updateMe,
  uploadAvatar,
} from "../api/me.js";
import { auth, updateUserLocal } from "../store/auth.js";

const props = defineProps({
  visible: { type: Boolean, required: true },
});

const emit = defineEmits(["update:visible"]);

const toast = useToast();

const ALLOWED_AVATAR_TYPES = ["image/jpeg", "image/png", "image/webp"];
const MAX_AVATAR_BYTES = 2 * 1024 * 1024;

const activeIndex = ref(0);

const basicForm = reactive({
  name: "",
  phone: "",
});
const basicLoading = ref(false);
const basicError = ref("");
const basicFieldErrors = ref({});

const passwordForm = reactive({
  current_password: "",
  new_password: "",
  new_password_confirmation: "",
});
const passwordLoading = ref(false);
const passwordError = ref("");
const passwordFieldErrors = ref({});

const avatarInputRef = ref(null);
const avatarFile = ref(null);
const avatarPreviewUrl = ref(null);
const avatarError = ref("");
const avatarProgress = ref(0);
const avatarUploading = ref(false);
const avatarDeleting = ref(false);

function resetFeedback() {
  basicError.value = "";
  basicFieldErrors.value = {};
  passwordError.value = "";
  passwordFieldErrors.value = {};
}

function syncBasicForm() {
  basicForm.name = auth.user?.name || "";
  basicForm.phone = auth.user?.phone || "";
}

watch(
  () => props.visible,
  (v) => {
    if (v) {
      syncBasicForm();
      resetFeedback();
      clearAvatar();
      passwordForm.current_password = "";
      passwordForm.new_password = "";
      passwordForm.new_password_confirmation = "";
    }
  },
  { immediate: true },
);

const initials = computed(() => {
  const fromUser = auth.user?.initials;
  if (fromUser) return fromUser;
  const email = auth.user?.email || "";
  return email.charAt(0).toUpperCase();
});

const avatarColor = computed(() => auth.user?.color || "#64748b");
const currentAvatarUrl = computed(() => auth.user?.avatar_url || null);
const currentAvatarFailed = ref(false);
const currentAvatarSrc = computed(() => {
  if (currentAvatarFailed.value) return null;
  return currentAvatarUrl.value;
});
watch(currentAvatarUrl, () => {
  currentAvatarFailed.value = false;
});

const initialsStyle = computed(() => ({
  backgroundColor: avatarColor.value,
  color: "#fff",
  fontWeight: 600,
}));

const canSubmitBasic = computed(
  () => !basicLoading.value && basicForm.name.trim().length > 0,
);

const canSubmitPassword = computed(
  () =>
    !passwordLoading.value &&
    passwordForm.current_password.length > 0 &&
    passwordForm.new_password.length >= 6 &&
    passwordForm.new_password ===
      passwordForm.new_password_confirmation,
);

function fieldError(errorsRef, key) {
  const errors = errorsRef.value;
  if (!errors) return null;
  const err = errors[key];
  return Array.isArray(err) ? err[0] : err;
}

function pickError(err, fallback) {
  if (err && err.errors) {
    const firstKey = Object.keys(err.errors)[0];
    if (firstKey) {
      const val = err.errors[firstKey];
      if (Array.isArray(val) && val[0]) return val[0];
    }
  }
  return (err && err.message) || fallback;
}

function onDialogHide(v) {
  emit("update:visible", v);
}

async function onSaveBasic() {
  basicError.value = "";
  basicFieldErrors.value = {};
  if (!canSubmitBasic.value) {
    basicError.value = "Имя обязательно";
    return;
  }
  basicLoading.value = true;
  try {
    const user = await updateMe({
      name: basicForm.name.trim(),
      phone: basicForm.phone.trim() || null,
    });
    updateUserLocal(user);
    toast.add({
      severity: "success",
      summary: "Профиль обновлён",
      life: 2500,
    });
  } catch (err) {
    if (err && err.errors) basicFieldErrors.value = err.errors;
    basicError.value = pickError(err, "Не удалось сохранить профиль");
  } finally {
    basicLoading.value = false;
  }
}

async function onChangePassword() {
  passwordError.value = "";
  passwordFieldErrors.value = {};
  if (!canSubmitPassword.value) {
    passwordError.value = "Заполните все поля корректно";
    return;
  }
  passwordLoading.value = true;
  try {
    await changePassword({
      current_password: passwordForm.current_password,
      new_password: passwordForm.new_password,
      new_password_confirmation: passwordForm.new_password_confirmation,
    });
    passwordForm.current_password = "";
    passwordForm.new_password = "";
    passwordForm.new_password_confirmation = "";
    toast.add({
      severity: "success",
      summary: "Пароль изменён",
      life: 2500,
    });
  } catch (err) {
    if (err && err.errors) passwordFieldErrors.value = err.errors;
    passwordError.value = pickError(err, "Не удалось сменить пароль");
  } finally {
    passwordLoading.value = false;
  }
}

function revokePreview() {
  if (avatarPreviewUrl.value) {
    URL.revokeObjectURL(avatarPreviewUrl.value);
    avatarPreviewUrl.value = null;
  }
}

function clearAvatar() {
  revokePreview();
  avatarFile.value = null;
  avatarError.value = "";
  avatarProgress.value = 0;
  avatarInputRef.value?.clear?.();
}

function onAvatarSelect(event) {
  const file = event.files && event.files[0];
  if (!file) return;
  setAvatarFile(file);
}

function setAvatarFile(file) {
  avatarError.value = "";
  if (!ALLOWED_AVATAR_TYPES.includes(file.type)) {
    avatarError.value = "Допустимы только JPG, PNG или WebP";
    return;
  }
  if (file.size > MAX_AVATAR_BYTES) {
    avatarError.value = "Файл слишком большой. Максимальный размер — 2 МБ";
    return;
  }
  revokePreview();
  avatarFile.value = file;
  avatarPreviewUrl.value = URL.createObjectURL(file);
}

function onAvatarClear() {
  clearAvatar();
}

async function onUploadAvatar() {
  if (!avatarFile.value) return;
  avatarError.value = "";
  avatarUploading.value = true;
  avatarProgress.value = 0;
  try {
    const user = await uploadAvatar(avatarFile.value, {
      onProgress: (p) => {
        avatarProgress.value = p;
      },
    });
    updateUserLocal(user);
    toast.add({
      severity: "success",
      summary: "Аватарка обновлена",
      life: 2500,
    });
    clearAvatar();
  } catch (err) {
    avatarError.value = pickError(err, "Не удалось загрузить аватарку");
  } finally {
    avatarUploading.value = false;
  }
}

async function onDeleteAvatar() {
  if (avatarDeleting.value) return;
  avatarError.value = "";
  avatarDeleting.value = true;
  try {
    const user = await deleteAvatar();
    updateUserLocal(user);
    toast.add({
      severity: "success",
      summary: "Аватарка удалена",
      life: 2500,
    });
  } catch (err) {
    avatarError.value = pickError(err, "Не удалось удалить аватарку");
  } finally {
    avatarDeleting.value = false;
  }
}

onBeforeUnmount(() => {
  revokePreview();
});
</script>

<template>
  <Dialog
    :visible="visible"
    @update:visible="onDialogHide"
    header="Профиль"
    modal
    :style="{ width: '520px' }"
  >
    <TabView v-model:activeIndex="activeIndex" class="profile-tabs">
      <TabPanel header="Основное">
        <div class="profile-section">
          <div class="profile-field">
            <label for="profile-name">Имя</label>
            <InputText
              id="profile-name"
              v-model="basicForm.name"
              :invalid="!!fieldError(basicFieldErrors, 'name')"
              :disabled="basicLoading"
              maxlength="255"
              fluid
            />
            <small
              v-if="fieldError(basicFieldErrors, 'name')"
              class="profile-error"
            >
              {{ fieldError(basicFieldErrors, "name") }}
            </small>
          </div>

          <div class="profile-field">
            <label for="profile-email">Email</label>
            <InputText
              id="profile-email"
              :model-value="auth.user?.email || ''"
              disabled
              fluid
            />
          </div>

          <div class="profile-field">
            <label for="profile-phone">Телефон</label>
            <InputText
              id="profile-phone"
              v-model="basicForm.phone"
              :invalid="!!fieldError(basicFieldErrors, 'phone')"
              :disabled="basicLoading"
              maxlength="20"
              fluid
            />
            <small
              v-if="fieldError(basicFieldErrors, 'phone')"
              class="profile-error"
            >
              {{ fieldError(basicFieldErrors, "phone") }}
            </small>
          </div>

          <Message
            v-if="basicError"
            severity="error"
            :closable="false"
            class="profile-feedback"
          >
            {{ basicError }}
          </Message>

          <div class="profile-actions">
            <Button
              label="Сохранить"
              icon="pi pi-check"
              :disabled="!canSubmitBasic"
              :loading="basicLoading"
              @click="onSaveBasic"
            />
          </div>
        </div>
      </TabPanel>

      <TabPanel header="Аватарка">
        <div class="profile-section">
          <div class="profile-avatar-preview">
            <Avatar
              v-if="avatarPreviewUrl"
              :image="avatarPreviewUrl"
              shape="circle"
              size="xlarge"
            />
            <Avatar
              v-else-if="currentAvatarSrc"
              :image="currentAvatarSrc"
              shape="circle"
              size="xlarge"
              @error="currentAvatarFailed = true"
            />
            <Avatar
              v-else
              :label="initials"
              shape="circle"
              size="xlarge"
              :style="initialsStyle"
            />
          </div>

          <div class="profile-avatar-controls">
            <FileUpload
              ref="avatarInputRef"
              mode="basic"
              :auto="false"
              :custom-upload="true"
              accept="image/jpeg,image/png,image/webp"
              :max-file-size="MAX_AVATAR_BYTES"
              choose-label="Выберите файл"
              choose-icon="pi pi-paperclip"
              :disabled="avatarUploading"
              @select="onAvatarSelect"
              @clear="onAvatarClear"
            />
            <span class="profile-avatar-hint">JPG, PNG или WebP, до 2 МБ</span>
          </div>

          <div
            v-if="avatarFile"
            class="profile-avatar-selected"
          >
            <span class="profile-avatar-filename">{{ avatarFile.name }}</span>
            <Button
              icon="pi pi-times"
              severity="secondary"
              text
              :disabled="avatarUploading"
              @click="clearAvatar"
            />
          </div>

          <ProgressBar
            v-if="avatarUploading"
            :value="avatarProgress"
            class="profile-progress"
          />

          <Message
            v-if="avatarError"
            severity="error"
            :closable="false"
            class="profile-feedback"
          >
            {{ avatarError }}
          </Message>

          <div class="profile-actions">
            <Button
              label="Загрузить"
              icon="pi pi-upload"
              :disabled="!avatarFile || avatarUploading"
              :loading="avatarUploading"
              @click="onUploadAvatar"
            />
            <Button
              v-if="currentAvatarUrl && !avatarFile"
              label="Удалить"
              icon="pi pi-trash"
              severity="danger"
              outlined
              :loading="avatarDeleting"
              :disabled="avatarUploading || avatarDeleting"
              @click="onDeleteAvatar"
            />
          </div>
        </div>
      </TabPanel>

      <TabPanel header="Безопасность">
        <div class="profile-section">
          <div class="profile-field">
            <label for="profile-current-pw">Текущий пароль</label>
            <Password
              id="profile-current-pw"
              v-model="passwordForm.current_password"
              :feedback="false"
              toggleMask
              :invalid="!!fieldError(passwordFieldErrors, 'current_password')"
              :disabled="passwordLoading"
              fluid
            />
            <small
              v-if="fieldError(passwordFieldErrors, 'current_password')"
              class="profile-error"
            >
              {{ fieldError(passwordFieldErrors, "current_password") }}
            </small>
          </div>

          <div class="profile-field">
            <label for="profile-new-pw">Новый пароль</label>
            <Password
              id="profile-new-pw"
              v-model="passwordForm.new_password"
              :feedback="false"
              toggleMask
              :invalid="!!fieldError(passwordFieldErrors, 'new_password')"
              :disabled="passwordLoading"
              fluid
            />
            <small
              v-if="fieldError(passwordFieldErrors, 'new_password')"
              class="profile-error"
            >
              {{ fieldError(passwordFieldErrors, "new_password") }}
            </small>
          </div>

          <div class="profile-field">
            <label for="profile-confirm-pw">Подтверждение</label>
            <Password
              id="profile-confirm-pw"
              v-model="passwordForm.new_password_confirmation"
              :feedback="false"
              toggleMask
              :invalid="!!fieldError(passwordFieldErrors, 'new_password_confirmation')"
              :disabled="passwordLoading"
              fluid
            />
            <small
              v-if="fieldError(passwordFieldErrors, 'new_password_confirmation')"
              class="profile-error"
            >
              {{ fieldError(passwordFieldErrors, "new_password_confirmation") }}
            </small>
          </div>

          <Message
            v-if="passwordError"
            severity="error"
            :closable="false"
            class="profile-feedback"
          >
            {{ passwordError }}
          </Message>

          <div class="profile-actions">
            <Button
              label="Сменить пароль"
              icon="pi pi-key"
              :disabled="!canSubmitPassword"
              :loading="passwordLoading"
              @click="onChangePassword"
            />
          </div>
        </div>
      </TabPanel>
    </TabView>
  </Dialog>
</template>

<style scoped>
.profile-tabs :deep(.p-tabview-nav) {
  margin-bottom: 1rem;
}

.profile-section {
  display: flex;
  flex-direction: column;
  gap: 0.85rem;
  padding-top: 0.25rem;
}

.profile-field {
  display: flex;
  flex-direction: column;
  gap: 0.3rem;
}

.profile-field label {
  font-size: 0.85rem;
  font-weight: 600;
  color: var(--p-text-muted-color, #64748b);
}

.profile-error {
  color: var(--p-red-500, #ef4444);
  font-size: 0.8rem;
}

.profile-feedback {
  margin: 0;
}

.profile-actions {
  display: flex;
  gap: 0.5rem;
  justify-content: flex-end;
  flex-wrap: wrap;
}

.profile-avatar-preview {
  display: flex;
  justify-content: center;
  padding: 0.5rem 0 0.25rem;
}

.profile-avatar-controls {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.35rem;
}

.profile-avatar-hint {
  font-size: 0.8rem;
  color: var(--p-text-muted-color, #64748b);
}

.profile-avatar-selected {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.4rem 0.6rem;
  background: var(--p-surface-50, #f8fafc);
  border: 1px solid var(--p-content-border-color, #e2e8f0);
  border-radius: 6px;
}

.profile-avatar-filename {
  flex: 1;
  font-size: 0.85rem;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.profile-progress {
  margin: 0;
}
</style>

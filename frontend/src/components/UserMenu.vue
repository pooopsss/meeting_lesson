<script setup>
import { computed, ref, watch } from "vue";
import Avatar from "primevue/avatar";
import Menu from "primevue/menu";
import Button from "primevue/button";
import { auth } from "../store/auth.js";

const emit = defineEmits(["open-profile", "logout"]);

const menuRef = ref(null);
const avatarFailed = ref(false);

const displayName = computed(() => {
  const name = auth.user?.name;
  if (name && name.trim()) return name;
  return auth.user?.email || "";
});

const initials = computed(() => {
  const fromUser = auth.user?.initials;
  if (fromUser) return fromUser;
  const email = auth.user?.email || "";
  return email.charAt(0).toUpperCase();
});

const avatarColor = computed(() => auth.user?.color || "#64748b");

const avatarUrl = computed(() => {
  if (avatarFailed.value) return null;
  return auth.user?.avatar_url || null;
});

watch(
  () => auth.user?.avatar_url,
  () => {
    avatarFailed.value = false;
  },
);

const initialsStyle = computed(() => ({
  backgroundColor: avatarColor.value,
  color: "#fff",
  fontWeight: 600,
}));

const items = computed(() => [
  {
    label: "Профиль",
    icon: "pi pi-user",
    command: () => emit("open-profile"),
  },
  {
    label: "Выйти",
    icon: "pi pi-sign-out",
    command: () => emit("logout"),
  },
]);

function toggleMenu(event) {
  menuRef.value?.toggle(event);
}

function onAvatarError() {
  avatarFailed.value = true;
}
</script>

<template>
  <div class="user-menu">
    <Button
      class="user-menu-trigger"
      severity="secondary"
      text
      :pt="{
        root: { class: 'user-menu-trigger-root', 'aria-label': 'Меню пользователя' },
      }"
      @click="toggleMenu"
    >
      <template #default>
        <span class="user-menu-avatar">
          <Avatar
            v-if="avatarUrl"
            :image="avatarUrl"
            shape="circle"
            size="normal"
            @error="onAvatarError"
          />
          <Avatar
            v-else
            :label="initials"
            shape="circle"
            size="normal"
            :style="initialsStyle"
          />
        </span>
        <span class="user-menu-name">{{ displayName }}</span>
        <i class="pi pi-chevron-down user-menu-caret" aria-hidden="true" />
      </template>
    </Button>
    <Menu ref="menuRef" :model="items" :popup="true" />
  </div>
</template>

<style scoped>
.user-menu {
  display: inline-flex;
  align-items: center;
}

.user-menu-trigger :deep(.user-menu-trigger-root) {
  display: inline-flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.25rem 0.5rem;
}

.user-menu-avatar {
  display: inline-flex;
  align-items: center;
  justify-content: center;
}

.user-menu-name {
  font-size: 0.95rem;
  font-weight: 500;
  color: var(--p-text-color, #1e293b);
  max-width: 180px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.user-menu-caret {
  font-size: 0.7rem;
  color: var(--p-text-muted-color, #64748b);
}
</style>

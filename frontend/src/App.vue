<script setup>
import { onMounted, ref, watch } from "vue";
import Card from "primevue/card";
import Toast from "primevue/toast";
import ConfirmDialog from "primevue/confirmdialog";
import { useConfirm } from "primevue/useconfirm";
import AuthForm from "./components/AuthForm.vue";
import MeetingsList from "./components/MeetingsList.vue";
import UserMenu from "./components/UserMenu.vue";
import ProfileView from "./components/ProfileView.vue";
import {
  auth,
  isAuthenticated,
  logout as authLogout,
  refreshMe,
} from "./store/auth.js";

const view = ref("dashboard");
const profileVisible = ref(false);
const confirm = useConfirm();

function openProfile() {
  view.value = "profile";
  profileVisible.value = true;
}

function closeProfile() {
  profileVisible.value = false;
  if (view.value === "profile") view.value = "dashboard";
}

function askLogout() {
  confirm.require({
    message: "Выйти из аккаунта?",
    header: "Подтверждение",
    icon: "pi pi-sign-out",
    acceptLabel: "Выйти",
    rejectLabel: "Отмена",
    acceptProps: { severity: "danger" },
    accept: async () => {
      await authLogout();
      view.value = "dashboard";
      profileVisible.value = false;
    },
  });
}

watch(
  () => isAuthenticated(),
  (authed) => {
    if (!authed) {
      view.value = "dashboard";
      profileVisible.value = false;
    }
  },
);

onMounted(() => {
  if (isAuthenticated()) {
    refreshMe();
  }
});
</script>

<template>
  <div class="app-container">
    <Toast position="top-right" />
    <ConfirmDialog />
    <AuthForm v-if="!isAuthenticated()" />

    <Card v-else class="dashboard-card">
      <template #title>
        <div class="dashboard-header">
          <span class="user-email">
            <i class="pi pi-user"></i>
            {{ auth.user?.email }}
          </span>
          <UserMenu @open-profile="openProfile" @logout="askLogout" />
        </div>
      </template>
      <template #content>
        <MeetingsList v-if="view === 'dashboard'" />
      </template>
    </Card>

    <ProfileView
      v-if="isAuthenticated()"
      :visible="profileVisible"
      @update:visible="(v) => v || closeProfile()"
    />
  </div>
</template>

<style scoped>
.app-container {
  min-height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 1rem;
}

.dashboard-card {
  width: 100%;
  max-width: 480px;
}

.dashboard-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
}

.user-email {
  display: inline-flex;
  align-items: center;
  gap: 0.5rem;
  font-size: 1rem;
  font-weight: 600;
}
</style>

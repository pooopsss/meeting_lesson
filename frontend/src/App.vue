<script setup>
import Card from "primevue/card";
import Button from "primevue/button";
import Toast from "primevue/toast";
import ConfirmDialog from "primevue/confirmdialog";
import AuthForm from "./components/AuthForm.vue";
import MeetingsList from "./components/MeetingsList.vue";
import { auth, clearSession, isAuthenticated } from "./store/auth.js";
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
          <Button
            label="Log out"
            icon="pi pi-sign-out"
            severity="secondary"
            size="small"
            @click="clearSession"
          />
        </div>
      </template>
      <template #content>
        <MeetingsList />
      </template>
    </Card>
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

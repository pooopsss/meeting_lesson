<script setup>
import { reactive, ref } from "vue";
import Card from "primevue/card";
import TabView from "primevue/tabview";
import TabPanel from "primevue/tabpanel";
import InputText from "primevue/inputtext";
import Password from "primevue/password";
import Button from "primevue/button";
import Message from "primevue/message";
import { login, register } from "../api/auth.js";
import { setSession } from "../store/auth.js";

const activeIndex = ref(0);

const loginForm = reactive({ email: "", password: "" });
const registerForm = reactive({
  email: "",
  password: "",
  password_confirmation: "",
});

const loading = ref(false);
const errorMessage = ref("");
const fieldErrors = ref({});

function resetFeedback() {
  errorMessage.value = "";
  fieldErrors.value = {};
}

function handleError(err) {
  if (err.errors) {
    fieldErrors.value = err.errors;
  }
  errorMessage.value =
    err.message ||
    (err.status === 401 ? "Неверный email или пароль" : "Что-то пошло не так");
}

async function submitLogin() {
  resetFeedback();
  loading.value = true;
  try {
    const data = await login({ ...loginForm });
    setSession(data);
  } catch (err) {
    handleError(err);
  } finally {
    loading.value = false;
  }
}

async function submitRegister() {
  resetFeedback();
  loading.value = true;
  try {
    const data = await register({ ...registerForm });
    setSession(data);
  } catch (err) {
    handleError(err);
  } finally {
    loading.value = false;
  }
}

function fieldError(field) {
  const err = fieldErrors.value[field];
  return Array.isArray(err) ? err[0] : err;
}
</script>

<template>
  <Card class="auth-card">
    <template #title>Welcome</template>
    <template #content>
      <TabView
        v-model:activeIndex="activeIndex"
        @update:activeIndex="resetFeedback"
      >
        <TabPanel header="Login">
          <form class="auth-form" @submit.prevent="submitLogin">
            <div class="field">
              <label for="login-email">Email</label>
              <InputText
                id="login-email"
                v-model="loginForm.email"
                type="email"
                autocomplete="email"
                :invalid="!!fieldError('email')"
                fluid
              />
              <small v-if="fieldError('email')" class="error-text">{{
                fieldError("email")
              }}</small>
            </div>

            <div class="field">
              <label for="login-password">Password</label>
              <Password
                id="login-password"
                v-model="loginForm.password"
                :feedback="false"
                toggleMask
                :invalid="!!fieldError('password')"
                fluid
              />
              <small v-if="fieldError('password')" class="error-text">{{
                fieldError("password")
              }}</small>
            </div>

            <Message v-if="errorMessage" severity="error" :closable="false">{{
              errorMessage
            }}</Message>

            <Button type="submit" label="Sign in" :loading="loading" fluid />
          </form>
        </TabPanel>

        <TabPanel header="Register">
          <form class="auth-form" @submit.prevent="submitRegister">
            <div class="field">
              <label for="register-email">Email</label>
              <InputText
                id="register-email"
                v-model="registerForm.email"
                type="email"
                autocomplete="email"
                :invalid="!!fieldError('email')"
                fluid
              />
              <small v-if="fieldError('email')" class="error-text">{{
                fieldError("email")
              }}</small>
            </div>

            <div class="field">
              <label for="register-password">Password</label>
              <Password
                id="register-password"
                v-model="registerForm.password"
                toggleMask
                :invalid="!!fieldError('password')"
                fluid
              />
              <small v-if="fieldError('password')" class="error-text">{{
                fieldError("password")
              }}</small>
            </div>

            <div class="field">
              <label for="register-password-confirm">Confirm password</label>
              <Password
                id="register-password-confirm"
                v-model="registerForm.password_confirmation"
                :feedback="false"
                toggleMask
                fluid
              />
            </div>

            <Message v-if="errorMessage" severity="error" :closable="false">{{
              errorMessage
            }}</Message>

            <Button
              type="submit"
              label="Create account"
              :loading="loading"
              fluid
            />
          </form>
        </TabPanel>
      </TabView>
    </template>
  </Card>
</template>

<style scoped>
.auth-card {
  width: 100%;
  max-width: 420px;
}

.auth-form {
  display: flex;
  flex-direction: column;
  gap: 1.25rem;
  padding-top: 0.5rem;
}

.field {
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
}

.field label {
  font-weight: 600;
  font-size: 0.9rem;
}

.error-text {
  color: var(--p-red-500, #ef4444);
  font-size: 0.8rem;
}
</style>

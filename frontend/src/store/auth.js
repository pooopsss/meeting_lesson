import { reactive, readonly } from "vue";
import { getMe, logout as apiLogout } from "../api/me.js";

const TOKEN_KEY = "auth_token";
const USER_KEY = "auth_user";

const state = reactive({
  token: localStorage.getItem(TOKEN_KEY) || null,
  user: JSON.parse(localStorage.getItem(USER_KEY) || "null"),
});

export const auth = readonly(state);

export function setSession({ token, user }) {
  state.token = token;
  state.user = user;
  localStorage.setItem(TOKEN_KEY, token);
  if (user) {
    localStorage.setItem(USER_KEY, JSON.stringify(user));
  } else {
    localStorage.removeItem(USER_KEY);
  }
}

export function clearSession() {
  state.token = null;
  state.user = null;
  localStorage.removeItem(TOKEN_KEY);
  localStorage.removeItem(USER_KEY);
}

export function isAuthenticated() {
  return !!state.token;
}

export function updateUserLocal(partial) {
  if (!state.user) return;
  state.user = { ...state.user, ...partial };
  localStorage.setItem(USER_KEY, JSON.stringify(state.user));
}

let refreshInFlight = null;

export function refreshMe() {
  if (!state.token) return Promise.resolve(null);
  if (refreshInFlight) return refreshInFlight;
  refreshInFlight = (async () => {
    try {
      const user = await getMe();
      state.user = user;
      localStorage.setItem(USER_KEY, JSON.stringify(user));
      return user;
    } catch (_) {
      return null;
    } finally {
      refreshInFlight = null;
    }
  })();
  return refreshInFlight;
}

export async function logout() {
  if (state.token) {
    try {
      await apiLogout();
    } catch (_) {
      // ignore — очищаем сессию в любом случае
    }
  }
  clearSession();
}

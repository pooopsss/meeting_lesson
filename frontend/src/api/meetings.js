import { auth, clearSession } from "../store/auth.js";

const API_URL = import.meta.env.VITE_API_URL || "http://localhost:8081/api";

async function request(path, options = {}) {
  const response = await fetch(`${API_URL}${path}`, {
    ...options,
    headers: {
      Accept: "application/json",
      Authorization: auth.token ? `Bearer ${auth.token}` : "",
      ...(options.headers || {}),
    },
  });

  const data = await response.json().catch(() => ({}));

  if (response.status === 401) {
    clearSession();
  }

  if (!response.ok) {
    throw {
      status: response.status,
      message: data.message || "Request failed",
      errors: data.errors || null,
    };
  }

  return data;
}

export function listMeetings() {
  return request("/meetings");
}

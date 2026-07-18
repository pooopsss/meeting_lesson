import { auth, clearSession } from "../store/auth.js";

const API_URL = import.meta.env.VITE_API_URL || "http://localhost:8081/api";

async function authedRequest(path, options = {}) {
  const response = await fetch(`${API_URL}${path}`, {
    ...options,
    headers: {
      Accept: "application/json",
      Authorization: auth.token ? `Bearer ${auth.token}` : "",
      ...(options.headers || {}),
    },
  });

  if (response.status === 401) {
    clearSession();
  }

  const data = await response.json().catch(() => ({}));

  if (!response.ok) {
    throw {
      status: response.status,
      message: data.message || "Request failed",
      errors: data.errors || null,
    };
  }

  return data;
}

export function getMe() {
  return authedRequest("/me");
}

export function updateMe(payload) {
  return authedRequest("/me", {
    method: "PATCH",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify(payload),
  });
}

export function deleteAvatar() {
  return authedRequest("/me/avatar", { method: "DELETE" });
}

export function changePassword(payload) {
  return authedRequest("/me/password", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify(payload),
  });
}

export function logout() {
  return authedRequest("/logout", { method: "POST" });
}

export function uploadAvatar(file, { onProgress } = {}) {
  return new Promise((resolve, reject) => {
    const xhr = new XMLHttpRequest();
    const form = new FormData();
    form.append("avatar", file);

    xhr.open("POST", `${API_URL}/me/avatar`);
    xhr.setRequestHeader("Accept", "application/json");
    if (auth.token) {
      xhr.setRequestHeader("Authorization", `Bearer ${auth.token}`);
    }

    if (typeof onProgress === "function") {
      xhr.upload.addEventListener("progress", (event) => {
        if (event.lengthComputable) {
          onProgress(Math.round((event.loaded / event.total) * 100));
        }
      });
    }

    xhr.addEventListener("load", () => {
      if (xhr.status === 401) {
        clearSession();
      }
      let data = {};
      try {
        data = JSON.parse(xhr.responseText || "{}");
      } catch (_) {
        // not JSON
      }
      if (xhr.status >= 200 && xhr.status < 300) {
        resolve(data);
      } else {
        reject({
          status: xhr.status,
          message: data.message || "Upload failed",
          errors: data.errors || null,
        });
      }
    });

    xhr.addEventListener("error", () => {
      reject({ status: 0, message: "Network error" });
    });

    xhr.addEventListener("abort", () => {
      reject({ status: 0, message: "aborted" });
    });

    xhr.send(form);
  });
}

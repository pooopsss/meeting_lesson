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

  const contentType = response.headers.get("Content-Type") || "";
  const data = contentType.includes("application/json")
    ? await response.json().catch(() => ({}))
    : await response.blob().catch(() => null);

  if (!response.ok) {
    throw {
      status: response.status,
      message: (data && data.message) || "Request failed",
      errors: (data && data.errors) || null,
    };
  }

  return data;
}

export function listMeetingFiles(meetingId) {
  return authedRequest(`/meetings/${meetingId}/files`);
}

export function uploadMeetingFile(meetingId, file, label, { onProgress } = {}) {
  return new Promise((resolve, reject) => {
    const xhr = new XMLHttpRequest();
    const form = new FormData();
    form.append("file", file);
    if (label && label.trim()) {
      form.append("label", label.trim());
    }

    xhr.open("POST", `${API_URL}/meetings/${meetingId}/files`);
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

export async function downloadMeetingFile(meetingId, fileId) {
  const response = await fetch(
    `${API_URL}/meetings/${meetingId}/files/${fileId}`,
    {
      headers: {
        Accept: "*/*",
        Authorization: auth.token ? `Bearer ${auth.token}` : "",
      },
    },
  );

  if (response.status === 401) {
    clearSession();
  }

  if (!response.ok) {
    let message = "Download failed";
    try {
      const data = await response.json();
      message = data.message || message;
    } catch (_) {
      // not JSON
    }
    throw { status: response.status, message };
  }

  const disposition = response.headers.get("Content-Disposition") || "";
  const blob = await response.blob();
  const filename = parseFilename(disposition) || `file-${fileId}`;

  triggerBrowserDownload(blob, filename);

  return { filename, size: blob.size };
}

export async function fetchMeetingFileBlob(meetingId, fileId) {
  const response = await fetch(
    `${API_URL}/meetings/${meetingId}/files/${fileId}`,
    {
      headers: {
        Accept: "*/*",
        Authorization: auth.token ? `Bearer ${auth.token}` : "",
      },
    },
  );

  if (response.status === 401) {
    clearSession();
  }

  if (!response.ok) {
    let message = "Failed to load media";
    try {
      const data = await response.json();
      message = data.message || message;
    } catch (_) {
      // not JSON
    }
    throw { status: response.status, message };
  }

  return response.blob();
}

export async function deleteMeetingFile(meetingId, fileId) {
  return authedRequest(`/meetings/${meetingId}/files/${fileId}`, {
    method: "DELETE",
  });
}

function parseFilename(disposition) {
  if (!disposition) return null;
  const utf8Match = disposition.match(/filename\*=UTF-8''([^;]+)/i);
  if (utf8Match) {
    try {
      return decodeURIComponent(utf8Match[1]);
    } catch (_) {
      return utf8Match[1];
    }
  }
  const quoted = disposition.match(/filename="([^"]+)"/i);
  if (quoted) return quoted[1];
  const plain = disposition.match(/filename=([^;]+)/i);
  if (plain) return plain[1].trim().replace(/^["']|["']$/g, "");
  return null;
}

function triggerBrowserDownload(blob, filename) {
  const url = URL.createObjectURL(blob);
  const a = document.createElement("a");
  a.href = url;
  a.download = filename;
  document.body.appendChild(a);
  a.click();
  document.body.removeChild(a);
  setTimeout(() => URL.revokeObjectURL(url), 1000);
}

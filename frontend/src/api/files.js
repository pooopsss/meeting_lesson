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

const API_URL = import.meta.env.VITE_API_URL || "http://localhost:8081/api";

async function request(path, body) {
  const response = await fetch(`${API_URL}${path}`, {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      Accept: "application/json",
    },
    body: JSON.stringify(body),
  });

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

export function register(payload) {
  return request("/register", payload);
}

export function login(payload) {
  return request("/login", payload);
}

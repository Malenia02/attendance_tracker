const API_BASE = (import.meta.env.VITE_API_URL || "/api").replace(/\/$/, "");
const USER_KEY = "dilg_auth_user";

export function getStoredUser() {
  const value = sessionStorage.getItem(USER_KEY);

  if (!value) return null;

  try {
    return JSON.parse(value);
  } catch {
    clearAuth();
    return null;
  }
}

export function storeAuth(user) {
  clearAuth();
  sessionStorage.setItem(USER_KEY, JSON.stringify(user));
}

export function updateStoredUser(user) {
  sessionStorage.setItem(USER_KEY, JSON.stringify(user));
}

export function clearAuth() {
  localStorage.removeItem(USER_KEY);
  sessionStorage.removeItem(USER_KEY);
}

function xsrfToken() {
  const pair = document.cookie
    .split("; ")
    .find((row) => row.startsWith("XSRF-TOKEN="));

  return pair ? decodeURIComponent(pair.slice("XSRF-TOKEN=".length)) : "";
}

export async function initializeCsrf() {
  return fetch("/sanctum/csrf-cookie", {
    credentials: "include",
    headers: { Accept: "application/json" },
  });
}

export async function apiFetch(path, options = {}) {
  const headers = new Headers(options.headers || {});
  headers.set("Accept", "application/json");
  const method = (options.method || "GET").toUpperCase();
  const csrf = xsrfToken();

  if (!["GET", "HEAD", "OPTIONS"].includes(method) && csrf) {
    headers.set("X-XSRF-TOKEN", csrf);
  }

  const response = await fetch(`${API_BASE}${path}`, {
    ...options,
    headers,
    credentials: "include",
  });

  if (response.status === 401 && path !== "/auth/login") {
    clearAuth();
    window.location.assign("/login");
  }

  return response;
}

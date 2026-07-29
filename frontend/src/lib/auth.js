const API_BASE = (import.meta.env.VITE_API_URL || "/api").replace(/\/$/, "");
const API_ORIGIN = API_BASE.endsWith("/api")
  ? API_BASE.slice(0, -4)
  : API_BASE.replace(/\/api\/?$/, "");
const USER_KEY = "dilg_auth_user";
const SESSION_CHECK_TTL_MS = 30_000;
const SESSION_CHECK_TIMEOUT_MS = Number(
  import.meta.env.VITE_SESSION_CHECK_TIMEOUT_MS || 60_000,
);
let sessionVerificationPromise = null;
let lastSessionVerificationAt = 0;

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
  lastSessionVerificationAt = Date.now();
}

export function updateStoredUser(user) {
  sessionStorage.setItem(USER_KEY, JSON.stringify(user));
  lastSessionVerificationAt = Date.now();
}

export function clearAuth() {
  localStorage.removeItem(USER_KEY);
  sessionStorage.removeItem(USER_KEY);
  lastSessionVerificationAt = 0;
}

function xsrfToken() {
  const pair = document.cookie
    .split("; ")
    .find((row) => row.startsWith("XSRF-TOKEN="));

  return pair ? decodeURIComponent(pair.slice("XSRF-TOKEN=".length)) : "";
}

export async function initializeCsrf() {
  return fetch(`${API_ORIGIN}/sanctum/csrf-cookie`, {
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

export function verifySession({ force = false } = {}) {
  const storedUser = getStoredUser();

  if (
    !force
    && storedUser
    && Date.now() - lastSessionVerificationAt < SESSION_CHECK_TTL_MS
  ) {
    return Promise.resolve(storedUser);
  }

  if (sessionVerificationPromise) {
    return sessionVerificationPromise;
  }

  const controller = new AbortController();
  const timeoutId = window.setTimeout(
    () => controller.abort(),
    SESSION_CHECK_TIMEOUT_MS,
  );

  sessionVerificationPromise = apiFetch("/auth/me", { signal: controller.signal })
    .then(async (response) => {
      const payload = await response.json().catch(() => ({}));

      if (!response.ok) {
        const error = new Error(payload.message || "Your session is no longer valid.");
        error.status = response.status;
        throw error;
      }

      updateStoredUser(payload.user);
      return payload.user;
    })
    .catch((error) => {
      if (error.name === "AbortError") {
        throw new Error("Session verification timed out. Check the server connection.");
      }

      throw error;
    })
    .finally(() => {
      window.clearTimeout(timeoutId);
      sessionVerificationPromise = null;
    });

  return sessionVerificationPromise;
}

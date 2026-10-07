import { notifyToast } from "./toastEvents";
import { clearAllFormDrafts } from "./formDrafts";

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

function dispatchToast({ type, message, requestId }) {
  if (!message || typeof window === "undefined") return;

  notifyToast({
    type,
    message,
    meta: requestId ? `Request ID: ${requestId}` : "",
  });
}

async function maybeNotifyMutation(response, method, path, options) {
  if (
    options.suppressToast
    || ["GET", "HEAD", "OPTIONS"].includes(method)
    || path.startsWith("/auth/")
  ) {
    return;
  }

  const contentType = response.headers.get("content-type") || "";
  if (!contentType.includes("application/json")) return;

  const payload = await response.clone().json().catch(() => ({}));
  const message = payload.message || payload.error?.message;
  if (!message) return;

  dispatchToast({
    type: response.ok ? "success" : "error",
    message,
    requestId: response.ok ? "" : payload.request_id || response.headers.get("X-Request-ID") || "",
  });
}

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
  clearAllFormDrafts();
  lastSessionVerificationAt = 0;
}

function xsrfToken() {
  const pair = document.cookie
    .split("; ")
    .find((row) => row.startsWith("XSRF-TOKEN="));

  return pair ? decodeURIComponent(pair.slice("XSRF-TOKEN=".length)) : "";
}

export async function initializeCsrf() {
  try {
    return await fetch(`${API_ORIGIN}/sanctum/csrf-cookie`, {
      credentials: "include",
      headers: { Accept: "application/json" },
    });
  } catch (error) {
    console.error("Secure login initialization failed", error);
    throw new Error("We could not start a secure login session. Check your connection and try again.", { cause: error });
  }
}

function safeApiErrorResponse(response) {
  const requestId = response.headers.get("X-Request-ID") || "";
  const message = response.status >= 500
    ? "We could not complete your request. Please try again. If it continues, contact your administrator with the request ID."
    : "We could not complete this request. Refresh the page and try again.";

  return new Response(JSON.stringify({
    success: false,
    message,
    error: { code: "REQUEST_FAILED", message },
    request_id: requestId,
  }), {
    status: response.status,
    headers: {
      "Content-Type": "application/json",
      ...(requestId ? { "X-Request-ID": requestId } : {}),
    },
  });
}

async function sanitizeApiError(response, path, method) {
  if (response.status < 400) return response;

  const contentType = response.headers.get("Content-Type") || "";
  let unsafe = response.status >= 500 || !contentType.includes("application/json");

  if (!unsafe) {
    const payload = await response.clone().json().catch(() => null);
    unsafe = !payload || typeof payload !== "object" || "trace" in payload || "exception" in payload;
  }

  if (!unsafe) return response;

  console.error("Attendance API request failed", {
    path,
    method,
    status: response.status,
    requestId: response.headers.get("X-Request-ID") || "",
  });
  return safeApiErrorResponse(response);
}

export async function apiFetch(path, options = {}) {
  const { suppressToast = false, skipAuthRedirect = false, ...fetchOptions } = options;
  const headers = new Headers(fetchOptions.headers || {});
  headers.set("Accept", "application/json");
  const method = (fetchOptions.method || "GET").toUpperCase();
  const csrf = xsrfToken();

  if (!["GET", "HEAD", "OPTIONS"].includes(method) && csrf) {
    headers.set("X-XSRF-TOKEN", csrf);
  }

  let response;
  try {
    response = await fetch(`${API_BASE}${path}`, {
      ...fetchOptions,
      headers,
      credentials: "include",
    });
  } catch (error) {
    if (error?.name === "AbortError") throw error;
    console.error("Attendance API connection failed", { path, method, error });
    throw new Error("We could not connect to AttendanceHub. Check your connection and try again.", { cause: error });
  }

  response = await sanitizeApiError(response, path, method);

  await maybeNotifyMutation(response, method, path, { suppressToast });

  if (response.status === 401 && path !== "/auth/login" && !skipAuthRedirect) {
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

  sessionVerificationPromise = apiFetch("/auth/me", {
    signal: controller.signal,
    skipAuthRedirect: true,
  })
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

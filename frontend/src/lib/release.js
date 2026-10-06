export const appVersion = import.meta.env.VITE_APP_VERSION || "development";
export const appBuildId = import.meta.env.VITE_APP_BUILD_ID || appVersion;

export async function fetchCurrentRelease(signal) {
  const url = `${import.meta.env.BASE_URL}version.json?t=${Date.now()}`;
  const response = await fetch(url, {
    cache: "no-store",
    credentials: "same-origin",
    signal,
  });

  if (!response.ok || !response.headers.get("content-type")?.includes("application/json")) {
    throw new Error("Release metadata is unavailable.");
  }

  const release = await response.json();
  if (typeof release?.version !== "string" || typeof release?.build_id !== "string") {
    throw new Error("Release metadata is invalid.");
  }

  return release;
}

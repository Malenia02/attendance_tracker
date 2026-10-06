import { useEffect, useState } from "react";
import { RefreshCw, X } from "lucide-react";
import { appBuildId, fetchCurrentRelease } from "../../lib/release";

const CHECK_INTERVAL_MS = 5 * 60 * 1000;

export default function UpdateNotice() {
  const [availableBuild, setAvailableBuild] = useState("");
  const [dismissedBuild, setDismissedBuild] = useState("");

  useEffect(() => {
    if (import.meta.env.DEV) return undefined;

    const controller = new AbortController();
    let checking = false;

    async function checkForUpdate() {
      if (document.visibilityState === "hidden" || checking) return;
      checking = true;

      try {
        const release = await fetchCurrentRelease(controller.signal);
        if (!controller.signal.aborted) {
          setAvailableBuild(release.build_id !== appBuildId ? release.build_id : "");
        }
      } catch {
        // Network or deployment errors must never block attendance work.
      } finally {
        checking = false;
      }
    }

    const interval = window.setInterval(checkForUpdate, CHECK_INTERVAL_MS);
    document.addEventListener("visibilitychange", checkForUpdate);
    window.addEventListener("focus", checkForUpdate);
    checkForUpdate();

    return () => {
      controller.abort();
      window.clearInterval(interval);
      document.removeEventListener("visibilitychange", checkForUpdate);
      window.removeEventListener("focus", checkForUpdate);
    };
  }, []);

  if (!availableBuild || availableBuild === dismissedBuild) return null;

  function reloadWhenReady() {
    if (window.confirm("Save any unfinished form before reloading. Refresh DILG AttendanceHub now?")) {
      window.location.reload();
    }
  }

  return (
    <div className="app-update-notice" role="status" aria-live="polite">
      <div>
        <strong>A new version of AttendanceHub is available.</strong>
        <span>Finish and save your work before refreshing.</span>
      </div>
      <button type="button" className="app-update-reload" onClick={reloadWhenReady}>
        <RefreshCw size={16} /> Refresh now
      </button>
      <button
        type="button"
        className="app-update-later"
        onClick={() => setDismissedBuild(availableBuild)}
        aria-label="Remind me later about this update"
        title="Remind me later"
      >
        <X size={18} />
      </button>
    </div>
  );
}

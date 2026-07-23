import { useEffect, useState } from "react";
import { Navigate } from "react-router-dom";
import { ShieldCheck } from "lucide-react";
import {
  apiFetch,
  clearAuth,
  getAuthToken,
  updateStoredUser,
} from "../../lib/auth";

export default function RequireAuth({ children }) {
  const [status, setStatus] = useState(() => (getAuthToken() ? "checking" : "guest"));

  useEffect(() => {
    if (status !== "checking") return;

    const controller = new AbortController();

    apiFetch("/auth/me", { signal: controller.signal })
      .then(async (response) => {
        if (!response.ok) throw new Error("Your session is no longer valid.");
        const payload = await response.json();
        updateStoredUser(payload.user);
        setStatus("authenticated");
      })
      .catch((error) => {
        if (error.name === "AbortError") return;
        clearAuth();
        setStatus("guest");
      });

    return () => controller.abort();
  }, [status]);

  if (status === "guest") {
    return <Navigate to="/login" replace />;
  }

  if (status === "checking") {
    return (
      <div className="auth-loading">
        <span><ShieldCheck size={27} /></span>
        <strong>Verifying your session…</strong>
      </div>
    );
  }

  return children;
}

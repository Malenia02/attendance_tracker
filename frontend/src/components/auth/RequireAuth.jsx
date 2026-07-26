import { useEffect, useState } from "react";
import { Navigate } from "react-router-dom";
import DilgSeal from "../branding/DilgSeal";
import {
  apiFetch,
  clearAuth,
  updateStoredUser,
} from "../../lib/auth";

export default function RequireAuth({ children }) {
  const [status, setStatus] = useState("checking");

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
        <span><DilgSeal /></span>
        <strong>Verifying your session…</strong>
      </div>
    );
  }

  return children;
}

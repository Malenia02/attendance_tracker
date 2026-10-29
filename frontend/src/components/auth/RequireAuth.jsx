import { useEffect, useState } from "react";
import { Navigate, useLocation } from "react-router";
import DilgSeal from "../branding/DilgSeal";
import {
  clearAuth,
  getStoredUser,
  verifySession,
} from "../../lib/auth";

export default function RequireAuth({ children }) {
  const location = useLocation();
  const cachedUser = getStoredUser();
  const [status, setStatus] = useState(cachedUser ? "authenticated" : "checking");
  const [retryKey, setRetryKey] = useState(0);

  useEffect(() => {
    let active = true;

    verifySession()
      .then(() => {
        if (active) setStatus("authenticated");
      })
      .catch((error) => {
        if (!active) return;

        if (error.status === 401 || error.status === 419) {
          clearAuth();
          setStatus("guest");
          return;
        }

        if (!getStoredUser()) setStatus("unavailable");
      });

    return () => {
      active = false;
    };
  }, [retryKey]);

  if (status === "guest") {
    const returnTo = `${location.pathname}${location.search}`;
    return <Navigate to={`/login?returnTo=${encodeURIComponent(returnTo)}`} replace />;
  }

  if (status === "checking") {
    return (
      <div className="auth-loading">
        <span><DilgSeal /></span>
        <strong>Verifying your session…</strong>
      </div>
    );
  }

  if (status === "unavailable") {
    return (
      <div className="auth-loading">
        <span><DilgSeal /></span>
        <strong>The server did not respond.</strong>
        <button
          type="button"
          onClick={() => {
            setStatus("checking");
            setRetryKey((key) => key + 1);
          }}
        >
          Try again
        </button>
      </div>
    );
  }

  return children;
}

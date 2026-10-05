import { useEffect, useState } from "react";
import { Navigate, useNavigate } from "react-router";
import {
  ArrowRight,
  Eye,
  EyeOff,
  LockKeyhole,
  UserRound,
} from "lucide-react";
import DilgSeal from "../components/branding/DilgSeal";
import {
  apiFetch,
  getStoredUser,
  initializeCsrf,
  storeAuth,
  verifySession,
} from "../lib/auth";

function offerToSavePassword(username, password, user) {
  if (typeof window.PasswordCredential !== "function" || !navigator.credentials?.store) {
    return;
  }

  try {
    const credential = new window.PasswordCredential({
      id: username,
      password,
      name: user?.full_name || username,
    });

    navigator.credentials.store(credential).catch(() => {
      // Saving is optional; browser settings or a user choice may decline it.
    });
  } catch {
    // Signing in must still work in browsers without password-save support.
  }
}

export default function Login() {
  const navigate = useNavigate();
  const [showPassword, setShowPassword] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState("");

  useEffect(() => {
    if (getStoredUser()) return;

    let active = true;
    verifySession({ force: true })
      .then(() => {
        if (active) navigate("/dashboard", { replace: true });
      })
      .catch(() => {
        // A missing or expired session leaves the login form available.
      });

    return () => {
      active = false;
    };
  }, [navigate]);

  if (getStoredUser()) return <Navigate to="/dashboard" replace />;

  async function handleSubmit(event) {
    event.preventDefault();
    const form = new FormData(event.currentTarget);
    const username = String(form.get("username") || "").trim();
    const password = String(form.get("password") || "");
    const remember = form.has("remember");
    setSubmitting(true);
    setError("");

    try {
      const csrfResponse = await initializeCsrf();

      if (!csrfResponse.ok) {
        throw new Error("A secure login session could not be started. Please try again.");
      }

      const response = await apiFetch("/auth/login", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ username, password, remember }),
      });
      const payload = await response.json().catch(() => ({}));

      if (!response.ok) {
        throw new Error(payload.message || "Unable to sign in. Please try again.");
      }

      storeAuth(payload.user);
      offerToSavePassword(username, password, payload.user);
      navigate("/dashboard", { replace: true });
    } catch (requestError) {
      setError(requestError.message);
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <main className="login-page">
      <section className="login-brand-panel" aria-label="System introduction">
        <div className="login-brand-content">
          <div className="login-seal">
            <DilgSeal />
          </div>
          <p className="login-agency">Department of the Interior and Local Government</p>
          <h1>DILG AttendanceHub</h1>
          <p className="login-intro">
            A secure and reliable workspace for monitoring attendance, schedules, and daily time records.
          </p>

          <div className="login-feature-list">
            <span><i></i> Daily attendance monitoring</span>
            <span><i></i> Accurate DTR management</span>
            <span><i></i> Secure personnel records</span>
          </div>
        </div>

        <div className="login-brand-footer">
          <span>DILG Attendance Management System</span>
          <span>Official Use Only</span>
        </div>
      </section>

      <section className="login-form-panel">
        <div className="login-mobile-brand">
          <span><DilgSeal /></span>
          <strong>DILG </strong>
        </div>

        <div className="login-card">
          <div className="login-heading">
            <span className="login-eyebrow">Welcome back</span>
            <h2>Sign in to your account</h2>
            <p>Enter your assigned credentials to access the attendance system.</p>
          </div>

          <form className="login-form" method="post" onSubmit={handleSubmit}>
            {error && <div className="login-error" role="alert">{error}</div>}

            <label htmlFor="username">Username or email address</label>
            <div className="login-input-group">
              <UserRound size={19} />
              <input
                id="username"
                name="username"
                type="text"
                placeholder="Enter your username"
                autoComplete="username"
                autoCapitalize="none"
                autoCorrect="off"
                spellCheck={false}
                required
              />
            </div>

            <div className="login-password-row">
              <label htmlFor="password">Password</label>
              <button type="button" className="login-text-button">Forgot password?</button>
            </div>
            <div className="login-input-group">
              <LockKeyhole size={19} />
              <input
                id="password"
                name="password"
                type={showPassword ? "text" : "password"}
                placeholder="Enter your password"
                autoComplete="current-password"
                required
              />
              <button
                type="button"
                className="password-toggle"
                onClick={() => setShowPassword((visible) => !visible)}
                aria-label={showPassword ? "Hide password" : "Show password"}
              >
                {showPassword ? <EyeOff size={19} /> : <Eye size={19} />}
              </button>
            </div>

            <label className="remember-option" htmlFor="remember">
              <input
                id="remember"
                name="remember"
                type="checkbox"
              />
              <span>Keep me signed in for 15 days on this private device</span>
            </label>

            <button type="submit" className="login-submit" disabled={submitting}>
              {submitting ? "Signing in…" : "Sign in"}
              <ArrowRight size={18} />
            </button>
          </form>

          <div className="login-help">
            <p>Install on your phone</p>
            <span>Android: in Chrome, open the menu and tap Install app. iPhone: in Safari, tap Share, then Add to Home Screen.</span>
            <p className="login-help-secondary">Having trouble signing in?</p>
            <span>On your private phone, choose Save if your browser offers to save your password. Contact your DILG system administrator if you need help.</span>
          </div>
        </div>

        <footer className="login-footer">
          © 2026 Department of the Interior and Local Government
        </footer>
      </section>
    </main>
  );
}

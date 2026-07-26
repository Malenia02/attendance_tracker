import { useState } from "react";
import { Navigate, useNavigate } from "react-router-dom";
import {
  ArrowRight,
  Eye,
  EyeOff,
  LockKeyhole,
  UserRound,
} from "lucide-react";
import DilgSeal from "../components/branding/DilgSeal";
import { apiFetch, getStoredUser, initializeCsrf, storeAuth } from "../lib/auth";

export default function Login() {
  const navigate = useNavigate();
  const [showPassword, setShowPassword] = useState(false);
  const [credentials, setCredentials] = useState({ username: "", password: "" });
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState("");

  if (getStoredUser()) return <Navigate to="/dashboard" replace />;

  function handleChange(event) {
    const { name, value } = event.target;
    setCredentials((current) => ({ ...current, [name]: value }));
  }

  async function handleSubmit(event) {
    event.preventDefault();
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
        body: JSON.stringify(credentials),
      });
      const payload = await response.json().catch(() => ({}));

      if (!response.ok) {
        throw new Error(payload.message || "Unable to sign in. Please try again.");
      }

      storeAuth(payload.user);
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
          <h1>GIP Attendance<br />Tracker</h1>
          <p className="login-intro">
            A secure and reliable workspace for monitoring Government Internship
            Program attendance, schedules, and daily time records.
          </p>

          <div className="login-feature-list">
            <span><i></i> Daily attendance monitoring</span>
            <span><i></i> Accurate DTR management</span>
            <span><i></i> Secure personnel records</span>
          </div>
        </div>

        <div className="login-brand-footer">
          <span>GIP Attendance Management System</span>
          <span>Official Use Only</span>
        </div>
      </section>

      <section className="login-form-panel">
        <div className="login-mobile-brand">
          <span><DilgSeal /></span>
          <strong>DILG GIP</strong>
        </div>

        <div className="login-card">
          <div className="login-heading">
            <span className="login-eyebrow">Welcome back</span>
            <h2>Sign in to your account</h2>
            <p>Enter your assigned credentials to access the attendance system.</p>
          </div>

          <form className="login-form" onSubmit={handleSubmit}>
            {error && <div className="login-error" role="alert">{error}</div>}

            <label htmlFor="username">Username or email address</label>
            <div className="login-input-group">
              <UserRound size={19} />
              <input
                id="username"
                name="username"
                type="text"
                value={credentials.username}
                onChange={handleChange}
                placeholder="Enter your username"
                autoComplete="username"
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
                value={credentials.password}
                onChange={handleChange}
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

            <button type="submit" className="login-submit" disabled={submitting}>
              {submitting ? "Signing in…" : "Sign in"}
              <ArrowRight size={18} />
            </button>
          </form>

          <div className="login-help">
            <p>Having trouble signing in?</p>
            <span>Contact your DILG system administrator for assistance.</span>
          </div>
        </div>

        <footer className="login-footer">
          © 2026 Department of the Interior and Local Government
        </footer>
      </section>
    </main>
  );
}

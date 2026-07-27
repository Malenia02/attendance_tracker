import {
  ArrowLeft,
  House,
  LifeBuoy,
  RefreshCw,
  ShieldCheck,
  TriangleAlert,
} from "lucide-react";
import { Link, useLocation } from "react-router-dom";
import DilgSeal from "../components/branding/DilgSeal";
import { getStoredUser } from "../lib/auth";
import "../styles/pages/error-page.css";

const ERROR_CONTENT = {
  404: {
    eyebrow: "Page not found",
    title: "We could not find that page",
    message: "The page may have moved, the address may be incorrect, or your account may not have a link to it.",
  },
  500: {
    eyebrow: "Application error",
    title: "Something did not load correctly",
    message: "The interface encountered an unexpected problem. Reload the page before repeating your last action.",
  },
};

export default function ErrorPage({
  code = 404,
  embedded = false,
  error = null,
  onReset = null,
}) {
  const location = useLocation();
  const authenticated = Boolean(getStoredUser());
  const homePath = authenticated ? "/dashboard" : "/login";
  const content = ERROR_CONTENT[code] || ERROR_CONTENT[500];
  const canGoBack = window.history.length > 1;

  function goBack() {
    if (canGoBack) {
      window.history.back();
      return;
    }

    window.location.assign(homePath);
  }

  function retry() {
    if (onReset) onReset();
    window.location.reload();
  }

  return (
    <section
      className={`error-page ${embedded ? "error-page-embedded" : "error-page-standalone"}`}
      role="alert"
      aria-labelledby="error-page-title"
    >
      <div className="error-page-orb error-page-orb-one"></div>
      <div className="error-page-orb error-page-orb-two"></div>

      <article className="error-page-card">
        <div className="error-page-brand">
          <span><DilgSeal /></span>
          <div>
            <strong>DILG GIP Attendance</strong>
            <small>Secure personnel management system</small>
          </div>
        </div>

        <div className="error-page-content">
          <div className="error-page-code" aria-hidden="true">
            <span>{code}</span>
            <i><TriangleAlert size={24} /></i>
          </div>

          <span className="error-page-eyebrow"><ShieldCheck size={14} />{content.eyebrow}</span>
          <h1 id="error-page-title">{content.title}</h1>
          <p>{content.message}</p>

          {code === 404 && (
            <div className="error-page-location">
              <span>Requested address</span>
              <code>{location.pathname}</code>
            </div>
          )}

          <div className="error-page-actions">
            {code === 500 ? (
              <button type="button" className="error-primary-action" onClick={retry}>
                <RefreshCw size={17} />
                Reload page
              </button>
            ) : (
              <Link className="error-primary-action" to={homePath}>
                <House size={17} />
                {authenticated ? "Return to dashboard" : "Return to sign in"}
              </Link>
            )}

            <button type="button" className="error-secondary-action" onClick={goBack}>
              <ArrowLeft size={17} />
              Go back
            </button>
          </div>

          {import.meta.env.DEV && error?.message && (
            <details className="error-technical-details">
              <summary>Developer details</summary>
              <code>{error.message}</code>
            </details>
          )}
        </div>

        <footer className="error-page-footer">
          <LifeBuoy size={15} />
          <span>If the problem continues, contact your DILG system administrator.</span>
        </footer>
      </article>
    </section>
  );
}

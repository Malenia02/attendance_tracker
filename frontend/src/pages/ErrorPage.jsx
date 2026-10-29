import {
  ArrowLeft,
  House,
  LifeBuoy,
  LogIn,
  RefreshCw,
} from "lucide-react";
import { Link, useLocation } from "react-router";
import DilgSeal from "../components/branding/DilgSeal";
import { getStoredUser } from "../lib/auth";
import "../styles/pages/error-page.css";

const ERROR_CONTENT = {
  401: {
    eyebrow: "Authentication required",
    title: "Please Sign In",
    message: "Your session may have ended. Sign in again to continue using Attendance Hub.",
  },
  403: {
    eyebrow: "Restricted access",
    title: "Access Denied",
    message: "Your account does not have permission to access this page.",
  },
  404: {
    eyebrow: "Page not found",
    title: "Page Not Found",
    message: "The page you are trying to access could not be found or may no longer be available.",
  },
  500: {
    eyebrow: "Application error",
    title: "Something Went Wrong",
    message: "An unexpected error occurred while processing your request. Please try again.",
  },
};

export default function ErrorPage({
  code = 404,
  embedded = false,
  onReset = null,
}) {
  const location = useLocation();
  const authenticated = Boolean(getStoredUser());
  const homePath = authenticated ? "/dashboard" : "/";
  const content = ERROR_CONTENT[code] || ERROR_CONTENT[500];
  const canGoBack = window.history.length > 1;
  const currentPage = `${location.pathname}${location.search}`;

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
      data-error-code={code}
    >
      <article className="error-page-card">
        <div className="error-page-brand">
          <span><DilgSeal /></span>
          <div>
            <strong>DILG AttendanceHub</strong>
            <small>Zamboanga Sibugay Provincial Office</small>
          </div>
        </div>

        <div className="error-page-content">
          <p className="error-page-code" aria-label={`Error ${code}`}>{code}</p>
          <span className="error-page-eyebrow">{content.eyebrow}</span>
          <h1 id="error-page-title">{content.title}</h1>
          <p>{content.message}</p>

          <div className="error-page-actions">
            {code === 401 ? (
              <Link className="error-primary-action" to="/login">
                <LogIn size={17} />
                Sign in
              </Link>
            ) : code === 500 ? (
              <button type="button" className="error-primary-action" onClick={retry}>
                <RefreshCw size={17} />
                Try again
              </button>
            ) : (
              <Link className="error-primary-action" to={homePath}>
                <House size={17} />
                {authenticated ? "Return to dashboard" : "Return to home"}
              </Link>
            )}

            <button type="button" className="error-secondary-action" onClick={goBack}>
              <ArrowLeft size={17} />
              Go back
            </button>
          </div>
        </div>

        <footer className="error-page-footer">
          <LifeBuoy size={15} />
          <span>If the problem continues, contact your system administrator.</span>
          {authenticated && (
            <Link to="/issue-reports" state={{ from: currentPage }}>Report this issue</Link>
          )}
        </footer>
      </article>
    </section>
  );
}

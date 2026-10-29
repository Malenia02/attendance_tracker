import { Link } from "react-router";
import DilgSeal from "../branding/DilgSeal";

export default function LandingFooter({ actionPath, issuePath, version }) {
  return (
    <footer className="lp-footer">
      <div className="lp-container lp-footer-main">
        <div className="lp-footer-brand">
          <span><DilgSeal /></span>
          <div><strong>Department of the Interior and Local Government</strong><small>Zamboanga Sibugay Provincial Office</small></div>
        </div>
        <nav aria-label="Footer navigation">
          <a href="#home">Home</a>
          <a href="#features">Features</a>
          <a href="#about">About</a>
          <Link to={issuePath}>Report an Issue</Link>
          <Link to={actionPath}>Sign In</Link>
        </nav>
      </div>
      <div className="lp-container lp-footer-bottom">
        <p>© 2026 Department of the Interior and Local Government – Zamboanga Sibugay Provincial Office.</p>
        <p><strong>Attendance Hub</strong><span>Developed for internal attendance monitoring and DTR management.</span><small>v{version}</small></p>
      </div>
    </footer>
  );
}

import {
  ArrowUpRight,
  Code2,
  ShieldCheck,
} from "lucide-react";
import { Link } from "react-router";
import DilgSeal from "../branding/DilgSeal";
import { appVersion } from "../../lib/release";

export default function Footer() {
  const year = new Date().getFullYear();

  return (
    <footer className="app-footer">
      <div className="app-footer-main">
        <div className="app-footer-brand">
          <span className="app-footer-seal"><DilgSeal alt="" /></span>
          <div>
            <strong>DILG AttendanceHub</strong>
            <small>Attendance and DTR, all in one place</small>
          </div>
        </div>

        <div className="app-footer-actions">
          <nav className="app-footer-links" aria-label="Footer navigation">
            <Link to="/dashboard">Dashboard</Link>
            <Link to="/about">About</Link>
          </nav>
          <a
            className="app-footer-github"
            href="https://github.com/Malenia02"
            target="_blank"
            rel="noreferrer"
            aria-label="Visit Malenia02 on GitHub"
          >
            <Code2 size={15} />
            <span>By @Malenia02</span>
            <ArrowUpRight size={13} />
          </a>
        </div>
      </div>

      <div className="app-footer-bottom">
        <small>&copy; {year} DILG AttendanceHub</small>
        <span className="app-footer-protection"><ShieldCheck size={13} /> Protected workspace</span>
        <Link to="/about" aria-label={`About DILG AttendanceHub, version ${appVersion}`}>
          v{appVersion}
        </Link>
      </div>
    </footer>
  );
}

import {
  ArrowUpRight,
  BadgeCheck,
  Code2,
  LayoutDashboard,
  ShieldCheck,
  Sparkles,
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
            <span className="app-footer-eyebrow"><Sparkles size={12} /> Attendance intelligence</span>
            <strong>DILG AttendanceHub</strong>
            <small>Reliable attendance and DTR operations for DILG personnel and job order workers.</small>
          </div>
        </div>

        <nav className="app-footer-links" aria-label="Footer navigation">
          <Link to="/dashboard"><LayoutDashboard size={15} /> Dashboard</Link>
          <Link to="/about"><BadgeCheck size={15} /> About &amp; updates</Link>
        </nav>

        <div className="app-footer-trust">
          <span><ShieldCheck size={18} /></span>
          <div>
            <small>Protected workspace</small>
            <strong>Role-based access and audit trails</strong>
          </div>
        </div>
      </div>

      <div className="app-footer-bottom">
        <div className="app-footer-release" aria-label={`Installed version ${appVersion}`}>
          <span><i /> Live system</span>
          <Link to="/about">Version {appVersion}</Link>
          <small>Asia/Manila</small>
        </div>

        <small className="app-footer-copyright">
          &copy; {year} DILG AttendanceHub. All rights reserved.
        </small>

        <a
          className="app-footer-github"
          href="https://github.com/Malenia02"
          target="_blank"
          rel="noreferrer"
          aria-label="Visit Malenia02 on GitHub"
        >
          <Code2 size={17} />
          <span>
            <small>Designed and developed by</small>
            <strong>@Malenia02</strong>
          </span>
          <ArrowUpRight size={14} />
        </a>
      </div>
    </footer>
  );
}

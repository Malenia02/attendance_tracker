import { Code2, ExternalLink, ShieldCheck, Sparkles } from "lucide-react";
import DilgSeal from "../branding/DilgSeal";

export default function Footer() {
  const year = new Date().getFullYear();

  return (
    <footer className="app-footer">
      <div className="app-footer-brand">
        <span className="app-footer-seal"><DilgSeal alt="" /></span>
        <div>
          <span className="app-footer-eyebrow"><Sparkles size={11} /> Attendance intelligence</span>
          <strong>GIP Attendance Tracker</strong>
          <small>Secure workforce attendance platform</small>
        </div>
      </div>

      <div className="app-footer-trust">
        <span><ShieldCheck size={16} /></span>
        <div>
          <small>Protected workspace</small>
          <strong>Secure attendance operations</strong>
        </div>
      </div>

      <div className="app-footer-meta">
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
          <ExternalLink size={12} />
        </a>
        <small className="app-footer-copyright">{"\u00A9"} {year} Malenia02. All rights reserved.</small>
      </div>
    </footer>
  );
}

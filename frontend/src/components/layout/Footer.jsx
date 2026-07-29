import { ShieldCheck } from "lucide-react";
import DilgSeal from "../branding/DilgSeal";

export default function Footer() {
  const year = new Date().getFullYear();

  return (
    <footer className="app-footer">
      <div className="app-footer-brand">
        <span className="app-footer-seal"><DilgSeal alt="" /></span>
        <div>
          <strong>DILG GIP Attendance Tracker</strong>
          <small>Department of the Interior and Local Government</small>
        </div>
      </div>

      <div className="app-footer-meta">
        <span><ShieldCheck size={14} />Authorized personnel system</span>
        <small>© {year} DILG. All rights reserved.</small>
      </div>
    </footer>
  );
}

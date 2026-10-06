import {
  ArrowLeft,
  CalendarDays,
  Clock3,
  FileCheck2,
  GitCommitHorizontal,
  RefreshCw,
  ShieldCheck,
  Smartphone,
  Sparkles,
} from "lucide-react";
import { Link } from "react-router";
import DilgSeal from "../components/branding/DilgSeal";
import { appBuildId, appVersion } from "../lib/release";

const capabilities = [
  {
    icon: Clock3,
    title: "Daily attendance",
    description: "Time entries and attendance records in one workspace.",
  },
  {
    icon: CalendarDays,
    title: "Work rules",
    description: "Schedules, duty days, and holidays stay connected.",
  },
  {
    icon: FileCheck2,
    title: "Daily time records",
    description: "Review and prepare DTRs for each reporting period.",
  },
];

export default function About() {
  return (
    <section className="about-page" aria-labelledby="about-title">
      <header className="about-hero">
        <div className="about-hero-main">
          <span className="about-kicker"><Sparkles size={15} /> About the platform</span>
          <div className="about-identity">
            <span className="about-seal"><DilgSeal alt="" /></span>
            <div>
              <h1 id="about-title">DILG AttendanceHub</h1>
              <p>One place for attendance, schedules, and daily time records.</p>
            </div>
          </div>
          <p className="about-hero-description">
            Designed for GIP participants, job order personnel, and other authorized DILG staff.
          </p>
          <Link className="about-back-link" to="/dashboard">
            <ArrowLeft size={16} /> Back to dashboard
          </Link>
        </div>

        <div className="about-version-highlight" aria-label={`Installed release ${appVersion}`}>
          <span className="about-version-icon"><Smartphone size={22} /></span>
          <span className="about-version-label">Installed release</span>
          <strong>v{appVersion}</strong>
          <span className="about-version-caption">Your current app version</span>
        </div>
      </header>

      <div className="about-content-grid">
        <section className="about-card about-release-card" aria-labelledby="about-release-title">
          <div className="about-card-heading">
            <span className="about-card-icon"><GitCommitHorizontal size={21} /></span>
            <div>
              <h2 id="about-release-title">Release details</h2>
              <p>Useful when reporting an issue to your administrator.</p>
            </div>
          </div>
          <dl className="about-release-list">
            <div>
              <dt>Version</dt>
              <dd>v{appVersion}</dd>
            </div>
            <div>
              <dt>Build ID</dt>
              <dd><code>{appBuildId}</code></dd>
            </div>
          </dl>
        </section>

        <section className="about-card about-update-card" aria-labelledby="about-update-title">
          <div className="about-card-heading">
            <span className="about-card-icon"><RefreshCw size={21} /></span>
            <div>
              <h2 id="about-update-title">How updates work</h2>
              <p>New releases are delivered through the web app.</p>
            </div>
          </div>
          <ol className="about-update-steps">
            <li>AttendanceHub checks for a newer version while you use it.</li>
            <li>If an update appears, finish and save any open form.</li>
            <li>Choose “Refresh now” when you are ready.</li>
          </ol>
          <p className="about-update-note"><ShieldCheck size={17} /> The app will never reload your work automatically.</p>
        </section>
      </div>

      <section className="about-capabilities" aria-labelledby="about-capabilities-title">
        <div className="about-section-heading">
          <span>Made for daily operations</span>
          <h2 id="about-capabilities-title">Everything your team needs, together</h2>
        </div>
        <div className="about-capability-grid">
          {capabilities.map(({ icon: Icon, title, description }) => (
            <article className="about-capability" key={title}>
              <span><Icon size={21} /></span>
              <h3>{title}</h3>
              <p>{description}</p>
            </article>
          ))}
        </div>
      </section>
    </section>
  );
}

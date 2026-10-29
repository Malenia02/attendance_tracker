import { ArrowRight, CheckCircle2, ShieldCheck } from "lucide-react";
import { Link } from "react-router";
import { CompactDashboardPreview } from "./LandingDashboardPreview";

export default function LandingHero({ actionLabel, actionPath }) {
  return (
    <section className="lp-hero" id="home" aria-labelledby="landing-heading">
      <div className="lp-container lp-hero-grid">
        <div className="lp-hero-copy lp-reveal">
          <span className="lp-eyebrow">DILG IX <b>•</b> Zamboanga Sibugay</span>
          <h1 id="landing-heading">Smarter Attendance.<br /><span>Simpler DTR Monitoring.</span></h1>
          <p className="lp-hero-lead">
            An efficient attendance and Daily Time Record monitoring system designed to simplify
            employee and GIP attendance management, record keeping, verification, and reporting.
          </p>
          <div className="lp-hero-actions">
            <Link className="lp-button lp-button-primary lp-button-large" to={actionPath}>
              {actionLabel}<ArrowRight aria-hidden="true" />
            </Link>
            <a className="lp-button lp-button-secondary lp-button-large" href="#features">Learn More</a>
          </div>
          <p className="lp-trust-line" aria-label="System values">
            <span>Secure</span><i>•</i><span>Organized</span><i>•</i><span>Accessible</span>
          </p>
          <div className="lp-access-note">
            <ShieldCheck aria-hidden="true" />
            <p><strong>Administrator-issued accounts only.</strong> Personnel accounts are created and activated by an authorized system administrator. Public registration is not available.</p>
          </div>
        </div>

        <div className="lp-hero-preview lp-reveal lp-reveal-delay">
          <CompactDashboardPreview />
          <div className="lp-preview-assurance"><CheckCircle2 aria-hidden="true" /> Fictional sample records</div>
        </div>
      </div>
    </section>
  );
}

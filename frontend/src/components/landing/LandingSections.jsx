import {
  Activity,
  ArrowRight,
  BadgeCheck,
  CalendarCheck2,
  CalendarDays,
  Check,
  CheckCircle2,
  ClipboardCheck,
  Clock3,
  FileCheck2,
  FileClock,
  Fingerprint,
  History,
  KeyRound,
  Laptop,
  LockKeyhole,
  MapPin,
  ShieldCheck,
  UserCheck,
  UsersRound,
} from "lucide-react";
import { Link } from "react-router";
import { DetailedDashboardPreview } from "./LandingDashboardPreview";

const statusItems = [
  [Clock3, "Attendance Monitoring", "Real-time attendance records"],
  [FileCheck2, "Digital DTR", "Organized monthly attendance records"],
  [BadgeCheck, "Verification", "Streamlined checking and approval"],
  [ShieldCheck, "Secure Access", "Role-based authenticated access"],
];

const features = [
  [Fingerprint, "Time In & Time Out", "Record employee or GIP attendance quickly and accurately.", "/attendance"],
  [FileClock, "Daily Time Records", "Automatically organize records for easier DTR preparation and printing.", "/dtr"],
  [Activity, "Attendance Monitoring", "View attendance history, absences, late entries, and attendance status.", "/attendance"],
  [ClipboardCheck, "Remarks & Corrections", "Document official remarks, absences, field activities, corrections, and special cases.", "/action-center?queue=correction_requests"],
  [UserCheck, "DTR Verification", "Allow authorized personnel to review records before finalization.", "/dtr"],
  [CalendarCheck2, "Holiday & Work Schedules", "Handle holidays, designated schedules, and non-working days correctly.", "/calendar"],
];

const workflow = [
  [KeyRound, "Sign In", "Authorized personnel securely access their Attendance Hub account."],
  [Fingerprint, "Record Attendance", "Personnel record their Time In and Time Out using the available attendance flow."],
  [Activity, "Monitor Records", "Attendance data is organized and made available for authorized review."],
  [FileCheck2, "Review & Generate DTR", "Authorized personnel verify records and prepare Daily Time Records."],
];

const benefits = [
  "Reduced manual attendance recording",
  "Faster DTR preparation",
  "Centralized attendance information",
  "Easier record verification",
  "Better attendance visibility",
  "Organized personnel attendance history",
  "Fewer manual encoding errors",
  "Accessible across supported devices",
];

const securityItems = [
  [LockKeyhole, "Secure authentication"],
  [UserCheck, "Role-based permissions"],
  [ShieldCheck, "Protected attendance records"],
  [History, "Activity monitoring"],
  [KeyRound, "Controlled administrative access"],
];

function SectionHeading({ eyebrow, title, description, centered = true }) {
  return (
    <div className={`lp-section-heading${centered ? " is-centered" : ""}`}>
      <span>{eyebrow}</span>
      <h2>{title}</h2>
      {description && <p>{description}</p>}
    </div>
  );
}

export function StatsSection() {
  return (
    <section className="lp-status-section" aria-label="Attendance Hub system capabilities">
      <div className="lp-container lp-status-grid">
        {statusItems.map(([Icon, title, description]) => (
          <article key={title}>
            <span><Icon aria-hidden="true" /></span>
            <div><h2>{title}</h2><p>{description}</p></div>
          </article>
        ))}
      </div>
    </section>
  );
}

export function FeaturesSection({ getModulePath }) {
  return (
    <section className="lp-section lp-features-section" id="features">
      <div className="lp-container">
        <SectionHeading
          eyebrow="CENTRALIZED ATTENDANCE MANAGEMENT"
          title="Built for Efficient Attendance Management"
          description="Everything needed to record, monitor, review, and manage attendance in one centralized system."
        />
        <div className="lp-feature-grid">
          {features.map(([Icon, title, description, path], index) => (
            <Link className="lp-feature-card" to={getModulePath(path)} key={title}>
              <span className={`lp-feature-icon is-${index % 3}`}><Icon aria-hidden="true" /></span>
              <h3>{title}</h3>
              <p>{description}</p>
              <em>Open protected module <ArrowRight aria-hidden="true" /></em>
            </Link>
          ))}
        </div>
        <p className="lp-module-notice"><ShieldCheck aria-hidden="true" /> Module links require an administrator-issued account and follow the user&apos;s assigned permissions.</p>
      </div>
    </section>
  );
}

export function WorkflowSection() {
  return (
    <section className="lp-section lp-workflow-section" id="how-it-works">
      <div className="lp-container">
        <SectionHeading
          eyebrow="HOW IT WORKS"
          title="Simple Attendance Workflow"
          description="A clear process takes each attendance record from secure access through DTR preparation."
        />
        <ol className="lp-workflow-list">
          {workflow.map(([Icon, title, description], index) => (
            <li key={title}>
              <span className="lp-step-number">{String(index + 1).padStart(2, "0")}</span>
              <span className="lp-step-icon"><Icon aria-hidden="true" /></span>
              <h3>{title}</h3>
              <p>{description}</p>
            </li>
          ))}
        </ol>
      </div>
    </section>
  );
}

export function DashboardSection() {
  return (
    <section className="lp-section lp-dashboard-section" aria-labelledby="dashboard-preview-heading">
      <div className="lp-container">
        <SectionHeading
          eyebrow="SYSTEM PREVIEW"
          title="Attendance information at a glance"
          description="A focused workspace helps authorized users review daily status, exceptions, and DTR readiness. All records shown below are fictional."
        />
        <h2 className="lp-visually-hidden" id="dashboard-preview-heading">Attendance dashboard preview</h2>
        <DetailedDashboardPreview />
      </div>
    </section>
  );
}

export function BenefitsSection() {
  return (
    <section className="lp-section lp-benefits-section">
      <div className="lp-container lp-benefits-grid">
        <div className="lp-readiness-card" aria-label="Sample DTR readiness interface">
          <div className="lp-readiness-heading">
            <span><FileCheck2 aria-hidden="true" /></span>
            <div><small>DTR MONITORING</small><strong>October 2026</strong></div>
            <em>Sample</em>
          </div>
          <div className="lp-readiness-score"><strong>86%</strong><span>Records ready for review</span></div>
          <div className="lp-readiness-progress"><i></i></div>
          <div className="lp-readiness-list">
            <div><span><CheckCircle2 aria-hidden="true" /> Complete attendance</span><strong>18</strong></div>
            <div><span><Clock3 aria-hidden="true" /> Needs attention</span><strong>3</strong></div>
            <div><span><CalendarDays aria-hidden="true" /> Approved leave</span><strong>1</strong></div>
          </div>
          <p><ShieldCheck aria-hidden="true" /> Verification remains subject to authorized review.</p>
        </div>
        <div>
          <SectionHeading
            eyebrow="PRACTICAL BENEFITS"
            title="Designed for Better Attendance Management"
            description="The system brings routine attendance work into one structured and reviewable process."
            centered={false}
          />
          <ul className="lp-benefit-list">
            {benefits.map((benefit) => <li key={benefit}><Check aria-hidden="true" />{benefit}</li>)}
          </ul>
        </div>
      </div>
    </section>
  );
}

export function SecuritySection() {
  return (
    <section className="lp-security-section" aria-labelledby="security-heading">
      <div className="lp-container">
        <div className="lp-security-copy">
          <span><ShieldCheck aria-hidden="true" /></span>
          <div>
            <small>ACCESS AND ACCOUNTABILITY</small>
            <h2 id="security-heading">Secure and Controlled Access</h2>
            <p>Attendance information is protected through authenticated access and role-based permissions so users only access features appropriate to their responsibilities.</p>
          </div>
        </div>
        <div className="lp-security-items">
          {securityItems.map(([Icon, label]) => <span key={label}><Icon aria-hidden="true" />{label}</span>)}
        </div>
      </div>
    </section>
  );
}

export function AboutSection() {
  return (
    <section className="lp-section lp-about-section" id="about">
      <div className="lp-container lp-about-grid">
        <div>
          <SectionHeading eyebrow="ABOUT THE SYSTEM" title="About the Attendance Hub" centered={false} />
          <p className="lp-about-lead">The DILG Attendance Hub was developed to support efficient attendance monitoring and Daily Time Record management for personnel and Government Internship Program participants of the DILG Zamboanga Sibugay Provincial Office.</p>
          <p>It provides one organized workspace for attendance capture, review, correction workflows, schedules, and DTR preparation while respecting assigned roles and office responsibilities.</p>
        </div>
        <aside className="lp-office-card" aria-label="System office information">
          <div><MapPin aria-hidden="true" /><span>OFFICIAL SYSTEM INFORMATION</span></div>
          <dl>
            <div><dt>Office</dt><dd>DILG Zamboanga Sibugay Provincial Office</dd></div>
            <div><dt>Region</dt><dd>DILG Region IX</dd></div>
            <div><dt>System</dt><dd>Attendance &amp; DTR Monitoring</dd></div>
          </dl>
        </aside>
      </div>
    </section>
  );
}

export function CTASection({ actionLabel, actionPath }) {
  return (
    <section className="lp-cta-section" aria-labelledby="cta-heading">
      <div className="lp-container lp-cta-inner">
        <div><small>AUTHORIZED USERS</small><h2 id="cta-heading">Access the DILG Attendance Hub</h2><p>Authorized personnel may sign in to record, monitor, and manage attendance information.</p></div>
        <Link className="lp-button lp-button-light lp-button-large" to={actionPath}>{actionLabel}<ArrowRight aria-hidden="true" /></Link>
      </div>
      <div className="lp-container lp-cta-policy"><UsersRound aria-hidden="true" /> Need access? Contact the authorized system administrator. Personnel cannot register directly.</div>
    </section>
  );
}

export function DeviceSupportNote() {
  return (
    <div className="lp-container lp-device-note">
      <Laptop aria-hidden="true" />
      <span><strong>Responsive by design.</strong> Attendance Hub works across supported office laptops, tablets, and mobile devices.</span>
    </div>
  );
}

import { useEffect } from "react";
import LandingFooter from "../components/landing/LandingFooter";
import LandingHero from "../components/landing/LandingHero";
import LandingNavbar from "../components/landing/LandingNavbar";
import {
  AboutSection,
  BenefitsSection,
  CTASection,
  DashboardSection,
  DeviceSupportNote,
  FeaturesSection,
  SecuritySection,
  StatsSection,
  WorkflowSection,
} from "../components/landing/LandingSections";
import { getStoredUser } from "../lib/auth";
import { appVersion } from "../lib/release";

function protectedDestination(path, user) {
  return user ? path : `/login?returnTo=${encodeURIComponent(path)}`;
}

export default function Landing() {
  const user = getStoredUser();
  const actionPath = protectedDestination("/dashboard", user);
  const actionLabel = user ? "Go to Dashboard" : "Sign In";
  const heroActionLabel = user ? "Go to Dashboard" : "Sign In to Attendance Hub";
  const issuePath = protectedDestination("/issue-reports", user);
  const getModulePath = (path) => protectedDestination(path, user);

  useEffect(() => {
    const sectionId = window.location.hash.slice(1);
    if (!sectionId) return undefined;

    const frameId = window.requestAnimationFrame(() => {
      document.getElementById(sectionId)?.scrollIntoView({ block: "start" });
    });

    return () => window.cancelAnimationFrame(frameId);
  }, []);

  return (
    <main className="landing-page">
      <a className="lp-skip-link" href="#landing-main-content">Skip to main content</a>
      <LandingNavbar actionLabel={actionLabel} actionPath={actionPath} />
      <div id="landing-main-content">
        <LandingHero actionLabel={heroActionLabel} actionPath={actionPath} />
        <StatsSection />
        <FeaturesSection getModulePath={getModulePath} />
        <WorkflowSection />
        <DashboardSection />
        <BenefitsSection />
        <SecuritySection />
        <AboutSection />
        <DeviceSupportNote />
        <CTASection actionLabel={actionLabel} actionPath={actionPath} />
      </div>
      <LandingFooter actionPath={actionPath} issuePath={issuePath} version={appVersion} />
    </main>
  );
}

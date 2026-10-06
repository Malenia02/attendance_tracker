import { appBuildId, appVersion } from "../lib/release";

export default function About() {
  return (
    <section className="about-page">
      <div className="page-title">
        <h1>About DILG AttendanceHub</h1>
        <p>Attendance, schedules, and daily time records for DILG personnel.</p>
      </div>

      <div className="panel about-release-panel">
        <h2>Installed application version</h2>
        <dl>
          <div><dt>Release</dt><dd>v{appVersion}</dd></div>
          <div><dt>Build</dt><dd><code>{appBuildId}</code></dd></div>
        </dl>
        <p>New versions are available after deployment. If an update notice appears, finish and save your work before refreshing.</p>
      </div>
    </section>
  );
}

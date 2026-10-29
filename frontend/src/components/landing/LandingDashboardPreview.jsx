import { BarChart3, CalendarDays, CheckCircle2, Clock3, FileCheck2, UsersRound } from "lucide-react";

const attendanceRows = [
  ["Employee 001", "Oct 29, 2026", "7:52 AM", "5:03 PM", "Present", "Complete"],
  ["Employee 002", "Oct 29, 2026", "8:04 AM", "—", "In progress", "Pending time out"],
  ["Employee 003", "Oct 29, 2026", "—", "—", "On leave", "Approved leave"],
];

function Metric({ icon: Icon, label, value, tone = "blue" }) {
  return (
    <div className={`lp-preview-metric is-${tone}`}>
      <span><Icon aria-hidden="true" /></span>
      <div><small>{label}</small><strong>{value}</strong></div>
    </div>
  );
}

export function CompactDashboardPreview() {
  return (
    <div className="lp-dashboard-window" aria-label="Fictional attendance dashboard preview">
      <div className="lp-window-toolbar">
        <span className="lp-window-mark"><i></i><i></i><i></i></span>
        <div><strong>Attendance Overview</strong><small>Thursday, October 29, 2026</small></div>
        <span className="lp-window-live"><i></i> Updated</span>
      </div>
      <div className="lp-window-content">
        <div className="lp-compact-metrics">
          <Metric icon={UsersRound} label="Present" value="18" tone="green" />
          <Metric icon={Clock3} label="Absent" value="2" tone="red" />
          <Metric icon={FileCheck2} label="Pending DTR" value="4" tone="gold" />
        </div>
        <div className="lp-compact-panel">
          <div className="lp-compact-panel-heading"><strong>Recent attendance</strong><span>Today</span></div>
          <div className="lp-compact-row">
            <span className="lp-sample-avatar">01</span>
            <div><strong>Employee 001</strong><small>Time in · 7:52 AM</small></div>
            <em className="lp-status is-present">Present</em>
          </div>
          <div className="lp-compact-row">
            <span className="lp-sample-avatar">02</span>
            <div><strong>Employee 002</strong><small>Time in · 8:04 AM</small></div>
            <em className="lp-status is-progress">In progress</em>
          </div>
          <div className="lp-compact-row">
            <span className="lp-sample-avatar">03</span>
            <div><strong>Employee 003</strong><small>Official leave</small></div>
            <em className="lp-status is-leave">On leave</em>
          </div>
        </div>
        <div className="lp-compact-footer">
          <span><BarChart3 aria-hidden="true" /> Monthly attendance</span>
          <div className="lp-mini-bars" aria-hidden="true"><i></i><i></i><i></i><i></i><i></i><i></i><i></i></div>
        </div>
      </div>
    </div>
  );
}

export function DetailedDashboardPreview() {
  return (
    <div className="lp-detailed-dashboard" aria-label="Fictional detailed attendance dashboard preview">
      <div className="lp-detailed-heading">
        <div><span>ATTENDANCE DASHBOARD</span><h3>Today&apos;s attendance</h3></div>
        <p><CalendarDays aria-hidden="true" /> October 29, 2026</p>
      </div>
      <div className="lp-detailed-metrics">
        <Metric icon={UsersRound} label="Present" value="18" tone="green" />
        <Metric icon={Clock3} label="Absent" value="2" tone="red" />
        <Metric icon={CalendarDays} label="On Leave" value="1" tone="gold" />
        <Metric icon={CheckCircle2} label="Completed" value="15" tone="blue" />
      </div>
      <div className="lp-dashboard-layout">
        <div className="lp-table-card">
          <div className="lp-card-heading"><strong>Attendance records</strong><span>Sample data</span></div>
          <div className="lp-table-scroll">
            <table>
              <thead><tr><th>Employee</th><th>Date</th><th>Time In</th><th>Time Out</th><th>Status</th><th>Remarks</th></tr></thead>
              <tbody>
                {attendanceRows.map(([employee, date, timeIn, timeOut, status, remarks]) => (
                  <tr key={employee}>
                    <td><span className="lp-table-person"><i>{employee.slice(-3)}</i>{employee}</span></td>
                    <td>{date}</td><td>{timeIn}</td><td>{timeOut}</td>
                    <td><span className={`lp-status ${status === "Present" ? "is-present" : status === "On leave" ? "is-leave" : "is-progress"}`}>{status}</span></td>
                    <td>{remarks}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
        <div className="lp-chart-card">
          <div className="lp-card-heading"><strong>Monthly overview</strong><BarChart3 aria-hidden="true" /></div>
          <div className="lp-chart-legend"><span><i></i>Attendance rate</span><strong>94%</strong></div>
          <div className="lp-chart" aria-label="Sample monthly attendance chart">
            {[72, 86, 79, 92, 88, 96, 91, 98].map((value, index) => (
              <div key={value + index}><i style={{ height: `${value}%` }}></i><small>W{index + 1}</small></div>
            ))}
          </div>
          <p><CheckCircle2 aria-hidden="true" /> 21 working days monitored</p>
        </div>
      </div>
    </div>
  );
}

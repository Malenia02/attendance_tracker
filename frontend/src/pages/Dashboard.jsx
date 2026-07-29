import { useEffect, useMemo, useState } from "react";
import { useNavigate } from "react-router-dom";
import {
  Activity,
  AlertTriangle,
  ArrowRight,
  BadgeCheck,
  CalendarClock,
  CalendarDays,
  CheckCircle2,
  ClipboardCheck,
  Clock3,
  Coffee,
  FileCheck2,
  FileWarning,
  MapPin,
  QrCode,
  Sparkles,
  TimerReset,
  UserCheck,
  Users,
  X,
} from "lucide-react";
import { apiFetch, getStoredUser } from "../lib/auth";
import { formatDuration } from "../lib/duration";

async function readResponse(response) {
  const payload = await response.json().catch(() => ({}));
  if (!response.ok) throw new Error(payload.message || "The dashboard could not be loaded.");
  return payload;
}

function relativeTime(value) {
  const seconds = Math.max(0, Math.floor((Date.now() - new Date(value).getTime()) / 1000));
  if (seconds < 60) return "Just now";
  if (seconds < 3600) return `${Math.floor(seconds / 60)}m ago`;
  if (seconds < 86400) return `${Math.floor(seconds / 3600)}h ago`;
  return `${Math.floor(seconds / 86400)}d ago`;
}

function greeting() {
  const hour = new Date().getHours();
  if (hour < 12) return "Good morning";
  if (hour < 18) return "Good afternoon";
  return "Good evening";
}

export default function Dashboard() {
  const navigate = useNavigate();
  const currentUser = getStoredUser();
  const isAttendanceManager = ["Administrator", "HR", "Supervisor", "Encoder"]
    .includes(currentUser?.user_role);
  const canManagePersonnel = ["Administrator", "HR"].includes(currentUser?.user_role);
  const displayName = currentUser?.personnel?.full_name || currentUser?.username || "System User";
  const firstName = displayName.split(" ")[0];
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [now, setNow] = useState(new Date());

  useEffect(() => {
    const timer = window.setInterval(() => setNow(new Date()), 1000);
    return () => window.clearInterval(timer);
  }, []);

  useEffect(() => {
    const controller = new AbortController();

    apiFetch("/dashboard", { signal: controller.signal })
      .then(readResponse)
      .then(setData)
      .catch((requestError) => {
        if (requestError.name !== "AbortError") setError(requestError.message);
      })
      .finally(() => {
        if (!controller.signal.aborted) setLoading(false);
      });

    return () => controller.abort();
  }, []);

  const maximumExpected = useMemo(
    () => Math.max(1, ...(data?.weekly || []).map((day) => day.expected)),
    [data],
  );

  if (loading) {
    return (
      <section className="ops-dashboard">
        <div className="dashboard-loading">
          <span><Activity size={25} /></span>
          <strong>Preparing your operations dashboard…</strong>
          <small>Calculating live attendance and DTR status</small>
        </div>
      </section>
    );
  }

  if (error || !data) {
    return (
      <section className="ops-dashboard">
        <div className="users-notice error"><X size={18} />{error || "Dashboard data is unavailable."}</div>
      </section>
    );
  }

  const stats = [
    {
      label: "Active Personnel",
      value: data.today.total_personnel,
      helper: data.scope,
      icon: Users,
      tone: "blue",
      path: canManagePersonnel ? "/personnel" : "/attendance",
    },
    {
      label: "Present Today",
      value: data.today.present,
      helper: `${data.today.attendance_rate}% attendance rate`,
      icon: UserCheck,
      tone: "green",
      path: "/attendance",
    },
    {
      label: "Late Today",
      value: data.today.late,
      helper: data.today.late ? "Requires monitoring" : "No late arrivals",
      icon: Clock3,
      tone: "orange",
      path: "/attendance",
    },
    {
      label: "DTR Attention",
      value: data.dtr.returned + data.dtr.submitted,
      helper: `${data.dtr.returned} returned · ${data.dtr.submitted} submitted`,
      icon: FileWarning,
      tone: "violet",
      path: "/dtr",
    },
  ];

  const quickActions = [
    { label: "Attendance", icon: Clock3, path: "/attendance" },
    { label: "DTR Monitoring", icon: ClipboardCheck, path: "/dtr" },
    ...(isAttendanceManager
      ? [{ label: "QR Kiosk", icon: QrCode, path: "/qr-attendance" }]
      : currentUser?.user_role === "Personnel"
        ? [{ label: "QR Attendance", icon: QrCode, path: "/qr-attendance" }]
        : []),
    { label: "Calendar", icon: CalendarDays, path: "/calendar" },
  ];

  return (
    <section className="ops-dashboard">
      <header className="ops-dashboard-hero">
        <div>
          <span className="ops-eyebrow"><Sparkles size={14} /> DILG GIP Attendance Command Center</span>
          <h1>{greeting()}, {firstName}</h1>
          <p>Here is the live attendance and DTR situation across your assigned scope.</p>
          <div className="ops-hero-meta">
            <span><BadgeCheck size={14} /> System operational</span>
            <span><CalendarClock size={14} /> {data.today.calendar_status}</span>
          </div>
        </div>
        <div className="ops-live-clock">
          <small>{data.today.day_label}</small>
          <strong>{now.toLocaleTimeString("en-PH", {
            hour: "2-digit",
            minute: "2-digit",
            second: "2-digit",
          })}</strong>
          <span>{data.scope}</span>
        </div>
        <i></i>
      </header>

      <div className="ops-quick-actions" aria-label="Quick actions">
        {quickActions.map(({ label, icon: Icon, path }) => (
          <button type="button" key={label} onClick={() => navigate(path)}>
            <span><Icon size={17} /></span>
            <b>{label}</b>
            <ArrowRight size={14} />
          </button>
        ))}
      </div>

      <div className="ops-stat-grid">
        {stats.map(({ label, value, helper, icon: Icon, tone, path }, index) => (
          <button
            type="button"
            className={`ops-stat-card ${tone}`}
            key={label}
            onClick={() => navigate(path)}
            style={{ "--delay": `${index * 55}ms` }}
          >
            <span className="ops-stat-icon"><Icon size={21} /></span>
            <div><small>{label}</small><strong>{value}</strong><p>{helper}</p></div>
            <ArrowRight size={16} />
          </button>
        ))}
      </div>

      <div className="ops-dashboard-grid">
        <div className="ops-dashboard-main">
          <article className="ops-panel ops-weekly-panel">
            <header>
              <div>
                <span>Attendance pulse</span>
                <h2>This week at a glance</h2>
                <p>Expected duty versus recorded attendance, including approved optional days.</p>
              </div>
              <div className="ops-chart-legend">
                <span><i className="expected"></i>Expected</span>
                <span><i className="present"></i>Present</span>
                <span><i className="late"></i>Late</span>
              </div>
            </header>

            <div className="ops-weekly-chart">
              {data.weekly.map((day) => {
                const expectedHeight = (day.expected / maximumExpected) * 100;
                const presentHeight = (day.present / maximumExpected) * 100;

                return (
                  <div className={`ops-chart-day ${day.is_future ? "future" : ""}`} key={day.date}>
                    <div className="ops-chart-values">
                      {!!day.present && <b style={{ bottom: `calc(${presentHeight}% + 7px)` }}>{day.present}</b>}
                      <span className="expected-bar" style={{ height: `${expectedHeight}%` }}></span>
                      <span className="present-bar" style={{ height: `${presentHeight}%` }}></span>
                      {!!day.late && <em title={`${day.late} late`}>{day.late}</em>}
                    </div>
                    <strong>{day.day}</strong>
                    <small>{day.calendar_label}</small>
                  </div>
                );
              })}
            </div>
          </article>

          <div className="ops-dual-panels">
            <article className="ops-panel ops-dtr-panel">
              <header>
                <div><span>Monthly workflow</span><h2>DTR Pipeline</h2><p>{data.dtr.month_label}</p></div>
                <button type="button" onClick={() => navigate("/dtr")}>Open DTR <ArrowRight size={14} /></button>
              </header>
              <div className="ops-dtr-progress" aria-label="DTR certification progress">
                {[
                  ["Draft", data.dtr.draft, "draft"],
                  ["Submitted", data.dtr.submitted, "submitted"],
                  ["Returned", data.dtr.returned, "returned"],
                  ["Certified", data.dtr.certified, "certified"],
                ].map(([label, value, className]) => (
                  <div key={label}>
                    <span><i className={className}></i>{label}</span>
                    <strong>{value}</strong>
                    <small>{data.dtr.total ? Math.round((value / data.dtr.total) * 100) : 0}%</small>
                  </div>
                ))}
              </div>
              <div className="ops-certification-track">
                <i style={{
                  width: `${data.dtr.total ? (data.dtr.certified / data.dtr.total) * 100 : 0}%`,
                }}></i>
              </div>
              <p className="ops-certification-copy">
                <FileCheck2 size={15} />
                {data.dtr.certified} of {data.dtr.total} personnel DTRs certified
              </p>
            </article>

            <article className="ops-panel ops-department-panel">
              <header>
                <div><span>Workforce</span><h2>Personnel by Office</h2></div>
                <MapPin size={20} />
              </header>
              <div className="ops-department-list">
                {data.departments.map((department) => (
                  <div key={department.code}>
                    <span title={department.name}>{department.code}<small>{department.name}</small></span>
                    <div><i style={{
                      width: `${data.today.total_personnel
                        ? (department.count / data.today.total_personnel) * 100
                        : 0}%`,
                    }}></i></div>
                    <strong>{department.count}</strong>
                  </div>
                ))}
                {!data.departments.length && <p className="ops-empty">No office assignments available.</p>}
              </div>
            </article>
          </div>

          <article className="ops-panel ops-recent-panel">
            <header>
              <div><span>Live stream</span><h2>Recent Attendance Activity</h2></div>
              <button type="button" onClick={() => navigate("/attendance")}>View attendance <ArrowRight size={14} /></button>
            </header>
            <div className="ops-recent-grid">
              {data.recent_logs.map((log) => (
                <div key={log.id}>
                  <span className={log.action.includes("Out") ? "out" : "in"}>
                    {log.action.includes("Out") ? <Clock3 size={15} /> : <CheckCircle2 size={15} />}
                  </span>
                  <div><strong>{log.full_name}</strong><small>{log.action} · {log.source}</small></div>
                  <time>{relativeTime(log.logged_at)}</time>
                </div>
              ))}
              {!data.recent_logs.length && <p className="ops-empty">No attendance activity has been recorded yet.</p>}
            </div>
          </article>
        </div>

        <aside className="ops-dashboard-side">
          <article className="ops-panel ops-today-panel">
            <header><div><span>Today</span><h2>Attendance Health</h2></div><Activity size={20} /></header>
            <div className="ops-donut-row">
              <div
                className="ops-attendance-donut"
                style={{ "--rate": `${data.today.attendance_rate * 3.6}deg` }}
              >
                <div><strong>{data.today.attendance_rate}%</strong><small>present</small></div>
              </div>
              <div className="ops-today-counts">
                <span><i className="green"></i><b>{data.today.present}</b>Present</span>
                <span><i className="blue"></i><b>{data.today.timed_in}</b>Timed in</span>
                <span><i className="orange"></i><b>{data.today.late}</b>Late</span>
                <span><i className="red"></i><b>{data.today.incomplete}</b>Incomplete</span>
              </div>
            </div>
            <div className="ops-today-foot">
              <span><Coffee size={14} />{data.today.half_day} half day</span>
              <span><TimerReset size={14} />{data.today.not_started} not started</span>
            </div>
          </article>

          <article className="ops-panel ops-exception-panel">
            <header>
              <div><span>Action needed</span><h2>Attendance Exceptions</h2></div>
              <AlertTriangle size={20} />
            </header>
            <div className="ops-exception-list">
              {data.exceptions.map((item) => (
                <button
                  type="button"
                  key={item.personnel_id}
                  onClick={() => navigate(
                    `/attendance?date=${data.today.date}&personnel=${item.personnel_id}`,
                  )}
                >
                  <span>{item.full_name.split(" ").map((part) => part[0]).slice(0, 2).join("")}</span>
                  <div><strong>{item.full_name}</strong><small>{item.department} · {item.status}</small></div>
                  {item.is_late && <em>{formatDuration(item.late_minutes)} late</em>}
                  <ArrowRight size={13} />
                </button>
              ))}
              {!data.exceptions.length && (
                <div className="ops-exception-clear">
                  <BadgeCheck size={24} /><strong>No active exceptions</strong><small>Attendance looks healthy.</small>
                </div>
              )}
            </div>
          </article>

          <article className="ops-panel ops-calendar-panel">
            <header>
              <div><span>Next 45 days</span><h2>Calendar Notices</h2></div>
              <button type="button" onClick={() => navigate("/calendar")}><CalendarDays size={18} /></button>
            </header>
            <div className="ops-event-list">
              {data.upcoming_events.map((event) => (
                <div key={event.id}>
                  <time>
                    <strong>{new Date(`${event.date}T00:00:00`).toLocaleDateString("en-PH", { day: "2-digit" })}</strong>
                    <small>{new Date(`${event.date}T00:00:00`).toLocaleDateString("en-PH", { month: "short" })}</small>
                  </time>
                  <span className={event.is_working_day ? "working" : ""}>
                    <strong>{event.name}</strong>
                    <small>{event.is_working_day ? "Authorized duty day" : event.type} · {event.scope}</small>
                  </span>
                </div>
              ))}
              {!data.upcoming_events.length && <p className="ops-empty">No upcoming calendar notices.</p>}
            </div>
          </article>
        </aside>
      </div>
    </section>
  );
}

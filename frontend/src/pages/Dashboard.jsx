import {
  Clock3,
  Ellipsis,
  FileWarning,
  TrendingUp,
  UserCheck,
  Users,
} from "lucide-react";

const stats = [
  {
    label: "Total Personnel",
    value: "128",
    change: "+8.4%",
    icon: Users,
    type: "blue",
  },
  {
    label: "Present Today",
    value: "94",
    change: "+6.1%",
    icon: UserCheck,
    type: "green",
  },
  {
    label: "Late Today",
    value: "7",
    change: "-2.5%",
    icon: Clock3,
    type: "orange",
  },
  {
    label: "Incomplete DTR",
    value: "5",
    change: "+1.8%",
    icon: FileWarning,
    type: "blue",
  },
];

const recentActivities = [
  { time: "12 min", text: "Louie Inoferio recorded morning time-in." },
  { time: "38 min", text: "Angela Reyes submitted July DTR." },
  { time: "1 hr", text: "Maria Garcia verified attendance records." },
  { time: "2 hrs", text: "QR attendance token was generated." },
  { time: "4 hrs", text: "John Mendoza recorded overtime out." },
];

export default function Dashboard() {
  return (
    <section className="dashboard-page">
      <div className="page-title">
        <h1>Dashboard</h1>
        <nav className="breadcrumb" aria-label="Breadcrumb">
          <span>Home</span>
          <span>/</span>
          <strong>Dashboard</strong>
        </nav>
      </div>

      <div className="dashboard-columns">
        <div className="dashboard-primary">
          <div className="stats-grid">
            {stats.map((stat) => {
              const Icon = stat.icon;

              return (
                <article key={stat.label} className={`stat-card ${stat.type}`}>
                  <div className="stat-card-heading">
                    <h2>{stat.label}</h2>
                    <span>| Today</span>
                    <button type="button" aria-label={`More ${stat.label} options`}>
                      <Ellipsis size={20} />
                    </button>
                  </div>

                  <div className="stat-card-body">
                    <div className="stat-icon">
                      <Icon size={27} />
                    </div>
                    <div>
                      <strong>{stat.value}</strong>
                      <p>
                        <span>{stat.change}</span>
                        <TrendingUp size={14} /> from last week
                      </p>
                    </div>
                  </div>
                </article>
              );
            })}
          </div>

          <div className="panel chart-panel">
            <div className="panel-header">
              <div>
                <h2>Attendance Overview <span>| This Week</span></h2>
                <p>Monday to Thursday compressed work schedule</p>
              </div>
              <button type="button" className="panel-menu" aria-label="Chart options">
                <Ellipsis size={21} />
              </button>
            </div>

            <div className="chart-legend">
              <span><i className="legend-present"></i> Present</span>
              <span><i className="legend-late"></i> Late</span>
              <span><i className="legend-undertime"></i> Undertime</span>
            </div>

            <div className="line-chart" aria-label="Weekly attendance chart">
              <div className="chart-y-labels"><span>100</span><span>75</span><span>50</span><span>25</span><span>0</span></div>
              <svg viewBox="0 0 760 260" role="img" aria-label="Attendance values from Monday to Thursday">
                <defs>
                  <linearGradient id="attendanceFill" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0%" stopColor="#4154f1" stopOpacity=".25" />
                    <stop offset="100%" stopColor="#4154f1" stopOpacity="0" />
                  </linearGradient>
                </defs>
                <g className="chart-grid-lines">
                  <line x1="0" y1="20" x2="760" y2="20" /><line x1="0" y1="75" x2="760" y2="75" />
                  <line x1="0" y1="130" x2="760" y2="130" /><line x1="0" y1="185" x2="760" y2="185" />
                  <line x1="0" y1="240" x2="760" y2="240" />
                </g>
                <path className="chart-area" d="M20 88 C130 76,165 50,255 65 S390 93,500 52 S650 68,740 38 L740 240 L20 240 Z" />
                <path className="chart-line chart-line-primary" d="M20 88 C130 76,165 50,255 65 S390 93,500 52 S650 68,740 38" />
                <path className="chart-line chart-line-warning" d="M20 200 C130 190,165 205,255 188 S390 199,500 181 S650 195,740 174" />
                <path className="chart-line chart-line-green" d="M20 220 C130 215,165 224,255 211 S390 218,500 207 S650 214,740 202" />
              </svg>
              <div className="chart-x-labels"><span>Mon</span><span>Tue</span><span>Wed</span><span>Thu</span></div>
            </div>
          </div>
        </div>

        <aside className="dashboard-secondary">
          <div className="panel activity-panel">
            <div className="panel-header">
              <div>
                <h2>Recent Activity <span>| Today</span></h2>
              </div>
              <button type="button" className="panel-menu" aria-label="Activity options">
                <Ellipsis size={21} />
              </button>
            </div>

            <div className="activity-list">
              {recentActivities.map((activity, index) => (
                <div key={activity.text} className="activity-item">
                  <time>{activity.time}</time>
                  <span className={`dot dot-${index + 1}`}></span>
                  <p>{activity.text}</p>
                </div>
              ))}
            </div>

            <button type="button" className="activity-view-all">View all activity</button>
          </div>

          <div className="panel quick-summary">
            <div className="panel-header">
              <h2>Today’s Summary</h2>
              <Ellipsis size={21} />
            </div>
            <div className="progress-row"><span>Present</span><strong>94 / 128</strong></div>
            <div className="progress-track"><i style={{ width: "73%" }}></i></div>
            <div className="summary-mini-grid">
              <div><strong>7</strong><span>Late</span></div>
              <div><strong>3</strong><span>Undertime</span></div>
              <div><strong>5</strong><span>Incomplete</span></div>
            </div>
          </div>
        </aside>
      </div>
    </section>
  );
}

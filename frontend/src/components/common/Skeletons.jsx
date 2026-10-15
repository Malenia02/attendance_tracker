const widths = ["58%", "74%", "46%", "82%", "63%", "39%", "70%"];

export function SkeletonBlock({ className = "", style }) {
  return <span aria-hidden="true" className={`loading-skeleton ${className}`.trim()} style={style} />;
}

export function TableSkeletonRows({ columns, rows = 5, label = "records" }) {
  return Array.from({ length: rows }, (_, rowIndex) => (
    <tr className="table-skeleton-row" key={`skeleton-${rowIndex}`}>
      <td colSpan={columns}>
        {rowIndex === 0 && <span className="sr-only" role="status">Loading {label}…</span>}
        <div className="table-skeleton-cells" style={{ "--skeleton-columns": columns }} aria-hidden="true">
          {Array.from({ length: columns }, (_, columnIndex) => (
            <span className="table-skeleton-cell" key={`skeleton-cell-${columnIndex}`}>
              <SkeletonBlock style={{ width: widths[(rowIndex + columnIndex) % widths.length] }} />
              {columnIndex === 0 && <SkeletonBlock className="skeleton-subline" style={{ width: "44%" }} />}
            </span>
          ))}
        </div>
      </td>
    </tr>
  ));
}

export function NotificationListSkeleton({ rows = 4 }) {
  return (
    <div className="notification-list-skeleton" role="status" aria-label="Loading notifications">
      {Array.from({ length: rows }, (_, index) => (
        <div className="notification-skeleton-row" key={`notification-skeleton-${index}`} aria-hidden="true">
          <SkeletonBlock className="notification-skeleton-icon" />
          <div className="notification-skeleton-copy">
            <SkeletonBlock style={{ width: `${48 + (index % 3) * 11}%` }} />
            <SkeletonBlock className="skeleton-subline" style={{ width: `${82 - (index % 2) * 13}%` }} />
            <SkeletonBlock className="skeleton-caption" style={{ width: "24%" }} />
          </div>
          <SkeletonBlock className="notification-skeleton-action" />
        </div>
      ))}
      <span className="sr-only">Loading notifications…</span>
    </div>
  );
}

export function ActionListSkeleton({ rows = 3 }) {
  return (
    <div className="action-list-skeleton" role="status" aria-label="Loading actions">
      {Array.from({ length: rows }, (_, index) => (
        <div className="action-skeleton-row" key={`action-skeleton-${index}`} aria-hidden="true">
          <SkeletonBlock className="action-skeleton-avatar" />
          <div className="action-skeleton-copy">
            <SkeletonBlock style={{ width: `${40 + (index % 3) * 10}%` }} />
            <SkeletonBlock className="skeleton-subline" style={{ width: "72%" }} />
          </div>
          <SkeletonBlock className="action-skeleton-meta" />
        </div>
      ))}
      <span className="sr-only">Loading authorized actions…</span>
    </div>
  );
}

export function CardGridSkeleton({ cards = 3 }) {
  return (
    <div className="card-grid-skeleton" role="status" aria-label="Loading schedule templates">
      {Array.from({ length: cards }, (_, index) => (
        <div className="card-skeleton" key={`card-skeleton-${index}`} aria-hidden="true">
          <SkeletonBlock className="card-skeleton-heading" />
          <SkeletonBlock className="skeleton-subline" style={{ width: "61%" }} />
          <SkeletonBlock className="card-skeleton-body" />
        </div>
      ))}
      <span className="sr-only">Loading schedule templates…</span>
    </div>
  );
}

export function DashboardSkeleton() {
  return (
    <div className="dashboard-page-skeleton" role="status" aria-label="Loading dashboard">
      <div className="dashboard-skeleton-hero" aria-hidden="true">
        <SkeletonBlock className="dashboard-skeleton-eyebrow" />
        <SkeletonBlock className="dashboard-skeleton-title" />
        <SkeletonBlock className="dashboard-skeleton-description" />
      </div>
      <div className="dashboard-skeleton-stats" aria-hidden="true">
        {Array.from({ length: 4 }, (_, index) => (
          <div className="dashboard-skeleton-stat" key={`dashboard-stat-${index}`}>
            <SkeletonBlock className="dashboard-skeleton-icon" />
            <div><SkeletonBlock className="dashboard-skeleton-stat-value" /><SkeletonBlock className="skeleton-subline" /></div>
          </div>
        ))}
      </div>
      <div className="dashboard-skeleton-content" aria-hidden="true">
        <div><SkeletonBlock className="dashboard-skeleton-section-title" /><SkeletonBlock className="dashboard-skeleton-chart" /></div>
        <div><SkeletonBlock className="dashboard-skeleton-section-title" /><SkeletonBlock className="dashboard-skeleton-chart" /></div>
      </div>
      <span className="sr-only">Preparing your role-specific dashboard…</span>
    </div>
  );
}

export function RoutePageSkeleton({ title, description }) {
  return (
    <section className="route-page-skeleton" role="status" aria-label={`Loading ${title}`}>
      <div className="route-skeleton-heading">
        <h1>{title}</h1>
        <p>{description}</p>
      </div>
      <div className="route-skeleton-summary" aria-hidden="true">
        {Array.from({ length: 3 }, (_, index) => (
          <div className="route-skeleton-summary-card" key={`summary-${index}`}>
            <SkeletonBlock className="dashboard-skeleton-stat-value" />
            <SkeletonBlock className="skeleton-subline" />
          </div>
        ))}
      </div>
      <div className="route-skeleton-panel" aria-hidden="true">
        <SkeletonBlock className="dashboard-skeleton-section-title" />
        {Array.from({ length: 5 }, (_, index) => (
          <div className="route-skeleton-row" key={`row-${index}`}>
            <SkeletonBlock style={{ width: `${45 + (index % 3) * 10}%` }} />
            <SkeletonBlock style={{ width: `${70 - (index % 2) * 13}%` }} />
            <SkeletonBlock style={{ width: "28%" }} />
          </div>
        ))}
      </div>
      <span className="sr-only">Loading page content…</span>
    </section>
  );
}

export function HealthOverviewSkeleton() {
  return (
    <div className="health-overview-skeleton" role="status" aria-label="Loading system health checks">
      <div className="health-summary-skeleton" aria-hidden="true">
        {Array.from({ length: 4 }, (_, index) => (
          <div className="health-summary-skeleton-card" key={`health-summary-${index}`}>
            <SkeletonBlock className="dashboard-skeleton-icon" />
            <div><SkeletonBlock className="dashboard-skeleton-stat-value" /><SkeletonBlock className="skeleton-subline" /></div>
          </div>
        ))}
      </div>
      <div className="health-check-skeleton-grid" aria-hidden="true">
        {Array.from({ length: 6 }, (_, index) => (
          <div className="health-check-skeleton-card" key={`health-check-${index}`}>
            <SkeletonBlock className="dashboard-skeleton-icon" />
            <SkeletonBlock className="dashboard-skeleton-section-title" />
            <SkeletonBlock className="skeleton-subline" />
            <SkeletonBlock className="health-check-skeleton-detail" />
          </div>
        ))}
      </div>
      <span className="sr-only">Checking service and backup health…</span>
    </div>
  );
}


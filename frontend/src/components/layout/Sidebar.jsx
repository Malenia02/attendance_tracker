import {
  BarChart3,
  CalendarDays,
  Clock3,
  FileText,
  LayoutDashboard,
  LogOut,
  QrCode,
  Settings,
  ShieldCheck,
  Users,
} from "lucide-react";
import { Link, NavLink } from "react-router-dom";

const menuItems = [
  {
    label: "Dashboard",
    path: "/dashboard",
    icon: LayoutDashboard,
  },
  {
    label: "Attendance",
    path: "/attendance",
    icon: Clock3,
  },
  {
    label: "Personnel",
    path: "/personnel",
    icon: Users,
  },
  {
    label: "Schedules",
    path: "/schedules",
    icon: CalendarDays,
  },
  {
    label: "DTR Reports",
    path: "/dtr",
    icon: FileText,
  },
  {
    label: "QR Attendance",
    path: "/qr-attendance",
    icon: QrCode,
  },
  {
    label: "Activity Logs",
    path: "/activity-logs",
    icon: BarChart3,
  },
  {
    label: "System Users",
    path: "/system-users",
    icon: ShieldCheck,
  },
  {
    label: "Settings",
    path: "/settings",
    icon: Settings,
  },
];

export default function Sidebar({ isOpen }) {
  return (
    <aside className={`sidebar ${isOpen ? "sidebar-open" : ""}`}>
      <nav className="sidebar-nav">
        <p className="sidebar-section-title">Attendance System</p>

        {menuItems.map((item) => {
          const Icon = item.icon;

          return (
            <NavLink
              key={item.path}
              to={item.path}
              className={({ isActive }) =>
                isActive ? "nav-link active" : "nav-link"
              }
            >
              <Icon size={18} />
              <span>{item.label}</span>
            </NavLink>
          );
        })}
      </nav>

      <div className="sidebar-user">
        <div className="avatar">JD</div>

        <div className="sidebar-user-info">
          <strong>John Doe</strong>
          <span>Administrator</span>
        </div>

        <Link to="/login" className="sidebar-icon-button" aria-label="Sign out">
          <LogOut size={18} />
        </Link>
      </div>
    </aside>
  );
}

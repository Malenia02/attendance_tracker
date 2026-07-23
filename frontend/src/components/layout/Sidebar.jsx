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
import { NavLink, useNavigate } from "react-router-dom";
import { apiFetch, clearAuth, getStoredUser } from "../../lib/auth";

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
    roles: ["Administrator", "HR"],
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
    roles: ["Administrator"],
  },
  {
    label: "Settings",
    path: "/settings",
    icon: Settings,
  },
];

export default function Sidebar({ isOpen }) {
  const navigate = useNavigate();
  const currentUser = getStoredUser();
  const displayName = currentUser?.personnel?.full_name || currentUser?.username || "System User";
  const initials = displayName
    .split(" ")
    .map((part) => part[0])
    .slice(0, 2)
    .join("")
    .toUpperCase();

  async function handleLogout() {
    try {
      await apiFetch("/auth/logout", { method: "POST" });
    } finally {
      clearAuth();
      navigate("/login", { replace: true });
    }
  }

  return (
    <aside className={`sidebar ${isOpen ? "sidebar-open" : ""}`}>
      <nav className="sidebar-nav">
        <p className="sidebar-section-title">Attendance System</p>

        {menuItems
          .filter((item) => !item.roles || item.roles.includes(currentUser?.user_role))
          .map((item) => {
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
        <div className="avatar">{initials}</div>

        <div className="sidebar-user-info">
          <strong>{displayName}</strong>
          <span>{currentUser?.user_role || "User"}</span>
        </div>

        <button type="button" className="sidebar-icon-button" aria-label="Sign out" onClick={handleLogout}>
          <LogOut size={18} />
        </button>
      </div>
    </aside>
  );
}

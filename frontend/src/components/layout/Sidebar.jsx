import {
  BarChart3,
  Building2,
  CalendarRange,
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

const menuSections = [
  {
    label: "Overview",
    items: [
      { label: "Dashboard", path: "/dashboard", icon: LayoutDashboard },
    ],
  },
  {
    label: "Attendance Operations",
    items: [
      {
        label: "QR Attendance Kiosk",
        personnelLabel: "QR Attendance",
        path: "/qr-attendance",
        icon: QrCode,
        roles: ["Administrator", "HR", "Supervisor", "Encoder", "Personnel"],
      },
      { label: "Daily Attendance", personnelLabel: "My Attendance", path: "/attendance", icon: Clock3 },
      { label: "DTR Monitoring", personnelLabel: "My DTR", path: "/dtr", icon: FileText },
    ],
  },
  {
    label: "Workforce & Work Rules",
    items: [
      {
        label: "Departments & Office GPS",
        path: "/departments",
        icon: Building2,
        roles: ["Administrator", "HR"],
      },
      {
        label: "Work Schedules",
        path: "/schedules",
        icon: CalendarDays,
        roles: ["Administrator", "HR"],
      },
      {
        label: "Personnel Directory",
        path: "/personnel",
        icon: Users,
        roles: ["Administrator", "HR"],
      },
      { label: "Calendar & Duty Days", path: "/calendar", icon: CalendarRange },
    ],
  },
  {
    label: "Security & Audit",
    items: [
      {
        label: "System Users",
        path: "/system-users",
        icon: ShieldCheck,
        roles: ["Administrator"],
      },
      {
        label: "Activity Logs",
        path: "/activity-logs",
        icon: BarChart3,
        roles: ["Administrator"],
      },
      {
        label: "Settings",
        path: "/settings",
        icon: Settings,
        roles: ["Administrator"],
      },
    ],
  },
];

export default function Sidebar({ isOpen, isCollapsed }) {
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
        {menuSections.map((section) => {
          const visibleItems = section.items.filter(
            (item) => !item.roles || item.roles.includes(currentUser?.user_role),
          );

          if (!visibleItems.length) return null;

          return (
            <div className="sidebar-menu-section" key={section.label}>
              <p className="sidebar-section-title">{section.label}</p>

              {visibleItems.map((item) => {
                const Icon = item.icon;
                const itemLabel = currentUser?.user_role === "Personnel"
                  ? item.personnelLabel || item.label
                  : item.label;

                return (
                  <NavLink
                    key={item.path}
                    to={item.path}
                    title={isCollapsed ? itemLabel : undefined}
                    aria-label={itemLabel}
                    className={({ isActive }) =>
                      isActive ? "nav-link active" : "nav-link"
                    }
                  >
                    <Icon size={18} />
                    <span>{itemLabel}</span>
                  </NavLink>
                );
              })}
            </div>
          );
        })}
      </nav>

      <div className="sidebar-user">
        <div className="avatar">
          {initials}
          {currentUser?.personnel?.photo_url && (
            <img src={currentUser.personnel.photo_url} alt="" />
          )}
        </div>

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

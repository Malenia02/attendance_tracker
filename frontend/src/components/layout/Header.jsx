import {
  Bell,
  Grid3X3,
  Menu,
  Moon,
  Search,
  ShieldCheck,
  Sun,
  UserCircle,
} from "lucide-react";
import { useTheme } from "../../context/ThemeContext";
import { getStoredUser } from "../../lib/auth";

export default function Header({ onToggleSidebar }) {
  const { theme, toggleTheme } = useTheme();
  const currentUser = getStoredUser();
  const displayName = currentUser?.personnel?.full_name || currentUser?.username || "System User";

  return (
    <header className="topbar">
      <div className="header-brand">
        <span className="header-brand-mark">
          <ShieldCheck size={25} />
        </span>
        <span>DILG Admin</span>
      </div>

      <div className="topbar-left">
        <button
          type="button"
          className="square-button menu-button"
          onClick={onToggleSidebar}
          aria-label="Toggle sidebar"
        >
          <Menu size={20} />
        </button>

        <div className="topbar-search">
          <Search size={19} />
          <input
            type="text"
            placeholder="Search personnel, attendance, DTR..."
          />
        </div>
      </div>

      <div className="topbar-actions">
        <button type="button" className="square-button">
          <Grid3X3 size={18} />
        </button>

        <button type="button" className="square-button notification-button">
          <Bell size={18} />
          <span className="notification-badge">4</span>
        </button>

        <button
          type="button"
          className="square-button"
          onClick={toggleTheme}
          title="Toggle theme"
          aria-label="Toggle theme"
        >
          {theme === "dark" ? <Sun size={18} /> : <Moon size={18} />}
        </button>

        <div className="topbar-user">
          <UserCircle size={34} />
          <div>
            <strong>{displayName}</strong>
            <span>{currentUser?.user_role || "User"}</span>
          </div>
        </div>
      </div>
    </header>
  );
}

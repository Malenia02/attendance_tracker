import {
  Bell,
  Grid3X3,
  Menu,
  Moon,
  Search,
  Sun,
  UserCircle,
} from "lucide-react";
import DilgSeal from "../branding/DilgSeal";
import { useTheme } from "../../context/ThemeContext";
import { getStoredUser } from "../../lib/auth";

export default function Header({ onToggleSidebar, sidebarToggled }) {
  const { theme, toggleTheme } = useTheme();
  const currentUser = getStoredUser();
  const displayName = currentUser?.personnel?.full_name || currentUser?.username || "System User";

  return (
    <header className="topbar">
      <div className="header-brand">
        <span className="header-brand-mark">
          <DilgSeal />
        </span>
        <span>DILG GIP</span>
      </div>

      <div className="topbar-left">
        <button
          type="button"
          className="square-button menu-button"
          onClick={onToggleSidebar}
          aria-label="Toggle sidebar"
          aria-pressed={sidebarToggled}
          title={sidebarToggled ? "Expand sidebar" : "Collapse sidebar"}
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
          <span className="topbar-avatar">
            <UserCircle size={34} />
            {currentUser?.personnel?.photo_url && (
              <img src={currentUser.personnel.photo_url} alt="" />
            )}
          </span>
          <div>
            <strong>{displayName}</strong>
            <span>{currentUser?.user_role || "User"}</span>
          </div>
        </div>
      </div>
    </header>
  );
}

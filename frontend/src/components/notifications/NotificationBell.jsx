import { useCallback, useEffect, useRef, useState } from "react";
import { useNavigate } from "react-router-dom";
import {
  Bell,
  BellRing,
  CheckCheck,
  ChevronRight,
  CircleAlert,
  Clock3,
  FileCheck2,
  LoaderCircle,
  ShieldAlert,
} from "lucide-react";
import { apiFetch } from "../../lib/auth";

const iconFor = (type) => {
  if (type.includes("attendance") || type.includes("time")) return Clock3;
  if (type.includes("dtr") || type.includes("leave")) return FileCheck2;
  if (type.includes("security")) return ShieldAlert;
  return CircleAlert;
};

const relativeTime = (value) => {
  if (!value) return "Just now";
  const seconds = Math.max(0, Math.floor((Date.now() - new Date(value).getTime()) / 1000));
  if (seconds < 60) return "Just now";
  if (seconds < 3600) return `${Math.floor(seconds / 60)}m ago`;
  if (seconds < 86400) return `${Math.floor(seconds / 3600)}h ago`;
  if (seconds < 604800) return `${Math.floor(seconds / 86400)}d ago`;
  return new Intl.DateTimeFormat("en-PH", { month: "short", day: "numeric" }).format(new Date(value));
};

export default function NotificationBell() {
  const navigate = useNavigate();
  const rootRef = useRef(null);
  const [open, setOpen] = useState(false);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [items, setItems] = useState([]);
  const [unreadCount, setUnreadCount] = useState(0);

  const loadSummary = useCallback(async ({ quiet = false } = {}) => {
    if (!quiet) setLoading(true);
    try {
      const response = await apiFetch("/notifications/summary");
      const payload = await response.json().catch(() => ({}));
      if (!response.ok) throw new Error(payload.message || "Notifications are unavailable.");
      setItems(Array.isArray(payload.data) ? payload.data : []);
      setUnreadCount(Number(payload.unread_count || 0));
      setError("");
    } catch (loadError) {
      if (loadError.name !== "AbortError") setError(loadError.message);
    } finally {
      if (!quiet) setLoading(false);
    }
  }, []);

  useEffect(() => {
    const initialLoadId = window.setTimeout(() => loadSummary(), 0);
    const intervalId = window.setInterval(() => loadSummary({ quiet: true }), 60_000);
    const onFocus = () => loadSummary({ quiet: true });
    const onUpdated = () => loadSummary({ quiet: true });
    window.addEventListener("focus", onFocus);
    window.addEventListener("dilg:notifications-updated", onUpdated);
    return () => {
      window.clearTimeout(initialLoadId);
      window.clearInterval(intervalId);
      window.removeEventListener("focus", onFocus);
      window.removeEventListener("dilg:notifications-updated", onUpdated);
    };
  }, [loadSummary]);

  useEffect(() => {
    if (!open) return undefined;
    const closeOutside = (event) => {
      if (!rootRef.current?.contains(event.target)) setOpen(false);
    };
    const closeOnEscape = (event) => {
      if (event.key === "Escape") setOpen(false);
    };
    document.addEventListener("mousedown", closeOutside);
    document.addEventListener("keydown", closeOnEscape);
    return () => {
      document.removeEventListener("mousedown", closeOutside);
      document.removeEventListener("keydown", closeOnEscape);
    };
  }, [open]);

  const markRead = async (notification) => {
    if (!notification.is_read) {
      const response = await apiFetch(`/notifications/${notification.id}/read`, { method: "PATCH" });
      if (response.ok) {
        setItems((current) => current.map((item) => (
          item.id === notification.id ? { ...item, is_read: true } : item
        )));
        setUnreadCount((count) => Math.max(0, count - 1));
      }
    }
    if (notification.action_url?.startsWith("/") && !notification.action_url.startsWith("//")) {
      setOpen(false);
      navigate(notification.action_url);
    }
  };

  const markAllRead = async () => {
    const response = await apiFetch("/notifications/read-all", { method: "PATCH" });
    if (!response.ok) return;
    setItems((current) => current.map((item) => ({ ...item, is_read: true })));
    setUnreadCount(0);
    window.dispatchEvent(new Event("dilg:notifications-updated"));
  };

  return (
    <div className="notification-bell" ref={rootRef}>
      <button
        type="button"
        className={`square-button notification-button${open ? " active" : ""}`}
        onClick={() => setOpen((value) => !value)}
        aria-label={`Notifications${unreadCount ? `, ${unreadCount} unread` : ""}`}
        aria-expanded={open}
        aria-haspopup="dialog"
      >
        {unreadCount ? <BellRing size={18} /> : <Bell size={18} />}
        {unreadCount > 0 && (
          <span className="notification-badge">{unreadCount > 99 ? "99+" : unreadCount}</span>
        )}
      </button>

      {open && (
        <section className="notification-popover" role="dialog" aria-label="Recent notifications">
          <header>
            <div>
              <span>Notification Center</span>
              <strong>{unreadCount ? `${unreadCount} unread` : "You are up to date"}</strong>
            </div>
            {unreadCount > 0 && (
              <button type="button" onClick={markAllRead}><CheckCheck size={15} /> Mark all read</button>
            )}
          </header>

          <div className="notification-popover-list" aria-live="polite">
            {loading ? (
              <div className="notification-empty"><LoaderCircle className="spin" size={24} /><span>Loading alerts…</span></div>
            ) : error ? (
              <div className="notification-empty error"><CircleAlert size={24} /><span>{error}</span><button type="button" onClick={() => loadSummary()}>Try again</button></div>
            ) : items.length === 0 ? (
              <div className="notification-empty"><CheckCheck size={26} /><strong>No active notifications</strong><span>New workflow alerts will appear here.</span></div>
            ) : items.map((item) => {
              const Icon = iconFor(item.type);
              return (
                <button
                  type="button"
                  className={`notification-preview severity-${item.severity?.toLowerCase()}${item.is_read ? " read" : " unread"}`}
                  key={item.id}
                  onClick={() => markRead(item)}
                >
                  <span className="notification-preview-icon"><Icon size={17} /></span>
                  <span className="notification-preview-copy">
                    <strong>{item.title}</strong>
                    <small>{item.message}</small>
                    <time>{relativeTime(item.created_at)}</time>
                  </span>
                  <ChevronRight size={15} />
                </button>
              );
            })}
          </div>

          <footer>
            <button type="button" onClick={() => { setOpen(false); navigate("/notifications"); }}>
              View all notifications <ChevronRight size={15} />
            </button>
          </footer>
        </section>
      )}
    </div>
  );
}

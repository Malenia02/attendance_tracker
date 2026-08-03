import { useCallback, useEffect, useState } from "react";
import { useNavigate } from "react-router-dom";
import {
  BellRing,
  Check,
  CheckCheck,
  CircleAlert,
  Clock3,
  FileCheck2,
  LoaderCircle,
  RefreshCw,
  ShieldAlert,
} from "lucide-react";
import Pagination from "../components/common/Pagination";
import { apiFetch } from "../lib/auth";

const ICONS = {
  account_security: ShieldAlert,
  attendance_verification: Clock3,
  missing_time_outs: Clock3,
  correction_requests: Clock3,
  leave_requests: FileCheck2,
  leave_decision: FileCheck2,
  returned_dtrs: FileCheck2,
};

const formatDate = (value) => new Intl.DateTimeFormat("en-PH", {
  dateStyle: "medium",
  timeStyle: "short",
}).format(new Date(value));

export default function Notifications() {
  const navigate = useNavigate();
  const [status, setStatus] = useState("all");
  const [page, setPage] = useState(1);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [items, setItems] = useState([]);
  const [unreadCount, setUnreadCount] = useState(0);
  const [pagination, setPagination] = useState(null);

  const loadNotifications = useCallback(async () => {
    setLoading(true);
    setError("");
    try {
      const params = new URLSearchParams({ status, page: String(page), per_page: "15" });
      const response = await apiFetch(`/notifications?${params}`);
      const payload = await response.json().catch(() => ({}));
      if (!response.ok) throw new Error(payload.message || "Notifications could not be loaded.");
      setItems(Array.isArray(payload.data) ? payload.data : []);
      setUnreadCount(Number(payload.unread_count || 0));
      setPagination(payload.meta?.pagination || null);
    } catch (loadError) {
      setError(loadError.message);
    } finally {
      setLoading(false);
    }
  }, [page, status]);

  useEffect(() => {
    const loadId = window.setTimeout(() => loadNotifications(), 0);
    return () => window.clearTimeout(loadId);
  }, [loadNotifications]);

  const updateBell = () => window.dispatchEvent(new Event("dilg:notifications-updated"));

  const openNotification = async (notification) => {
    if (!notification.is_read) {
      const response = await apiFetch(`/notifications/${notification.id}/read`, { method: "PATCH" });
      if (response.ok) {
        setItems((current) => status === "unread"
          ? current.filter((item) => item.id !== notification.id)
          : current.map((item) => item.id === notification.id ? { ...item, is_read: true } : item));
        setUnreadCount((value) => Math.max(0, value - 1));
        updateBell();
      }
    }
    if (notification.action_url?.startsWith("/") && !notification.action_url.startsWith("//")) {
      navigate(notification.action_url);
    }
  };

  const markAllRead = async () => {
    const response = await apiFetch("/notifications/read-all", { method: "PATCH" });
    if (!response.ok) return;
    setUnreadCount(0);
    setItems((current) => status === "unread" ? [] : current.map((item) => ({ ...item, is_read: true })));
    updateBell();
  };

  return (
    <section className="notifications-page">
      <header className="notifications-hero">
        <div>
          <span><BellRing size={15} /> Secured workflow inbox</span>
          <h1>Notification Center</h1>
          <p>Role-aware alerts for attendance, leave, DTR, personnel setup, and account security.</p>
        </div>
        <div className="notifications-hero-count">
          <small>Unread alerts</small>
          <strong>{unreadCount}</strong>
          <span>Only notifications assigned to your account are visible.</span>
        </div>
      </header>

      <div className="panel notifications-panel">
        <div className="notifications-toolbar">
          <div className="notification-tabs" role="tablist" aria-label="Notification status">
            {[['all', 'All'], ['unread', 'Unread']].map(([value, label]) => (
              <button
                type="button"
                role="tab"
                aria-selected={status === value}
                className={status === value ? "active" : ""}
                key={value}
                onClick={() => { setStatus(value); setPage(1); }}
              >{label}{value === "unread" && unreadCount > 0 ? ` (${unreadCount})` : ""}</button>
            ))}
          </div>
          <div>
            <button type="button" className="notification-tool-button" onClick={loadNotifications} disabled={loading}>
              <RefreshCw size={15} className={loading ? "spin" : ""} /> Refresh
            </button>
            <button type="button" className="notification-tool-button primary" onClick={markAllRead} disabled={!unreadCount}>
              <CheckCheck size={15} /> Mark all read
            </button>
          </div>
        </div>

        <div className="notifications-list" aria-live="polite">
          {loading ? (
            <div className="notifications-state"><LoaderCircle className="spin" size={28} /><strong>Loading your notifications…</strong></div>
          ) : error ? (
            <div className="notifications-state error"><CircleAlert size={28} /><strong>Notification Center is unavailable</strong><span>{error}</span><button type="button" onClick={loadNotifications}>Try again</button></div>
          ) : items.length === 0 ? (
            <div className="notifications-state complete"><CheckCheck size={30} /><strong>{status === "unread" ? "No unread notifications" : "No active notifications"}</strong><span>Your inbox is clear.</span></div>
          ) : items.map((item) => {
            const Icon = ICONS[item.type] || CircleAlert;
            return (
              <article className={`notification-row severity-${item.severity?.toLowerCase()}${item.is_read ? " read" : " unread"}`} key={item.id}>
                <span className="notification-row-icon"><Icon size={19} /></span>
                <div>
                  <span className="notification-row-heading"><strong>{item.title}</strong>{!item.is_read && <i>New</i>}</span>
                  <p>{item.message}</p>
                  <time>{formatDate(item.created_at)}</time>
                </div>
                <button type="button" onClick={() => openNotification(item)}>
                  {item.is_read ? "Open" : <><Check size={15} /> Review</>}
                </button>
              </article>
            );
          })}
        </div>

        <Pagination
          pagination={pagination}
          onPageChange={setPage}
          disabled={loading}
          itemLabel="notifications"
        />
      </div>
    </section>
  );
}

import { useEffect, useRef, useState } from "react";
import { CircleAlert, CircleCheck, X } from "lucide-react";
import { APP_TOAST_EVENT } from "../../lib/toastEvents";

const SUCCESS_TTL_MS = 5500;
const ERROR_TTL_MS = 10000;

export default function ToastViewport() {
  const [toasts, setToasts] = useState([]);
  const timers = useRef(new Map());

  function dismissToast(id) {
    window.clearTimeout(timers.current.get(id));
    timers.current.delete(id);
    setToasts((current) => current.filter((item) => item.id !== id));
  }

  useEffect(() => {
    const activeTimers = timers.current;

    function addToast(event) {
      const toast = event.detail;
      if (!toast?.message) return;

      setToasts((current) => [...current.slice(-2), toast]);
      const timeoutId = window.setTimeout(() => {
        activeTimers.delete(toast.id);
        setToasts((current) => current.filter((item) => item.id !== toast.id));
      }, toast.type === "success" ? SUCCESS_TTL_MS : ERROR_TTL_MS);
      activeTimers.set(toast.id, timeoutId);
    }

    window.addEventListener(APP_TOAST_EVENT, addToast);
    return () => {
      window.removeEventListener(APP_TOAST_EVENT, addToast);
      activeTimers.forEach((timeoutId) => window.clearTimeout(timeoutId));
      activeTimers.clear();
    };
  }, []);

  if (!toasts.length) return null;

  return (
    <div className="toast-viewport" aria-label="System messages">
      {toasts.map((toast) => {
        const isSuccess = toast.type === "success";
        const Icon = isSuccess ? CircleCheck : CircleAlert;

        return (
          <div
            className={`app-toast ${isSuccess ? "success" : "error"}`}
            key={toast.id}
            role={isSuccess ? "status" : "alert"}
          >
            <span className="app-toast-icon"><Icon size={21} /></span>
            <div className="app-toast-content">
              <strong>{isSuccess ? "Action completed" : "Action not completed"}</strong>
              <p>{toast.message}</p>
              {toast.meta && <small>{toast.meta}</small>}
            </div>
            <button
              type="button"
              className="app-toast-dismiss"
              onClick={() => dismissToast(toast.id)}
              aria-label={`Dismiss ${isSuccess ? "success" : "error"} message`}
              title="Dismiss message"
            >
              <X size={17} />
            </button>
          </div>
        );
      })}
    </div>
  );
}

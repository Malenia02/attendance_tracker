import { useEffect, useState } from "react";
import { BadgeCheck, X } from "lucide-react";
import { APP_TOAST_EVENT } from "../../lib/toastEvents";

const TOAST_TTL_MS = 4200;

export default function ToastViewport() {
  const [toasts, setToasts] = useState([]);

  useEffect(() => {
    function addToast(event) {
      const toast = event.detail;
      if (!toast?.message) return;

      setToasts((current) => [...current.slice(-3), toast]);
      window.setTimeout(() => {
        setToasts((current) => current.filter((item) => item.id !== toast.id));
      }, TOAST_TTL_MS);
    }

    window.addEventListener(APP_TOAST_EVENT, addToast);
    return () => window.removeEventListener(APP_TOAST_EVENT, addToast);
  }, []);

  if (!toasts.length) return null;

  return (
    <div className="toast-viewport" aria-live="polite" aria-label="System messages">
      {toasts.map((toast) => {
        const isSuccess = toast.type === "success";
        const Icon = isSuccess ? BadgeCheck : X;

        return (
          <div className={`app-toast ${isSuccess ? "success" : "error"}`} key={toast.id}>
            <span><Icon size={17} /></span>
            <div>
              <strong>{isSuccess ? "Saved" : "Action failed"}</strong>
              <p>{toast.message}</p>
              {toast.meta && <small>{toast.meta}</small>}
            </div>
          </div>
        );
      })}
    </div>
  );
}

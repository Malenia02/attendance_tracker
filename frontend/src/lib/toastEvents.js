export const APP_TOAST_EVENT = "dilg:toast";

export function notifyToast(toast) {
  window.dispatchEvent(new CustomEvent(APP_TOAST_EVENT, {
    detail: {
      id: `${Date.now()}-${Math.random().toString(16).slice(2)}`,
      type: toast.type || "info",
      message: toast.message,
      meta: toast.meta || "",
    },
  }));
}

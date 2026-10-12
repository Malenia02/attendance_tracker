import { useEffect, useRef } from "react";
import { createPortal } from "react-dom";

let openModalCount = 0;
let originalBodyOverflow = "";
const modalStack = [];

const FOCUSABLE_SELECTOR = [
  "a[href]",
  "button:not([disabled])",
  "input:not([disabled]):not([type='hidden'])",
  "select:not([disabled])",
  "textarea:not([disabled])",
  "[tabindex]:not([tabindex='-1'])",
].join(",");

export default function ModalPortal({ children, onClose, closeOnEscape = true }) {
  const rootRef = useRef(null);
  const onCloseRef = useRef(onClose);

  useEffect(() => {
    onCloseRef.current = onClose;
  }, [onClose]);

  useEffect(() => {
    const modalId = Symbol("modal");
    const previouslyFocused = document.activeElement;

    if (openModalCount === 0) {
      originalBodyOverflow = document.body.style.overflow;
      document.body.style.overflow = "hidden";
    }
    openModalCount += 1;
    modalStack.push(modalId);

    const root = rootRef.current;
    const dialog = root?.querySelector('[role="dialog"], [role="alertdialog"]');
    const focusTarget = root?.querySelector("[autofocus]")
      || root?.querySelector(FOCUSABLE_SELECTOR)
      || dialog;

    if (dialog && !dialog.hasAttribute("tabindex")) dialog.setAttribute("tabindex", "-1");
    const focusFrame = window.requestAnimationFrame(() => focusTarget?.focus());

    function handleKeyDown(event) {
      if (modalStack[modalStack.length - 1] !== modalId) return;

      if (event.key === "Escape" && closeOnEscape && onCloseRef.current) {
        event.preventDefault();
        onCloseRef.current();
        return;
      }

      if (event.key !== "Tab" || !root) return;

      const focusable = [...root.querySelectorAll(FOCUSABLE_SELECTOR)]
        .filter((element) => !element.hasAttribute("disabled") && element.getClientRects().length);

      if (!focusable.length) {
        event.preventDefault();
        dialog?.focus();
        return;
      }

      const first = focusable[0];
      const last = focusable[focusable.length - 1];
      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
      }
    }

    document.addEventListener("keydown", handleKeyDown);

    return () => {
      window.cancelAnimationFrame(focusFrame);
      document.removeEventListener("keydown", handleKeyDown);
      const stackIndex = modalStack.lastIndexOf(modalId);
      if (stackIndex >= 0) modalStack.splice(stackIndex, 1);
      openModalCount = Math.max(0, openModalCount - 1);
      if (openModalCount === 0) document.body.style.overflow = originalBodyOverflow;
      if (previouslyFocused instanceof HTMLElement) previouslyFocused.focus();
    };
  }, [closeOnEscape]);

  if (typeof document === "undefined") return null;

  return createPortal(<div className="modal-portal-root" ref={rootRef}>{children}</div>, document.body);
}

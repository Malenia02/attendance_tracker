import { useEffect } from "react";
import { createPortal } from "react-dom";

export default function ModalPortal({ children }) {
  useEffect(() => {
    const previousOverflow = document.body.style.overflow;
    document.body.style.overflow = "hidden";

    return () => {
      document.body.style.overflow = previousOverflow;
    };
  }, []);

  if (typeof document === "undefined") return null;

  return createPortal(children, document.body);
}

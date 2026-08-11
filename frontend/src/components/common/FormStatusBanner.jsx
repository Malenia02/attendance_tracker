import { BadgeCheck, X } from "lucide-react";

export default function FormStatusBanner({ status }) {
  if (!status?.message) return null;

  const isSuccess = status.type === "success";
  const Icon = isSuccess ? BadgeCheck : X;

  return (
    <div
      className={`form-status-banner ${isSuccess ? "success" : "error"}`}
      role={isSuccess ? "status" : "alert"}
      aria-live={isSuccess ? "polite" : "assertive"}
    >
      <Icon size={17} />
      <span>{status.message}</span>
    </div>
  );
}

import { CircleAlert, CircleCheck } from "lucide-react";

export default function FormStatusBanner({ status }) {
  if (!status?.message) return null;

  const isSuccess = status.type === "success";
  const Icon = isSuccess ? CircleCheck : CircleAlert;

  return (
    <div
      className={`form-status-banner ${isSuccess ? "success" : "error"}`}
      role={isSuccess ? "status" : "alert"}
      aria-live={isSuccess ? "polite" : "assertive"}
    >
      <span className="form-status-icon"><Icon size={21} /></span>
      <span className="form-status-copy">
        <strong>{isSuccess ? "Action completed" : "Action not completed"}</strong>
        <span>{status.message}</span>
      </span>
    </div>
  );
}

import { ChevronLeft, ChevronRight } from "lucide-react";

export default function Pagination({
  pagination,
  onPageChange,
  disabled = false,
  itemLabel = "records",
}) {
  if (!pagination || pagination.last_page <= 1) return null;

  const current = Number(pagination.current_page || 1);
  const last = Number(pagination.last_page || 1);
  const from = pagination.from ?? 0;
  const to = pagination.to ?? 0;
  const total = pagination.total ?? 0;

  return (
    <nav className="records-pagination" aria-label={`${itemLabel} pagination`}>
      <span>Showing {from}–{to} of {total} {itemLabel}</span>
      <div>
        <button
          type="button"
          disabled={disabled || current <= 1}
          onClick={() => onPageChange(current - 1)}
        >
          <ChevronLeft size={15} /> Previous
        </button>
        <strong>Page {current} of {last}</strong>
        <button
          type="button"
          disabled={disabled || current >= last}
          onClick={() => onPageChange(current + 1)}
        >
          Next <ChevronRight size={15} />
        </button>
      </div>
    </nav>
  );
}

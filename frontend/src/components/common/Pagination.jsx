import { ChevronLeft, ChevronRight, ChevronsLeft, ChevronsRight } from "lucide-react";

const DEFAULT_PAGE_SIZES = [15, 25, 50, 100];

function pageItems(current, last) {
  if (last <= 7) return Array.from({ length: last }, (_, index) => index + 1);

  const pages = new Set([1, last, current - 1, current, current + 1]);
  const ordered = [...pages].filter((page) => page >= 1 && page <= last).sort((a, b) => a - b);

  return ordered.flatMap((page, index) => {
    if (index === 0 || page === ordered[index - 1] + 1) return [page];
    return [`ellipsis-${page}`, page];
  });
}

export default function Pagination({
  pagination,
  onPageChange,
  perPage,
  onPerPageChange,
  perPageOptions = DEFAULT_PAGE_SIZES,
  disabled = false,
  loading = false,
  itemLabel = "records",
  placement = "bottom",
  display = "all",
}) {
  if (!pagination) return null;

  const current = Number(pagination.current_page || 1);
  const last = Number(pagination.last_page || 1);
  const from = pagination.from ?? 0;
  const to = pagination.to ?? 0;
  const total = pagination.total ?? 0;
  const showSummary = display !== "page-size";
  const showPageSize = display !== "navigation" && Boolean(onPerPageChange);
  const showNavigation = display !== "page-size";

  return (
    <nav className={`records-pagination ${placement} ${display}${loading ? " is-loading" : ""}`} aria-label={`${itemLabel} pagination`} aria-busy={loading}>
      {(showSummary || showPageSize) && (
        <div className="records-pagination-summary">
          {showSummary && <span>{total ? `Showing ${from}-${to} of ${total} ${itemLabel}` : `No ${itemLabel}`}</span>}
          {showPageSize && (
          <label>
            Rows per page
            <select
              value={perPage ?? pagination.per_page ?? 15}
              onChange={(event) => onPerPageChange(Number(event.target.value))}
              disabled={disabled || loading}
              aria-label={`Rows per page for ${itemLabel}`}
            >
              {perPageOptions.map((size) => <option key={size} value={size}>{size}</option>)}
            </select>
          </label>
          )}
        </div>
      )}
      {showNavigation && <div className="records-pagination-controls">
        <button type="button" className="page-edge" disabled={disabled || loading || current <= 1} onClick={() => onPageChange(1)} aria-label="First page">
          <ChevronsLeft size={15} />
        </button>
        <button type="button" disabled={disabled || loading || current <= 1} onClick={() => onPageChange(current - 1)}>
          <ChevronLeft size={15} /> Previous
        </button>
        <div className="records-page-numbers" aria-label={`Page ${current} of ${last}`}>
          {pageItems(current, last).map((item) => typeof item === "number" ? (
            <button
              type="button"
              className={item === current ? "active" : ""}
              aria-current={item === current ? "page" : undefined}
              disabled={disabled || loading}
              onClick={() => onPageChange(item)}
              key={item}
            >
              {item}
            </button>
          ) : <span key={item} aria-hidden="true">...</span>)}
        </div>
        <button type="button" disabled={disabled || loading || current >= last} onClick={() => onPageChange(current + 1)}>
          Next <ChevronRight size={15} />
        </button>
        <button type="button" className="page-edge" disabled={disabled || loading || current >= last} onClick={() => onPageChange(last)} aria-label="Last page">
          <ChevronsRight size={15} />
        </button>
      </div>}
    </nav>
  );
}

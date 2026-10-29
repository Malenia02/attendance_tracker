import {
  Bug,
  CheckCircle2,
  CircleAlert,
  Eye,
  FileWarning,
  MessageSquareWarning,
  RefreshCw,
  Search,
  Send,
  ShieldCheck,
  Wrench,
  X,
} from "lucide-react";
import { useEffect, useMemo, useState } from "react";
import { useLocation } from "react-router";
import FormDraftNotice from "../components/common/FormDraftNotice";
import FormStatusBanner from "../components/common/FormStatusBanner";
import ModalPortal from "../components/common/ModalPortal";
import Pagination from "../components/common/Pagination";
import { TableSkeletonRows } from "../components/common/Skeletons";
import useSessionFormDraft from "../hooks/useSessionFormDraft";
import { apiFetch } from "../lib/auth";
import { formDraftKey } from "../lib/formDrafts";

const CATEGORIES = ["Attendance", "DTR", "Account Access", "Performance", "Incorrect Data", "Other"];
const STATUSES = ["Open", "In Progress", "Resolved", "Closed"];

async function readResponse(response) {
  const payload = await response.json().catch(() => ({}));
  if (!response.ok) {
    const error = new Error(payload.message || "The request could not be completed.");
    error.fields = payload.errors || payload.error?.details || {};
    throw error;
  }
  return payload;
}

function formatDate(value) {
  if (!value) return "—";
  return new Intl.DateTimeFormat("en-PH", {
    dateStyle: "medium",
    timeStyle: "short",
    timeZone: "Asia/Manila",
  }).format(new Date(value));
}

function statusClass(status) {
  return String(status || "").toLowerCase().replaceAll(" ", "-");
}

export default function IssueReports() {
  const location = useLocation();
  const sourcePage = typeof location.state?.from === "string"
    && location.state.from.startsWith("/")
    && !location.state.from.startsWith("//")
    && !location.state.from.startsWith("/issue-reports")
    ? location.state.from
    : "";
  const initialForm = useMemo(() => ({
    category: "Attendance",
    subject: "",
    description: "",
    page_url: sourcePage,
  }), [sourcePage]);
  const draftKey = useMemo(() => formDraftKey("system-issue-report"), []);
  const [form, setForm] = useState(initialForm);
  const [records, setRecords] = useState([]);
  const [summary, setSummary] = useState({ total: 0, open: 0, in_progress: 0, resolved: 0 });
  const [options, setOptions] = useState({ categories: CATEGORIES, statuses: STATUSES });
  const [meta, setMeta] = useState({ can_review: false });
  const [pagination, setPagination] = useState(null);
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(15);
  const [search, setSearch] = useState("");
  const [statusFilter, setStatusFilter] = useState("");
  const [categoryFilter, setCategoryFilter] = useState("");
  const [sort, setSort] = useState("newest");
  const [loading, setLoading] = useState(true);
  const [refreshKey, setRefreshKey] = useState(0);
  const [loadError, setLoadError] = useState("");
  const [formStatus, setFormStatus] = useState(null);
  const [fieldErrors, setFieldErrors] = useState({});
  const [submitting, setSubmitting] = useState(false);
  const [selected, setSelected] = useState(null);
  const [reviewForm, setReviewForm] = useState({ status: "Open", admin_notes: "" });
  const [reviewStatus, setReviewStatus] = useState(null);
  const [reviewing, setReviewing] = useState(false);
  const { draftRestored, discardDraft } = useSessionFormDraft({
    isOpen: true,
    draftKey,
    initialForm,
    form,
    setForm,
  });

  useEffect(() => {
    if (!selected) return undefined;
    const previousOverflow = document.body.style.overflow;
    document.body.style.overflow = "hidden";
    return () => { document.body.style.overflow = previousOverflow; };
  }, [selected]);

  useEffect(() => {
    const controller = new AbortController();
    const timer = window.setTimeout(() => {
      const params = new URLSearchParams({ page: String(page), per_page: String(perPage), sort });
      if (search.trim()) params.set("search", search.trim());
      if (statusFilter) params.set("status", statusFilter);
      if (categoryFilter) params.set("category", categoryFilter);

      apiFetch(`/issue-reports?${params}`, { signal: controller.signal })
        .then(readResponse)
        .then((payload) => {
          setRecords(payload.data || []);
          setSummary(payload.summary || { total: 0, open: 0, in_progress: 0, resolved: 0 });
          setOptions(payload.options || { categories: CATEGORIES, statuses: STATUSES });
          setMeta(payload.meta || { can_review: false });
          setPagination(payload.meta?.pagination || null);
          setLoadError("");
          if (!payload.data?.length && page > 1) setPage((current) => Math.max(1, current - 1));
        })
        .catch((error) => {
          if (error.name !== "AbortError") setLoadError(error.message);
        })
        .finally(() => {
          if (!controller.signal.aborted) setLoading(false);
        });
    }, 350);

    return () => {
      window.clearTimeout(timer);
      controller.abort();
    };
  }, [page, perPage, search, statusFilter, categoryFilter, sort, refreshKey]);

  function updateForm(field, value) {
    setForm((current) => ({ ...current, [field]: value }));
    setFieldErrors((current) => ({ ...current, [field]: undefined }));
    setFormStatus(null);
  }

  async function submitReport(event) {
    event.preventDefault();
    setSubmitting(true);
    setFieldErrors({});
    setFormStatus(null);

    try {
      const payload = await apiFetch("/issue-reports", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(form),
      }).then(readResponse);
      setFormStatus({ type: "success", message: payload.message });
      discardDraft();
      setPage(1);
      setRefreshKey((key) => key + 1);
    } catch (error) {
      setFieldErrors(error.fields || {});
      setFormStatus({ type: "error", message: error.message });
    } finally {
      setSubmitting(false);
    }
  }

  function openReport(report) {
    setSelected(report);
    setReviewForm({ status: report.status, admin_notes: report.admin_notes || "" });
    setReviewStatus(null);
  }

  function closeReport() {
    if (reviewing) return;
    setSelected(null);
    setReviewStatus(null);
  }

  async function submitReview(event) {
    event.preventDefault();
    if (!selected || !meta.can_review) return;
    setReviewing(true);
    setReviewStatus(null);

    try {
      const payload = await apiFetch(`/issue-reports/${selected.id}`, {
        method: "PATCH",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(reviewForm),
      }).then(readResponse);
      setReviewStatus({ type: "success", message: payload.message });
      setSelected(payload.issue_report);
      setRefreshKey((key) => key + 1);
    } catch (error) {
      setReviewStatus({ type: "error", message: error.message });
    } finally {
      setReviewing(false);
    }
  }

  const summaryCards = [
    [FileWarning, "All reports", summary.total, "blue"],
    [CircleAlert, "Open", summary.open, "orange"],
    [Wrench, "In progress", summary.in_progress, "purple"],
    [CheckCircle2, "Resolved", summary.resolved, "green"],
  ];

  return (
    <section className="issue-reports-page">
      <header className="issue-reports-hero">
        <div>
          <span><MessageSquareWarning size={15} /> System support</span>
          <h1>Report a System Issue</h1>
          <p>Tell the system administrator what happened, where it happened, and what you expected to happen.</p>
        </div>
        <aside><ShieldCheck size={25} /><div><strong>Do not include passwords</strong><span>Never submit passwords, tokens, QR secrets, or private credentials.</span></div></aside>
      </header>

      <section className="issue-summary" aria-label="Issue report summary">
        {summaryCards.map(([Icon, label, value, tone]) => (
          <article className={tone} key={label}><Icon size={20} /><div><strong>{value}</strong><span>{label}</span></div></article>
        ))}
      </section>

      <section className="issue-submit-panel">
        <div className="issue-panel-heading"><div><span>NEW REPORT</span><h2>What problem did you encounter?</h2><p>Provide enough detail for an administrator to reproduce the problem.</p></div><Bug size={25} /></div>
        <FormDraftNotice restored={draftRestored} onDiscard={discardDraft} />
        <FormStatusBanner status={formStatus} />
        <form className="issue-report-form" onSubmit={submitReport}>
          <label><span>Category</span><select value={form.category} onChange={(event) => updateForm("category", event.target.value)}>{options.categories.map((category) => <option value={category} key={category}>{category}</option>)}</select>{fieldErrors.category?.[0] && <small className="field-error">{fieldErrors.category[0]}</small>}</label>
          <label className="issue-subject-field"><span>Short summary</span><input value={form.subject} maxLength={120} onChange={(event) => updateForm("subject", event.target.value)} placeholder="Example: Time In button stays loading" required />{fieldErrors.subject?.[0] && <small className="field-error">{fieldErrors.subject[0]}</small>}</label>
          <label className="issue-description-field"><span>What happened?</span><textarea value={form.description} maxLength={3000} rows={5} onChange={(event) => updateForm("description", event.target.value)} placeholder="Describe the steps you took, what appeared on screen, and what you expected instead." required />{fieldErrors.description?.[0] && <small className="field-error">{fieldErrors.description[0]}</small>}<em>{form.description.length}/3000</em></label>
          <label className="issue-page-field"><span>Affected page <small>Optional</small></span><input value={form.page_url} maxLength={500} onChange={(event) => updateForm("page_url", event.target.value)} placeholder="/attendance or /dtr" />{fieldErrors.page_url?.[0] && <small className="field-error">{fieldErrors.page_url[0]}</small>}</label>
          <div className="issue-submit-actions"><p><ShieldCheck size={16} /> Your account name and submission time are recorded for follow-up.</p><button type="submit" disabled={submitting}><Send size={16} />{submitting ? "Submitting…" : "Submit issue report"}</button></div>
        </form>
      </section>

      <section className="issue-list-panel">
        <div className="issue-list-heading"><div><span>{meta.can_review ? "ADMINISTRATOR QUEUE" : "YOUR REPORTS"}</span><h2>{meta.can_review ? "Reported system issues" : "Your submitted issues"}</h2></div><button type="button" onClick={() => { setLoading(true); setRefreshKey((key) => key + 1); }} disabled={loading}><RefreshCw size={15} className={loading ? "spinning" : ""} />Refresh</button></div>
        <div className="issue-filters">
          <label className="issue-search"><Search size={16} /><input value={search} onChange={(event) => { setSearch(event.target.value); setPage(1); }} placeholder="Search issue reports…" aria-label="Search issue reports" /></label>
          <select value={statusFilter} onChange={(event) => { setStatusFilter(event.target.value); setPage(1); }} aria-label="Filter reports by status"><option value="">All statuses</option>{options.statuses.map((status) => <option value={status} key={status}>{status}</option>)}</select>
          <select value={categoryFilter} onChange={(event) => { setCategoryFilter(event.target.value); setPage(1); }} aria-label="Filter reports by category"><option value="">All categories</option>{options.categories.map((category) => <option value={category} key={category}>{category}</option>)}</select>
          <select value={sort} onChange={(event) => { setSort(event.target.value); setPage(1); }} aria-label="Sort issue reports"><option value="newest">Newest first</option><option value="oldest">Oldest first</option></select>
        </div>

        {loadError && <div className="issue-load-error" role="alert"><CircleAlert size={18} /><span>{loadError}</span></div>}
        <Pagination pagination={pagination} perPage={perPage} onPerPageChange={(value) => { setPerPage(value); setPage(1); }} onPageChange={setPage} loading={loading} display="page-size" itemLabel="issue reports" placement="top" />
        <div className="issue-table-scroll">
          <table>
            <thead><tr><th>Issue</th><th>Category</th>{meta.can_review && <th>Submitted by</th>}<th>Submitted</th><th>Status</th><th><span className="sr-only">Actions</span></th></tr></thead>
            <tbody>
              {loading ? <TableSkeletonRows columns={meta.can_review ? 6 : 5} rows={5} /> : records.map((report) => (
                <tr key={report.id}>
                  <td><strong>{report.subject}</strong><small>{report.description}</small></td>
                  <td>{report.category}</td>
                  {meta.can_review && <td>{report.reporter_name}</td>}
                  <td>{formatDate(report.created_at)}</td>
                  <td><span className={`issue-status ${statusClass(report.status)}`}>{report.status}</span></td>
                  <td><button type="button" className="issue-view-button" onClick={() => openReport(report)}><Eye size={15} />{meta.can_review ? "Review" : "View"}</button></td>
                </tr>
              ))}
              {!loading && !records.length && <tr><td colSpan={meta.can_review ? 6 : 5} className="issue-empty"><MessageSquareWarning size={25} /><strong>No issue reports found</strong><span>Submitted reports will appear here.</span></td></tr>}
            </tbody>
          </table>
        </div>
        <Pagination pagination={pagination} perPage={perPage} onPageChange={setPage} loading={loading} display="navigation" itemLabel="issue reports" placement="bottom" />
      </section>

      {selected && (
        <ModalPortal>
          <div className="app-modal-backdrop issue-modal-backdrop" role="presentation" onMouseDown={(event) => { if (event.target === event.currentTarget) closeReport(); }}>
            <section className="app-modal issue-review-modal" role="dialog" aria-modal="true" aria-labelledby="issue-review-title">
              <header className="app-modal-header"><div><span><FileWarning size={20} /></span><div><small>{selected.category}</small><h2 id="issue-review-title">{selected.subject}</h2></div></div><button type="button" onClick={closeReport} aria-label="Close issue details"><X size={20} /></button></header>
              <div className="app-modal-body issue-review-body">
                <FormStatusBanner status={reviewStatus} />
                <dl className="issue-detail-grid"><div><dt>Submitted by</dt><dd>{selected.reporter_name}</dd></div><div><dt>Submitted</dt><dd>{formatDate(selected.created_at)}</dd></div><div><dt>Affected page</dt><dd>{selected.page_url || "Not provided"}</dd></div><div><dt>Status</dt><dd><span className={`issue-status ${statusClass(selected.status)}`}>{selected.status}</span></dd></div></dl>
                <section className="issue-description-card"><span>ISSUE DESCRIPTION</span><p>{selected.description}</p></section>
                {meta.can_review ? <form className="issue-review-form" onSubmit={submitReview}><label><span>Report status</span><select value={reviewForm.status} onChange={(event) => setReviewForm((current) => ({ ...current, status: event.target.value }))}>{options.statuses.map((status) => <option value={status} key={status}>{status}</option>)}</select></label><label><span>Administrator notes <small>Visible to the reporter</small></span><textarea rows={4} maxLength={2000} value={reviewForm.admin_notes} onChange={(event) => setReviewForm((current) => ({ ...current, admin_notes: event.target.value }))} placeholder="Add a safe update or resolution note without including credentials." /></label><button type="submit" disabled={reviewing}><CheckCircle2 size={16} />{reviewing ? "Saving…" : "Save review"}</button></form> : selected.admin_notes && <section className="issue-admin-note"><span>ADMINISTRATOR UPDATE</span><p>{selected.admin_notes}</p><small>{selected.reviewed_at ? formatDate(selected.reviewed_at) : ""}</small></section>}
              </div>
              <footer className="app-modal-footer"><button type="button" className="secondary" onClick={closeReport} disabled={reviewing}>Close</button></footer>
            </section>
          </div>
        </ModalPortal>
      )}
    </section>
  );
}

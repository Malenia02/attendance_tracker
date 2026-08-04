import { useEffect, useMemo, useState } from "react";
import {
  BadgeCheck,
  Building2,
  CheckCircle2,
  CircleAlert,
  ClipboardList,
  RefreshCw,
  Search,
  ShieldCheck,
  UserCheck,
  UserRoundCog,
  X,
} from "lucide-react";
import { Link } from "react-router-dom";
import Pagination from "../components/common/Pagination";
import { apiFetch, getStoredUser } from "../lib/auth";

const EMPTY_DATA = {
  data: [],
  summary: {},
  options: { states: [], departments: [] },
  meta: { pagination: null },
};

async function readResponse(response, fallback) {
  const payload = await response.json().catch(() => ({}));

  if (!response.ok) {
    const validationMessage = Object.values(payload.errors || {}).flat()[0];
    throw new Error(validationMessage || payload.message || fallback);
  }

  return payload;
}

function stateClass(state = "") {
  return state.toLowerCase().replaceAll(" ", "-");
}

export default function PersonnelOnboarding() {
  const currentUser = getStoredUser();
  const [records, setRecords] = useState(EMPTY_DATA);
  const [search, setSearch] = useState("");
  const [state, setState] = useState("");
  const [departmentId, setDepartmentId] = useState("");
  const [page, setPage] = useState(1);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");
  const [selected, setSelected] = useState([]);
  const [refreshKey, setRefreshKey] = useState(0);

  useEffect(() => {
    const controller = new AbortController();
    const timer = window.setTimeout(() => {
      const params = new URLSearchParams({ page: String(page), per_page: "25" });
      if (search.trim()) params.set("search", search.trim());
      if (state) params.set("state", state);
      if (departmentId) params.set("department_id", departmentId);

      setLoading(true);
      setError("");
      apiFetch(`/personnel-onboarding?${params}`, { signal: controller.signal })
        .then((response) => readResponse(response, "The onboarding queue could not be loaded."))
        .then((payload) => {
          setRecords(payload);
          setSelected([]);
        })
        .catch((requestError) => {
          if (requestError.name !== "AbortError") setError(requestError.message);
        })
        .finally(() => {
          if (!controller.signal.aborted) setLoading(false);
        });
    }, search ? 250 : 0);

    return () => {
      window.clearTimeout(timer);
      controller.abort();
    };
  }, [search, state, departmentId, page, refreshKey]);

  const actionableIds = useMemo(
    () => records.data
      .filter((record) => ["Ready", "Active"].includes(record.readiness.state))
      .map((record) => record.personnel_id),
    [records.data],
  );
  const selectedRecords = records.data.filter((record) => selected.includes(record.personnel_id));
  const canActivateSelection = selectedRecords.length > 0
    && selectedRecords.every((record) => record.readiness.state === "Ready");
  const canDeactivateSelection = selectedRecords.length > 0
    && selectedRecords.every((record) => record.readiness.state === "Active");

  function toggleAll() {
    setSelected(selected.length === actionableIds.length ? [] : actionableIds);
  }

  function toggleOne(personnelId) {
    setSelected((current) => current.includes(personnelId)
      ? current.filter((id) => id !== personnelId)
      : [...current, personnelId]);
  }

  async function changeStatus(personnelIds, targetStatus) {
    const activating = targetStatus === "Active";
    const promptText = activating
      ? `Activate ${personnelIds.length} ready personnel record${personnelIds.length > 1 ? "s" : ""}?`
      : `Deactivate ${personnelIds.length} personnel record${personnelIds.length > 1 ? "s" : ""}?`;

    if (!window.confirm(promptText)) return;

    setSaving(true);
    setError("");
    setNotice("");

    try {
      const bulk = personnelIds.length > 1;
      const response = await apiFetch(
        bulk ? "/personnel-onboarding/bulk" : `/personnel-onboarding/${personnelIds[0]}`,
        {
          method: "PATCH",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify(bulk
            ? { personnel_ids: personnelIds, target_status: targetStatus }
            : { target_status: targetStatus }),
        },
      );
      const payload = await readResponse(response, "The activation status could not be changed.");
      setNotice(payload.message);
      setSelected([]);
      setRefreshKey((value) => value + 1);
    } catch (requestError) {
      setError(requestError.message);
    } finally {
      setSaving(false);
    }
  }

  const summaryCards = [
    ["Setup required", records.summary.setup_required || 0, CircleAlert, "warning"],
    ["Ready to activate", records.summary.ready || 0, BadgeCheck, "ready"],
    ["Operational", records.summary.active || 0, UserCheck, "active"],
    ["Draft profiles", records.summary.draft || 0, ClipboardList, "draft"],
  ];

  return (
    <section className="onboarding-page">
      <header className="onboarding-hero">
        <div>
          <span><ShieldCheck size={15} /> Controlled workforce activation</span>
          <h1>Onboarding &amp; Readiness</h1>
          <p>Complete every operational requirement before personnel can record attendance, scan a QR card, or submit leave.</p>
        </div>
        <div className="onboarding-hero-total">
          <UserRoundCog size={22} />
          <div><strong>{Number(records.summary.total || 0).toLocaleString()}</strong><span>Personnel in workflow</span></div>
        </div>
      </header>

      {error && <div className="users-notice error"><X size={18} />{error}</div>}
      {notice && <div className="users-notice success"><CheckCircle2 size={18} />{notice}</div>}

      <div className="onboarding-summary">
        {summaryCards.map(([label, count, Icon, tone]) => (
          <button
            type="button"
            className={stateClass(label.startsWith("Setup") ? "Setup Required" : label.startsWith("Ready") ? "Ready" : label.startsWith("Operational") ? "Active" : "Draft") === stateClass(state) ? "selected" : ""}
            key={label}
            onClick={() => {
              const nextState = label.startsWith("Setup") ? "Setup Required" : label.startsWith("Ready") ? "Ready" : label.startsWith("Operational") ? "Active" : "Draft";
              setState(state === nextState ? "" : nextState);
              setPage(1);
            }}
          >
            <span className={tone}><Icon size={21} /></span>
            <div><strong>{Number(count).toLocaleString()}</strong><small>{label}</small></div>
          </button>
        ))}
      </div>

      <div className="panel onboarding-panel">
        <div className="onboarding-toolbar">
          <div>
            <span>Activation registry</span>
            <h2>Personnel readiness queue</h2>
            <p>Results are filtered and paginated on the server for large workforces.</p>
          </div>
          <div className="onboarding-filters">
            <label className="onboarding-search"><Search size={17} /><input value={search} onChange={(event) => { setSearch(event.target.value); setPage(1); }} placeholder="Search name or employee number" /></label>
            <select value={state} onChange={(event) => { setState(event.target.value); setPage(1); }}>
              <option value="">All readiness states</option>
              {records.options.states.map((option) => <option key={option}>{option}</option>)}
            </select>
            <select value={departmentId} onChange={(event) => { setDepartmentId(event.target.value); setPage(1); }}>
              <option value="">All offices</option>
              {records.options.departments.map((department) => (
                <option key={department.department_id} value={department.department_id}>{department.department_code} - {department.department_name}</option>
              ))}
            </select>
          </div>
        </div>

        {selected.length > 0 && (
          <div className="onboarding-bulkbar">
            <strong>{selected.length} selected</strong>
            <span>Select only records in the same state for a bulk action.</span>
            <div>
              <button type="button" disabled={saving || !canActivateSelection} onClick={() => changeStatus(selected, "Active")}><UserCheck size={15} /> Activate ready</button>
              <button type="button" className="secondary" disabled={saving || !canDeactivateSelection} onClick={() => changeStatus(selected, "Inactive")}>Deactivate</button>
            </div>
          </div>
        )}

        <div className="onboarding-table-wrap">
          <table className="onboarding-table">
            <thead><tr>
              <th><input type="checkbox" aria-label="Select actionable records" checked={actionableIds.length > 0 && selected.length === actionableIds.length} onChange={toggleAll} /></th>
              <th>Personnel</th><th>Readiness</th><th>Requirements</th><th>Assignment</th><th>Action</th>
            </tr></thead>
            <tbody>
              {loading ? (
                <tr><td colSpan="6" className="onboarding-empty"><RefreshCw className="spin" size={23} /> Checking readiness...</td></tr>
              ) : records.data.length ? records.data.map((record) => {
                const actionable = ["Ready", "Active"].includes(record.readiness.state);
                const missingLabels = record.readiness.requirements.filter((item) => !item.complete);
                return (
                  <tr key={record.personnel_id}>
                    <td><input type="checkbox" aria-label={`Select ${record.full_name}`} disabled={!actionable} checked={selected.includes(record.personnel_id)} onChange={() => toggleOne(record.personnel_id)} /></td>
                    <td><strong>{record.full_name}</strong><span>{record.employee_number} - {record.personnel_type}</span></td>
                    <td>
                      <span className={`onboarding-state ${stateClass(record.readiness.state)}`}>{record.readiness.state}</span>
                      <div className="onboarding-progress"><i style={{ width: `${record.readiness.progress}%` }} /><span>{record.readiness.progress}%</span></div>
                    </td>
                    <td>
                      {missingLabels.length ? <div className="onboarding-missing">{missingLabels.slice(0, 3).map((item) => <span key={item.code}>{item.label}</span>)}{missingLabels.length > 3 && <small>+{missingLabels.length - 3} more</small>}</div> : <span className="onboarding-complete"><CheckCircle2 size={15} /> All required steps complete</span>}
                    </td>
                    <td><strong>{record.department?.code || "No office"}</strong><span>{record.schedule?.name || "No effective schedule"}</span><span>{record.system_user ? `${record.system_user.role} account` : "No system account"}</span></td>
                    <td>
                      <div className="onboarding-actions">
                        {record.readiness.state === "Ready" && <button type="button" disabled={saving} onClick={() => changeStatus([record.personnel_id], "Active")}>Activate</button>}
                        {record.readiness.state === "Active" && <button type="button" className="danger" disabled={saving} onClick={() => changeStatus([record.personnel_id], "Inactive")}>Deactivate</button>}
                        <Link to={`/personnel?personnel=${record.personnel_id}`}>Profile</Link>
                        {!record.schedule && <Link to={`/schedules?personnel=${record.personnel_id}`}>Schedule</Link>}
                        {!record.system_user && currentUser?.user_role === "Administrator" && <Link to={`/system-users?personnel=${record.personnel_id}`}>Account</Link>}
                      </div>
                    </td>
                  </tr>
                );
              }) : (
                <tr><td colSpan="6" className="onboarding-empty"><BadgeCheck size={28} /><strong>No matching personnel</strong><span>Change the filters or create a personnel profile.</span></td></tr>
              )}
            </tbody>
          </table>
        </div>

        <Pagination pagination={records.meta?.pagination} onPageChange={setPage} disabled={loading || saving} itemLabel="personnel" />
      </div>

      <aside className="onboarding-security-note"><Building2 size={20} /><div><strong>Why activation is controlled</strong><span>Status cannot be changed from the regular personnel form. Every activation is revalidated inside one database transaction and recorded in an immutable audit history.</span></div></aside>
    </section>
  );
}

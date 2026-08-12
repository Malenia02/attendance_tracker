import { useEffect, useMemo, useState } from "react";
import {
  CheckCircle2,
  Circle,
  CircleUserRound,
  KeyRound,
  LockKeyhole,
  Pencil,
  Plus,
  Search,
  ShieldCheck,
  Trash2,
  UserCheck,
  UserX,
  X,
} from "lucide-react";
import useConfirmDialog from "../hooks/useConfirmDialog";
import { apiFetch } from "../lib/auth";
import Pagination from "../components/common/Pagination";
import ModalPortal from "../components/common/ModalPortal";
import FormStatusBanner from "../components/common/FormStatusBanner";
import FormDraftNotice from "../components/common/FormDraftNotice";
import useSessionFormDraft from "../hooks/useSessionFormDraft";
import { formDraftKey } from "../lib/formDrafts";

const emptyForm = {
  personnel_id: "",
  username: "",
  password: "",
  password_confirmation: "",
  user_role: "Personnel",
  status: "Active",
};

const MODAL_SUCCESS_DELAY_MS = 850;

function wait(ms) {
  return new Promise((resolve) => window.setTimeout(resolve, ms));
}

function formatDate(value) {
  if (!value) return "Never";

  return new Intl.DateTimeFormat("en-PH", {
    dateStyle: "medium",
    timeStyle: "short",
  }).format(new Date(value));
}

async function readResponse(response) {
  const payload = await response.json().catch(() => ({}));

  if (!response.ok) {
    const error = new Error(payload.message || "The request could not be completed.");
    error.fields = payload.errors || {};
    throw error;
  }

  return payload;
}

function evaluatePassword(password, confirmation) {
  return [
    {
      id: "length",
      label: "12 to 72 characters",
      met: password.length >= 12 && password.length <= 72,
    },
    {
      id: "case",
      label: "At least one uppercase and one lowercase letter",
      met: /\p{Lu}/u.test(password) && /\p{Ll}/u.test(password),
    },
    {
      id: "number",
      label: "At least one number",
      met: /\p{N}/u.test(password),
    },
    {
      id: "symbol",
      label: "At least one symbol, such as ! @ # $ %",
      met: /[\p{P}\p{S}]/u.test(password),
    },
    {
      id: "match",
      label: "Password confirmation matches",
      met: password.length > 0 && password === confirmation,
    },
  ];
}

export default function SystemUsers() {
  const { confirm, confirmationDialog } = useConfirmDialog();
  const [users, setUsers] = useState([]);
  const [page, setPage] = useState(1);
  const [pagination, setPagination] = useState(null);
  const [summary, setSummary] = useState({ total: 0, active: 0, inactive: 0, locked: 0 });
  const [options, setOptions] = useState({ roles: [], statuses: [], personnel: [] });
  const [personnelLookupSearch, setPersonnelLookupSearch] = useState("");
  const [search, setSearch] = useState("");
  const [roleFilter, setRoleFilter] = useState("");
  const [statusFilter, setStatusFilter] = useState("");
  const [loading, setLoading] = useState(true);
  const [pageError, setPageError] = useState("");
  const [refreshKey, setRefreshKey] = useState(0);
  const [modalOpen, setModalOpen] = useState(false);
  const [modalStatus, setModalStatus] = useState(null);
  const [editingUser, setEditingUser] = useState(null);
  const [form, setForm] = useState(emptyForm);
  const [draftKey, setDraftKey] = useState("");
  const [draftInitialForm, setDraftInitialForm] = useState(emptyForm);
  const [fieldErrors, setFieldErrors] = useState({});
  const [saving, setSaving] = useState(false);
  const [deletingId, setDeletingId] = useState(null);
  const [notice, setNotice] = useState("");
  const { draftRestored, discardDraft, clearDraft } = useSessionFormDraft({
    isOpen: modalOpen,
    draftKey,
    initialForm: draftInitialForm,
    form,
    setForm,
    excludedFields: ["password", "password_confirmation"],
  });
  const passwordChecks = useMemo(
    () => evaluatePassword(form.password, form.password_confirmation),
    [form.password, form.password_confirmation],
  );
  const passwordChangeStarted = Boolean(form.password || form.password_confirmation);
  const passwordReady = editingUser && !passwordChangeStarted
    ? true
    : passwordChecks.every((requirement) => requirement.met);
  const passwordProgress = Math.round(
    (passwordChecks.filter((requirement) => requirement.met).length / passwordChecks.length) * 100,
  );

  useEffect(() => {
    const controller = new AbortController();
    const timer = window.setTimeout(() => {
      const params = new URLSearchParams({ limit: "50" });
      if (personnelLookupSearch.trim()) params.set("personnel_search", personnelLookupSearch.trim());
      if (form.personnel_id) params.set("personnel_id", form.personnel_id);

      apiFetch(`/system-users/options?${params}`, { signal: controller.signal })
        .then(readResponse)
        .then(setOptions)
        .catch((error) => {
          if (error.name !== "AbortError") setPageError(error.message);
        });
    }, 200);

    return () => {
      window.clearTimeout(timer);
      controller.abort();
    };
  }, [personnelLookupSearch, form.personnel_id, refreshKey]);

  useEffect(() => {
    const controller = new AbortController();
    const timer = window.setTimeout(async () => {
      setLoading(true);
      setPageError("");

      const params = new URLSearchParams();
      params.set("page", String(page));
      params.set("per_page", "25");
      if (search.trim()) params.set("search", search.trim());
      if (roleFilter) params.set("role", roleFilter);
      if (statusFilter) params.set("status", statusFilter);

      try {
        const query = params.toString();
        const payload = await apiFetch(
          `/system-users${query ? `?${query}` : ""}`,
          { signal: controller.signal },
        ).then(readResponse);

        setUsers(payload.data);
        setSummary(payload.summary);
        setPagination(payload.meta?.pagination || null);
        if (!payload.data.length && page > 1) setPage((current) => Math.max(1, current - 1));
      } catch (error) {
        if (error.name !== "AbortError") setPageError(error.message);
      } finally {
        if (!controller.signal.aborted) setLoading(false);
      }
    }, 250);

    return () => {
      window.clearTimeout(timer);
      controller.abort();
    };
  }, [search, roleFilter, statusFilter, page, refreshKey]);

  const availablePersonnel = useMemo(
    () =>
      options.personnel.filter(
        (person) =>
          !person.assigned_user_id || person.assigned_user_id === editingUser?.user_id,
      ),
    [options.personnel, editingUser],
  );

  function openCreateModal() {
    setEditingUser(null);
    setPersonnelLookupSearch("");
    setDraftInitialForm(emptyForm);
    setDraftKey(formDraftKey("system-user", "new"));
    setForm(emptyForm);
    setFieldErrors({});
    setModalStatus(null);
    setModalOpen(true);
  }

  function openEditModal(user) {
    setEditingUser(user);
    setPersonnelLookupSearch("");
    const initialForm = {
      personnel_id: user.personnel_id ? String(user.personnel_id) : "",
      username: user.username,
      password: "",
      password_confirmation: "",
      user_role: user.user_role,
      status: user.status,
    };
    setDraftInitialForm(initialForm);
    setDraftKey(formDraftKey("system-user", user.user_id));
    setForm(initialForm);
    setFieldErrors({});
    setModalStatus(null);
    setModalOpen(true);
  }

  function closeModal() {
    if (saving) return;
    setModalOpen(false);
    setEditingUser(null);
    setFieldErrors({});
    setModalStatus(null);
  }

  function updateForm(event) {
    const { name, value } = event.target;
    setForm((current) => ({ ...current, [name]: value }));
    setFieldErrors((current) => ({ ...current, [name]: undefined }));
    setModalStatus(null);
  }

  async function submitForm(event) {
    event.preventDefault();
    setSaving(true);
    setFieldErrors({});
    setModalStatus(null);

    const body = {
      ...form,
      personnel_id: form.personnel_id ? Number(form.personnel_id) : null,
    };

    if (editingUser && !body.password) {
      delete body.password;
      delete body.password_confirmation;
    }

    try {
      const response = await apiFetch(
        editingUser
          ? `/system-users/${editingUser.user_id}`
          : "/system-users",
        {
          method: editingUser ? "PUT" : "POST",
          headers: {
            Accept: "application/json",
            "Content-Type": "application/json",
          },
          body: JSON.stringify(body),
        },
      );
      const payload = await readResponse(response);

      clearDraft();
      setNotice(payload.message);
      setModalStatus({ type: "success", message: payload.message || "Saved successfully." });
      await wait(MODAL_SUCCESS_DELAY_MS);
      setModalOpen(false);
      setEditingUser(null);
      setFieldErrors({});
      setModalStatus(null);
      setRefreshKey((key) => key + 1);
      window.setTimeout(() => setNotice(""), 3500);
    } catch (error) {
      setModalStatus({ type: "error", message: error.message });
      setFieldErrors(error.fields || {});
      if (!Object.keys(error.fields || {}).length) {
        setFieldErrors({ general: [error.message] });
      }
    } finally {
      setSaving(false);
    }
  }

  async function deleteUser(user) {
    const confirmed = await confirm({
      title: "Delete system account?",
      message: `Delete the system account “${user.username}”?`,
      note: "This removes the account’s access and cannot be undone.",
      confirmLabel: "Delete account",
    });
    if (!confirmed) return;

    setDeletingId(user.user_id);
    setPageError("");

    try {
      const payload = await apiFetch(`/system-users/${user.user_id}`, {
        method: "DELETE",
        headers: { Accept: "application/json" },
      }).then(readResponse);

      setNotice(payload.message);
      setRefreshKey((key) => key + 1);
      window.setTimeout(() => setNotice(""), 3500);
    } catch (error) {
      setPageError(error.message);
    } finally {
      setDeletingId(null);
    }
  }

  const summaryItems = [
    { label: "Total Users", value: summary.total, icon: CircleUserRound, tone: "blue" },
    { label: "Active", value: summary.active, icon: UserCheck, tone: "green" },
    { label: "Inactive", value: summary.inactive, icon: UserX, tone: "gray" },
    { label: "Locked", value: summary.locked, icon: LockKeyhole, tone: "orange" },
  ];

  return (
    <section className="system-users-page">
      <div className="records-hero system-users-hero">
        <div>
          <span className="records-hero-eyebrow"><ShieldCheck size={14} /> Access governance</span>
          <h1>System Users</h1>
          <p>Control account access, personnel links, security roles, and account availability.</p>
        </div>
        <div className="records-hero-side">
          <span className="records-hero-mark" aria-hidden="true"><LockKeyhole size={28} /></span>
          <button type="button" className="records-hero-action" onClick={openCreateModal}>
            <Plus size={18} />
            Add system user
          </button>
        </div>
      </div>

      {notice && <div className="users-notice success"><UserCheck size={18} />{notice}</div>}
      {pageError && <div className="users-notice error"><X size={18} />{pageError}</div>}

      <div className="user-summary-grid">
        {summaryItems.map(({ label, value, icon: Icon, tone }) => (
          <article className="user-summary-card" key={label}>
            <span className={`user-summary-icon ${tone}`}><Icon size={22} /></span>
            <div><strong>{value}</strong><span>{label}</span></div>
          </article>
        ))}
      </div>

      <div className="panel users-panel records-panel">
        <div className="records-panel-heading">
          <div>
            <span>Identity administration</span>
            <h2>Authorized accounts</h2>
          </div>
          <small>{loading ? "Loading accounts..." : `${users.length} result${users.length === 1 ? "" : "s"}`}</small>
        </div>
        <div className="users-toolbar">
          <div className="users-search">
            <Search size={18} />
            <input
              type="search"
              value={search}
              onChange={(event) => { setSearch(event.target.value); setPage(1); }}
              placeholder="Search username, personnel, email..."
              aria-label="Search system users"
            />
          </div>
          <select value={roleFilter} onChange={(event) => { setRoleFilter(event.target.value); setPage(1); }}>
            <option value="">All roles</option>
            {options.roles.map((role) => <option key={role}>{role}</option>)}
          </select>
          <select value={statusFilter} onChange={(event) => { setStatusFilter(event.target.value); setPage(1); }}>
            <option value="">All statuses</option>
            {options.statuses.map((status) => <option key={status}>{status}</option>)}
          </select>
        </div>

        <div className="users-table-wrap">
          <table className="users-table">
            <thead>
              <tr>
                <th>User</th>
                <th>Personnel</th>
                <th>Role</th>
                <th>Status</th>
                <th>Last login</th>
                <th><span className="sr-only">Actions</span></th>
              </tr>
            </thead>
            <tbody>
              {loading ? (
                <tr><td colSpan="6" className="users-empty">Loading system users…</td></tr>
              ) : users.length === 0 ? (
                <tr><td colSpan="6" className="users-empty">No system users match your filters.</td></tr>
              ) : users.map((user) => (
                <tr key={user.user_id}>
                  <td>
                    <div className="user-identity">
                      <span>{user.username.slice(0, 2).toUpperCase()}</span>
                      <div><strong>{user.username}</strong><small>Account #{user.user_id}</small></div>
                    </div>
                  </td>
                  <td>
                    {user.personnel ? (
                      <div className="personnel-cell">
                        <strong>{user.personnel.full_name}</strong>
                        <small>{user.personnel.employee_number}</small>
                      </div>
                    ) : <span className="muted-cell">Not linked</span>}
                  </td>
                  <td><span className="role-chip"><ShieldCheck size={14} />{user.user_role}</span></td>
                  <td><span className={`status-chip ${user.status.toLowerCase()}`}>{user.status}</span></td>
                  <td className="date-cell">{formatDate(user.last_login_at)}</td>
                  <td>
                    <div className="table-actions">
                      <button type="button" onClick={() => openEditModal(user)} aria-label={`Edit ${user.username}`}>
                        <Pencil size={16} />
                      </button>
                      <button
                        type="button"
                        className="danger"
                        onClick={() => deleteUser(user)}
                        disabled={deletingId === user.user_id}
                        aria-label={`Delete ${user.username}`}
                      >
                        <Trash2 size={16} />
                      </button>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        <Pagination
          pagination={pagination}
          onPageChange={setPage}
          disabled={loading}
          itemLabel="system users"
        />
      </div>

      {modalOpen && (
        <ModalPortal>
        <div className="modal-backdrop" role="presentation" onMouseDown={(event) => {
          if (event.target === event.currentTarget) closeModal();
        }}>
          <div className="user-modal admin-form-modal" role="dialog" aria-modal="true" aria-labelledby="user-modal-title">
            <div className="user-modal-header">
              <div>
                <span className="modal-icon"><KeyRound size={20} /></span>
                <div>
                  <h2 id="user-modal-title">{editingUser ? "Edit system user" : "Add system user"}</h2>
                  <p>{editingUser ? "Update account access and assignment." : "Create credentials for an authorized user."}</p>
                </div>
              </div>
              <button type="button" onClick={closeModal} aria-label="Close"><X size={20} /></button>
            </div>

            <form onSubmit={submitForm} className="user-form admin-form-layout">
              <FormStatusBanner status={modalStatus} />
              <FormDraftNotice restored={draftRestored} onDiscard={discardDraft} />
              {fieldErrors.general && <div className="form-error-banner">{fieldErrors.general[0]}</div>}

              <div className="admin-form-section">
                <div className="admin-form-section-title">
                  <CircleUserRound size={17} />
                  <div><strong>Personnel connection</strong><small>Link this account to one personnel record</small></div>
                </div>
                <div className="admin-form-grid">
                  <div className="form-field form-field-full">
                    <label htmlFor="personnel_id">Linked personnel <span>Optional</span></label>
                    <input
                      type="search"
                      value={personnelLookupSearch}
                      onChange={(event) => setPersonnelLookupSearch(event.target.value)}
                      placeholder="Search personnel by name or employee number..."
                      aria-label="Search personnel to link"
                    />
                    <select id="personnel_id" name="personnel_id" value={form.personnel_id} onChange={updateForm}>
                      <option value="">No personnel assignment</option>
                      {availablePersonnel.map((person) => (
                        <option key={person.personnel_id} value={person.personnel_id}>
                          {person.full_name} — {person.employee_number}
                        </option>
                      ))}
                    </select>
                    {fieldErrors.personnel_id && <small className="field-error">{fieldErrors.personnel_id[0]}</small>}
                  </div>
                </div>
              </div>

              <div className="admin-form-section">
                <div className="admin-form-section-title">
                  <KeyRound size={17} />
                  <div><strong>Account access</strong><small>Username, role, and account availability</small></div>
                </div>
                <div className="admin-form-grid">
                  <div className="form-field form-field-full">
                    <label htmlFor="username">Username</label>
                    <input id="username" name="username" value={form.username} onChange={updateForm} placeholder="e.g. juan.delacruz" required />
                    {fieldErrors.username && <small className="field-error">{fieldErrors.username[0]}</small>}
                  </div>

                  <div className="form-field">
                    <label htmlFor="user_role">Role</label>
                    <select id="user_role" name="user_role" value={form.user_role} onChange={updateForm} required>
                      {options.roles.map((role) => <option key={role}>{role}</option>)}
                    </select>
                    {fieldErrors.user_role && <small className="field-error">{fieldErrors.user_role[0]}</small>}
                  </div>

                  <div className="form-field">
                    <label htmlFor="status">Status</label>
                    <select id="status" name="status" value={form.status} onChange={updateForm} required>
                      {options.statuses.map((status) => <option key={status}>{status}</option>)}
                    </select>
                    {fieldErrors.status && <small className="field-error">{fieldErrors.status[0]}</small>}
                  </div>
                </div>
              </div>

              <div className="admin-form-section">
                <div className="admin-form-section-title">
                  <LockKeyhole size={17} />
                  <div><strong>Password security</strong><small>Strong credentials protected by server validation</small></div>
                </div>
                <div className="admin-form-grid">
                  <div className="form-field">
                    <label htmlFor="user_password">Password {editingUser && <span>Leave blank to keep</span>}</label>
                    <input
                      id="user_password"
                      name="password"
                      type="password"
                      value={form.password}
                      onChange={updateForm}
                      minLength="12"
                      maxLength="72"
                      required={!editingUser}
                      autoComplete="new-password"
                      aria-describedby="password-requirements"
                    />
                    {fieldErrors.password && <small className="field-error">{fieldErrors.password[0]}</small>}
                  </div>

                  <div className="form-field">
                    <label htmlFor="password_confirmation">Confirm password</label>
                    <input
                      id="password_confirmation"
                      name="password_confirmation"
                      type="password"
                      value={form.password_confirmation}
                      onChange={updateForm}
                      minLength="12"
                      maxLength="72"
                      required={!editingUser || Boolean(form.password)}
                      autoComplete="new-password"
                      aria-describedby="password-requirements"
                    />
                  </div>

                  {(!editingUser || passwordChangeStarted) && (
                    <div
                      id="password-requirements"
                      className="password-requirements form-field-full"
                      aria-live="polite"
                    >
                      <div className="password-requirements-header">
                        <div>
                          <strong>Password requirements</strong>
                          <small>The server also rejects passwords exposed in known data breaches.</small>
                        </div>
                        <span>{passwordProgress}%</span>
                      </div>
                      <div className="password-strength-track" aria-hidden="true">
                        <i style={{ width: `${passwordProgress}%` }} />
                      </div>
                      <ul>
                        {passwordChecks.map((requirement) => (
                          <li className={requirement.met ? "met" : ""} key={requirement.id}>
                            {requirement.met
                              ? <CheckCircle2 size={15} />
                              : <Circle size={15} />}
                            <span>{requirement.label}</span>
                          </li>
                        ))}
                      </ul>
                    </div>
                  )}
                </div>
              </div>

              <div className="user-modal-actions">
                <button type="button" className="secondary-action" onClick={closeModal}>Cancel</button>
                <button type="submit" className="primary-action" disabled={saving || !passwordReady}>
                  {saving ? "Saving…" : editingUser ? "Save changes" : "Create user"}
                </button>
              </div>
            </form>
          </div>
        </div>
        </ModalPortal>
      )}
      {confirmationDialog}
    </section>
  );
}

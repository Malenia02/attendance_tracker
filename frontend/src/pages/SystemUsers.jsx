import { useEffect, useMemo, useState } from "react";
import {
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

const API_BASE = (import.meta.env.VITE_API_URL || "/api").replace(/\/$/, "");

const emptyForm = {
  personnel_id: "",
  username: "",
  password: "",
  password_confirmation: "",
  user_role: "Personnel",
  status: "Active",
};

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

export default function SystemUsers() {
  const [users, setUsers] = useState([]);
  const [summary, setSummary] = useState({ total: 0, active: 0, inactive: 0, locked: 0 });
  const [options, setOptions] = useState({ roles: [], statuses: [], personnel: [] });
  const [search, setSearch] = useState("");
  const [roleFilter, setRoleFilter] = useState("");
  const [statusFilter, setStatusFilter] = useState("");
  const [loading, setLoading] = useState(true);
  const [pageError, setPageError] = useState("");
  const [refreshKey, setRefreshKey] = useState(0);
  const [modalOpen, setModalOpen] = useState(false);
  const [editingUser, setEditingUser] = useState(null);
  const [form, setForm] = useState(emptyForm);
  const [fieldErrors, setFieldErrors] = useState({});
  const [saving, setSaving] = useState(false);
  const [deletingId, setDeletingId] = useState(null);
  const [notice, setNotice] = useState("");

  useEffect(() => {
    const controller = new AbortController();

    fetch(`${API_BASE}/system-users/options`, { signal: controller.signal })
      .then(readResponse)
      .then(setOptions)
      .catch((error) => {
        if (error.name !== "AbortError") setPageError(error.message);
      });

    return () => controller.abort();
  }, [refreshKey]);

  useEffect(() => {
    const controller = new AbortController();
    const timer = window.setTimeout(async () => {
      setLoading(true);
      setPageError("");

      const params = new URLSearchParams();
      if (search.trim()) params.set("search", search.trim());
      if (roleFilter) params.set("role", roleFilter);
      if (statusFilter) params.set("status", statusFilter);

      try {
        const query = params.toString();
        const payload = await fetch(
          `${API_BASE}/system-users${query ? `?${query}` : ""}`,
          { signal: controller.signal },
        ).then(readResponse);

        setUsers(payload.data);
        setSummary(payload.summary);
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
  }, [search, roleFilter, statusFilter, refreshKey]);

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
    setForm(emptyForm);
    setFieldErrors({});
    setModalOpen(true);
  }

  function openEditModal(user) {
    setEditingUser(user);
    setForm({
      personnel_id: user.personnel_id ? String(user.personnel_id) : "",
      username: user.username,
      password: "",
      password_confirmation: "",
      user_role: user.user_role,
      status: user.status,
    });
    setFieldErrors({});
    setModalOpen(true);
  }

  function closeModal() {
    if (saving) return;
    setModalOpen(false);
    setEditingUser(null);
    setFieldErrors({});
  }

  function updateForm(event) {
    const { name, value } = event.target;
    setForm((current) => ({ ...current, [name]: value }));
    setFieldErrors((current) => ({ ...current, [name]: undefined }));
  }

  async function submitForm(event) {
    event.preventDefault();
    setSaving(true);
    setFieldErrors({});

    const body = {
      ...form,
      personnel_id: form.personnel_id ? Number(form.personnel_id) : null,
    };

    if (editingUser && !body.password) {
      delete body.password;
      delete body.password_confirmation;
    }

    try {
      const response = await fetch(
        editingUser
          ? `${API_BASE}/system-users/${editingUser.user_id}`
          : `${API_BASE}/system-users`,
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

      setNotice(payload.message);
      setModalOpen(false);
      setEditingUser(null);
      setFieldErrors({});
      setRefreshKey((key) => key + 1);
      window.setTimeout(() => setNotice(""), 3500);
    } catch (error) {
      setFieldErrors(error.fields || {});
      if (!Object.keys(error.fields || {}).length) {
        setFieldErrors({ general: [error.message] });
      }
    } finally {
      setSaving(false);
    }
  }

  async function deleteUser(user) {
    const confirmed = window.confirm(
      `Delete the system account “${user.username}”? This action cannot be undone.`,
    );
    if (!confirmed) return;

    setDeletingId(user.user_id);
    setPageError("");

    try {
      const payload = await fetch(`${API_BASE}/system-users/${user.user_id}`, {
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
      <div className="page-title users-page-title">
        <div>
          <h1>System Users</h1>
          <nav className="breadcrumb" aria-label="Breadcrumb">
            <span>Home</span><span>/</span><strong>System Users</strong>
          </nav>
        </div>
        <button type="button" className="primary-action" onClick={openCreateModal}>
          <Plus size={18} />
          Add system user
        </button>
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

      <div className="panel users-panel">
        <div className="users-toolbar">
          <div className="users-search">
            <Search size={18} />
            <input
              type="search"
              value={search}
              onChange={(event) => setSearch(event.target.value)}
              placeholder="Search username, personnel, email..."
              aria-label="Search system users"
            />
          </div>
          <select value={roleFilter} onChange={(event) => setRoleFilter(event.target.value)}>
            <option value="">All roles</option>
            {options.roles.map((role) => <option key={role}>{role}</option>)}
          </select>
          <select value={statusFilter} onChange={(event) => setStatusFilter(event.target.value)}>
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
        <div className="users-table-footer">
          Showing {users.length} {users.length === 1 ? "user" : "users"}
        </div>
      </div>

      {modalOpen && (
        <div className="modal-backdrop" role="presentation" onMouseDown={(event) => {
          if (event.target === event.currentTarget) closeModal();
        }}>
          <div className="user-modal" role="dialog" aria-modal="true" aria-labelledby="user-modal-title">
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

            <form onSubmit={submitForm} className="user-form">
              {fieldErrors.general && <div className="form-error-banner">{fieldErrors.general[0]}</div>}

              <div className="form-field form-field-full">
                <label htmlFor="personnel_id">Linked personnel <span>Optional</span></label>
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

              <div className="form-field">
                <label htmlFor="user_password">Password {editingUser && <span>Leave blank to keep</span>}</label>
                <input id="user_password" name="password" type="password" value={form.password} onChange={updateForm} minLength="8" required={!editingUser} autoComplete="new-password" />
                {fieldErrors.password && <small className="field-error">{fieldErrors.password[0]}</small>}
              </div>

              <div className="form-field">
                <label htmlFor="password_confirmation">Confirm password</label>
                <input id="password_confirmation" name="password_confirmation" type="password" value={form.password_confirmation} onChange={updateForm} minLength="8" required={!editingUser || Boolean(form.password)} autoComplete="new-password" />
              </div>

              <div className="user-modal-actions">
                <button type="button" className="secondary-action" onClick={closeModal}>Cancel</button>
                <button type="submit" className="primary-action" disabled={saving}>
                  {saving ? "Saving…" : editingUser ? "Save changes" : "Create user"}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </section>
  );
}

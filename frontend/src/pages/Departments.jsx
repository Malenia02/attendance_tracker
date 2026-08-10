import { useEffect, useMemo, useState } from "react";
import {
  Building2,
  Crosshair,
  Edit3,
  ExternalLink,
  MapPin,
  Navigation,
  Plus,
  Radio,
  Search,
  ShieldCheck,
  Trash2,
  Users,
  Wifi,
  X,
} from "lucide-react";
import useConfirmDialog from "../hooks/useConfirmDialog";
import { apiFetch } from "../lib/auth";
import ModalPortal from "../components/common/ModalPortal";

const emptyForm = {
  department_code: "",
  department_name: "",
  office_location: "",
  latitude: "",
  longitude: "",
  allowed_radius_meters: "100",
  status: "Active",
};

async function readResponse(response) {
  const payload = await response.json().catch(() => ({}));

  if (!response.ok) {
    const error = new Error(payload.message || "The request could not be completed.");
    error.fields = payload.errors || {};
    throw error;
  }

  return payload;
}

function FieldError({ errors, name }) {
  return errors[name]?.length ? <small className="field-error">{errors[name][0]}</small> : null;
}

export default function Departments() {
  const { confirm, confirmationDialog } = useConfirmDialog();
  const [departments, setDepartments] = useState([]);
  const [summary, setSummary] = useState({ total: 0, active: 0, gps_configured: 0, personnel: 0 });
  const [search, setSearch] = useState("");
  const [statusFilter, setStatusFilter] = useState("");
  const [loading, setLoading] = useState(true);
  const [pageError, setPageError] = useState("");
  const [notice, setNotice] = useState("");
  const [refreshKey, setRefreshKey] = useState(0);
  const [modalOpen, setModalOpen] = useState(false);
  const [editing, setEditing] = useState(null);
  const [form, setForm] = useState(emptyForm);
  const [fieldErrors, setFieldErrors] = useState({});
  const [saving, setSaving] = useState(false);
  const [locating, setLocating] = useState(false);
  const [locationAccuracy, setLocationAccuracy] = useState(null);
  const [deletingId, setDeletingId] = useState(null);
  const [canManageNetworks, setCanManageNetworks] = useState(false);
  const [networkName, setNetworkName] = useState("Main office internet");
  const [networkBusy, setNetworkBusy] = useState(false);

  useEffect(() => {
    const controller = new AbortController();
    const timer = window.setTimeout(async () => {
      const params = new URLSearchParams();
      if (search.trim()) params.set("search", search.trim());
      if (statusFilter) params.set("status", statusFilter);

      try {
        const payload = await apiFetch(`/departments?${params}`, { signal: controller.signal })
          .then(readResponse);
        setDepartments(payload.data);
        setSummary(payload.summary);
        setCanManageNetworks(Boolean(payload.can_manage_office_networks));
        setPageError("");
      } catch (error) {
        if (error.name !== "AbortError") setPageError(error.message);
      } finally {
        if (!controller.signal.aborted) setLoading(false);
      }
    }, 250);

    return () => {
      controller.abort();
      window.clearTimeout(timer);
    };
  }, [search, statusFilter, refreshKey]);

  const cards = useMemo(() => [
    { label: "Departments", value: summary.total, icon: Building2, tone: "blue" },
    { label: "Active", value: summary.active, icon: ShieldCheck, tone: "green" },
    { label: "GPS configured", value: summary.gps_configured, icon: Navigation, tone: "purple" },
    { label: "Personnel assigned", value: summary.personnel, icon: Users, tone: "orange" },
  ], [summary]);

  function openCreate() {
    setEditing(null);
    setForm(emptyForm);
    setFieldErrors({});
    setLocationAccuracy(null);
    setNetworkName("Main office internet");
    setModalOpen(true);
  }

  function openEdit(department) {
    setEditing(department);
    setForm({
      department_code: department.department_code,
      department_name: department.department_name,
      office_location: department.office_location,
      latitude: String(department.latitude ?? ""),
      longitude: String(department.longitude ?? ""),
      allowed_radius_meters: String(department.allowed_radius_meters ?? 100),
      status: department.status,
    });
    setFieldErrors({});
    setLocationAccuracy(null);
    setNetworkName("Main office internet");
    setModalOpen(true);
  }

  function closeModal() {
    if (saving || locating || networkBusy) return;
    setModalOpen(false);
    setEditing(null);
  }

  function updateForm(event) {
    const { name, value } = event.target;
    setForm((current) => ({ ...current, [name]: value }));
    setFieldErrors((current) => ({ ...current, [name]: undefined }));
  }

  function captureCoordinates() {
    setFieldErrors((current) => ({ ...current, location: undefined }));

    if (!navigator.geolocation) {
      setFieldErrors((current) => ({ ...current, location: ["This device does not support location services."] }));
      return;
    }

    setLocating(true);
    navigator.geolocation.getCurrentPosition(
      (position) => {
        setForm((current) => ({
          ...current,
          latitude: position.coords.latitude.toFixed(7),
          longitude: position.coords.longitude.toFixed(7),
        }));
        setLocationAccuracy(Math.round(position.coords.accuracy));
        setLocating(false);
      },
      (error) => {
        const message = error.code === error.PERMISSION_DENIED
          ? "Location permission was denied. Allow precise location and try again."
          : error.code === error.TIMEOUT
            ? "GPS lookup timed out. Move near a window and try again."
            : "The office coordinates could not be determined.";
        setFieldErrors((current) => ({ ...current, location: [message] }));
        setLocating(false);
      },
      { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 },
    );
  }

  async function submitForm(event) {
    event.preventDefault();
    setSaving(true);
    setFieldErrors({});

    try {
      const response = await apiFetch(
        editing ? `/departments/${editing.department_id}` : "/departments",
        {
          method: editing ? "PUT" : "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({
            ...form,
            department_code: form.department_code.trim().toUpperCase(),
            department_name: form.department_name.trim(),
            office_location: form.office_location.trim(),
            latitude: form.latitude === "" ? null : Number(form.latitude),
            longitude: form.longitude === "" ? null : Number(form.longitude),
            allowed_radius_meters: Number(form.allowed_radius_meters),
          }),
        },
      );
      const payload = await readResponse(response);
      setNotice(payload.message);
      setModalOpen(false);
      setEditing(null);
      setRefreshKey((key) => key + 1);
      window.setTimeout(() => setNotice(""), 3500);
    } catch (error) {
      setFieldErrors(
        Object.keys(error.fields || {}).length ? error.fields : { general: [error.message] },
      );
    } finally {
      setSaving(false);
    }
  }

  async function deleteDepartment(department) {
    const confirmed = await confirm({
      title: "Remove department?",
      message: `Remove ${department.department_name} from the office directory?`,
      note: "The server will prevent removal if personnel or attendance records still depend on it.",
      confirmLabel: "Remove department",
    });
    if (!confirmed) return;

    setDeletingId(department.department_id);
    setPageError("");

    try {
      const payload = await apiFetch(`/departments/${department.department_id}`, {
        method: "DELETE",
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

  async function registerOfficeNetwork() {
    if (!editing || !networkName.trim()) return;
    setNetworkBusy(true);
    setFieldErrors((current) => ({ ...current, office_network: undefined }));

    try {
      const payload = await apiFetch(`/departments/${editing.department_id}/office-networks`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ network_name: networkName.trim() }),
      }).then(readResponse);
      const networks = [
        payload.data,
        ...(editing.office_networks || []).filter(
          (item) => item.office_network_id !== payload.data.office_network_id,
        ),
      ];
      setEditing((current) => ({ ...current, office_networks: networks }));
      setDepartments((current) => current.map((item) => (
        item.department_id === editing.department_id
          ? { ...item, office_networks: networks }
          : item
      )));
      setNotice(payload.message);
    } catch (error) {
      setFieldErrors((current) => ({ ...current, office_network: [error.message] }));
    } finally {
      setNetworkBusy(false);
    }
  }

  async function revokeOfficeNetwork(network) {
    if (!editing) return;
    setNetworkBusy(true);

    try {
      const payload = await apiFetch(
        `/departments/${editing.department_id}/office-networks/${network.office_network_id}`,
        { method: "DELETE" },
      ).then(readResponse);
      const networks = (editing.office_networks || []).filter(
        (item) => item.office_network_id !== network.office_network_id,
      );
      setEditing((current) => ({ ...current, office_networks: networks }));
      setDepartments((current) => current.map((item) => (
        item.department_id === editing.department_id
          ? { ...item, office_networks: networks }
          : item
      )));
      setNotice(payload.message);
    } catch (error) {
      setFieldErrors((current) => ({ ...current, office_network: [error.message] }));
    } finally {
      setNetworkBusy(false);
    }
  }

  return (
    <section className="departments-page">
      <div className="departments-hero">
        <div>
          <span><Radio size={14} /> Office geofencing</span>
          <h1>Departments & Office Locations</h1>
          <p>Manage personnel departments and the GPS radius used to verify QR attendance.</p>
        </div>
        <button type="button" onClick={openCreate}><Plus size={18} />Add department</button>
      </div>

      {notice && <div className="users-notice success"><ShieldCheck size={18} />{notice}</div>}
      {pageError && <div className="users-notice error"><X size={18} />{pageError}</div>}

      <div className="department-summary-grid">
        {cards.map(({ label, value, icon: Icon, tone }) => (
          <article key={label}>
            <span className={tone}><Icon size={20} /></span>
            <div><strong>{loading ? "—" : value}</strong><small>{label}</small></div>
          </article>
        ))}
      </div>

      <div className="panel departments-panel">
        <div className="departments-toolbar">
          <label><Search size={16} /><input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Search department or office..." /></label>
          <select value={statusFilter} onChange={(event) => setStatusFilter(event.target.value)}>
            <option value="">All statuses</option>
            <option>Active</option>
            <option>Inactive</option>
          </select>
        </div>

        <div className="users-table-wrap">
          <table className="users-table department-table">
            <thead>
              <tr><th>Department</th><th>Office location</th><th>GPS coordinates</th><th>Radius</th><th>Personnel</th><th>Status</th><th><span className="sr-only">Actions</span></th></tr>
            </thead>
            <tbody>
              {loading ? (
                <tr><td colSpan="7" className="users-empty">Loading departments…</td></tr>
              ) : departments.length ? departments.map((department) => (
                <tr key={department.department_id}>
                  <td><strong>{department.department_code}</strong><small>{department.department_name}</small></td>
                  <td><span className="department-location"><MapPin size={15} />{department.office_location}</span></td>
                  <td>
                    <div className="department-coordinates">
                      <strong>{department.latitude.toFixed(7)}, {department.longitude.toFixed(7)}</strong>
                      <a href={`https://www.google.com/maps?q=${department.latitude},${department.longitude}`} target="_blank" rel="noreferrer">
                        View map <ExternalLink size={11} />
                      </a>
                    </div>
                  </td>
                  <td><span className="radius-pill">{department.allowed_radius_meters} m</span></td>
                  <td>{department.personnel_count}</td>
                  <td><span className={`status-badge ${department.status.toLowerCase()}`}>{department.status}</span></td>
                  <td>
                    <div className="department-actions">
                      <button type="button" onClick={() => openEdit(department)} aria-label={`Edit ${department.department_name}`}><Edit3 size={15} /></button>
                      <button type="button" className="danger" onClick={() => deleteDepartment(department)} disabled={deletingId === department.department_id} aria-label={`Delete ${department.department_name}`}><Trash2 size={15} /></button>
                    </div>
                  </td>
                </tr>
              )) : <tr><td colSpan="7" className="users-empty">No departments match the selected filters.</td></tr>}
            </tbody>
          </table>
        </div>
      </div>

      {modalOpen && (
        <ModalPortal>
        <div className="modal-backdrop department-modal-backdrop" role="presentation" onMouseDown={(event) => {
          if (event.target === event.currentTarget) closeModal();
        }}>
          <div className="user-modal admin-form-modal department-modal" role="dialog" aria-modal="true" aria-labelledby="department-modal-title">
            <div className="user-modal-header">
              <div>
                <span className="modal-icon"><Building2 size={21} /></span>
                <div>
                  <h2 id="department-modal-title">{editing ? "Edit department" : "Add department"}</h2>
                  <p>Set the exact office location used for QR attendance.</p>
                </div>
              </div>
              <button type="button" onClick={closeModal} aria-label="Close"><X size={20} /></button>
            </div>

            <form className="user-form admin-form-layout department-form" onSubmit={submitForm}>
              {fieldErrors.general && <div className="form-error-banner">{fieldErrors.general[0]}</div>}

              <div className="admin-form-section">
                <div className="admin-form-section-title">
                  <Building2 size={17} />
                  <div><strong>Department identity</strong><small>Office name, code, and availability</small></div>
                </div>
                <div className="admin-form-grid">
                  <div className="form-field">
                    <label htmlFor="department_code">Department code</label>
                    <input id="department_code" name="department_code" value={form.department_code} onChange={updateForm} placeholder="e.g. DILG-IPIL" maxLength="30" required />
                    <FieldError errors={fieldErrors} name="department_code" />
                  </div>

                  <div className="form-field">
                    <label htmlFor="department_status">Status</label>
                    <select id="department_status" name="status" value={form.status} onChange={updateForm}>
                      <option>Active</option>
                      <option>Inactive</option>
                    </select>
                    <FieldError errors={fieldErrors} name="status" />
                  </div>

                  <div className="form-field form-field-full">
                    <label htmlFor="department_name">Department name</label>
                    <input id="department_name" name="department_name" value={form.department_name} onChange={updateForm} placeholder="Enter the full department name" required />
                    <FieldError errors={fieldErrors} name="department_name" />
                  </div>
                </div>
              </div>

              <div className="admin-form-section">
                <div className="admin-form-section-title">
                  <MapPin size={17} />
                  <div><strong>Office location and attendance radius</strong><small>Secure GPS boundary used for onsite attendance</small></div>
                </div>
                <div className="admin-form-grid">
                  <div className="form-field form-field-full">
                    <label htmlFor="office_location">DILG office address</label>
                    <input id="office_location" name="office_location" value={form.office_location} onChange={updateForm} placeholder="Building, municipality, province" required />
                    <FieldError errors={fieldErrors} name="office_location" />
                  </div>

                  <div className="department-location-capture form-field-full">
                    <div>
                      <MapPin size={18} />
                      <span><strong>Office GPS coordinates</strong><small>Stand at the office and use a phone with precise location enabled.</small></span>
                    </div>
                    <button type="button" onClick={captureCoordinates} disabled={locating}>
                      <Crosshair size={16} />{locating ? "Locating…" : "Use current location"}
                    </button>
                    {locationAccuracy !== null && <p>Coordinates captured with approximately ±{locationAccuracy} m accuracy.</p>}
                    {fieldErrors.location && <small className="field-error">{fieldErrors.location[0]}</small>}
                  </div>

                  <div className="form-field">
                    <label htmlFor="department_latitude">Latitude</label>
                    <input id="department_latitude" name="latitude" type="number" step="0.0000001" min="-90" max="90" value={form.latitude} onChange={updateForm} placeholder="7.7845000" required />
                    <FieldError errors={fieldErrors} name="latitude" />
                  </div>

                  <div className="form-field">
                    <label htmlFor="department_longitude">Longitude</label>
                    <input id="department_longitude" name="longitude" type="number" step="0.0000001" min="-180" max="180" value={form.longitude} onChange={updateForm} placeholder="122.5868000" required />
                    <FieldError errors={fieldErrors} name="longitude" />
                  </div>

                  <div className="form-field form-field-full">
                    <label htmlFor="allowed_radius_meters">Allowed attendance radius <span>25–5,000 meters</span></label>
                    <div className="radius-input">
                      <input id="allowed_radius_meters" name="allowed_radius_meters" type="number" min="25" max="5000" value={form.allowed_radius_meters} onChange={updateForm} required />
                      <strong>meters</strong>
                    </div>
                    <FieldError errors={fieldErrors} name="allowed_radius_meters" />
                  </div>
                </div>
              </div>

              {editing && (
                <div className="admin-form-section office-network-section">
                  <div className="admin-form-section-title">
                    <Wifi size={17} />
                    <div>
                      <strong>Office network verification</strong>
                      <small>Secure laptop fallback when browser GPS is unavailable or inaccurate</small>
                    </div>
                  </div>

                  {(editing.office_networks || []).length > 0 && (
                    <div className="office-network-list">
                      {editing.office_networks.map((network) => (
                        <div key={network.office_network_id}>
                          <span>
                            <strong>{network.network_name}</strong>
                            <small>{network.ip_address} · expires {new Date(network.expires_at).toLocaleDateString()}</small>
                          </span>
                          {canManageNetworks && (
                            <button type="button" onClick={() => revokeOfficeNetwork(network)} disabled={networkBusy}>
                              Revoke
                            </button>
                          )}
                        </div>
                      ))}
                    </div>
                  )}

                  {canManageNetworks ? (
                    <div className="office-network-register">
                      <label htmlFor="office_network_name">Network label</label>
                      <div>
                        <input
                          id="office_network_name"
                          value={networkName}
                          onChange={(event) => setNetworkName(event.target.value)}
                          maxLength="80"
                          placeholder="e.g. Main office internet"
                        />
                        <button type="button" onClick={registerOfficeNetwork} disabled={networkBusy || !networkName.trim()}>
                          <Wifi size={15} />{networkBusy ? "Checking…" : "Register current network"}
                        </button>
                      </div>
                      <small>The server records the signed public IP from Vercel—not an address entered in this form. Registration expires automatically.</small>
                    </div>
                  ) : (
                    <p className="office-network-readonly">Only an Administrator can add or revoke a trusted office network.</p>
                  )}
                  <FieldError errors={fieldErrors} name="office_network" />
                </div>
              )}

              <div className="user-modal-actions">
                <button type="button" className="secondary-action" onClick={closeModal}>Cancel</button>
                <button type="submit" className="primary-action" disabled={saving || locating || networkBusy}>
                  {saving ? "Saving…" : editing ? "Save department" : "Add department"}
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

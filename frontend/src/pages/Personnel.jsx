import { useEffect, useRef, useState } from "react";
import {
  BriefcaseBusiness,
  Building2,
  Camera,
  GraduationCap,
  IdCard,
  ImagePlus,
  Mail,
  PenLine,
  Pencil,
  Phone,
  Plus,
  Search,
  Trash2,
  UserCheck,
  Users,
  X,
} from "lucide-react";
import useConfirmDialog from "../hooks/useConfirmDialog";
import { apiFetch } from "../lib/auth";

function toDateInput(date) {
  const year = date.getFullYear();
  const month = String(date.getMonth() + 1).padStart(2, "0");
  const day = String(date.getDate()).padStart(2, "0");
  return `${year}-${month}-${day}`;
}

function defaultCardValidity() {
  const validFrom = new Date();
  const validUntil = new Date(validFrom);
  validUntil.setFullYear(validUntil.getFullYear() + 1);
  validUntil.setDate(validUntil.getDate() - 1);

  return {
    qr_valid_from: toDateInput(validFrom),
    qr_valid_until: toDateInput(validUntil),
  };
}

const emptyForm = {
  employee_number: "",
  auto_generate_employee_number: "1",
  first_name: "",
  middle_name: "",
  last_name: "",
  suffix: "",
  sex: "",
  personnel_type: "GIP",
  position_title: "",
  department_id: "",
  employment_start_date: "",
  employment_end_date: "",
  ...defaultCardValidity(),
  email: "",
  contact_number: "",
  address: "",
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

export default function Personnel() {
  const { confirm, confirmationDialog } = useConfirmDialog();
  const [records, setRecords] = useState([]);
  const [summary, setSummary] = useState({ total: 0, active: 0, gip: 0, other_staff: 0 });
  const [options, setOptions] = useState({
    types: [],
    statuses: [],
    sex_options: [],
    departments: [],
  });
  const [search, setSearch] = useState("");
  const [typeFilter, setTypeFilter] = useState("");
  const [statusFilter, setStatusFilter] = useState("");
  const [departmentFilter, setDepartmentFilter] = useState("");
  const [loading, setLoading] = useState(true);
  const [pageError, setPageError] = useState("");
  const [notice, setNotice] = useState("");
  const [refreshKey, setRefreshKey] = useState(0);
  const [modalOpen, setModalOpen] = useState(false);
  const [editingRecord, setEditingRecord] = useState(null);
  const [form, setForm] = useState(emptyForm);
  const [fieldErrors, setFieldErrors] = useState({});
  const [saving, setSaving] = useState(false);
  const [deletingId, setDeletingId] = useState(null);
  const [photoFile, setPhotoFile] = useState(null);
  const [photoPreview, setPhotoPreview] = useState("");
  const [removePhoto, setRemovePhoto] = useState(false);
  const [signatureFile, setSignatureFile] = useState(null);
  const [signaturePreview, setSignaturePreview] = useState("");
  const [removeSignature, setRemoveSignature] = useState(false);
  const photoObjectUrl = useRef("");
  const signatureObjectUrl = useRef("");

  function releasePhotoObjectUrl() {
    if (photoObjectUrl.current) {
      URL.revokeObjectURL(photoObjectUrl.current);
      photoObjectUrl.current = "";
    }
  }

  function releaseSignatureObjectUrl() {
    if (signatureObjectUrl.current) {
      URL.revokeObjectURL(signatureObjectUrl.current);
      signatureObjectUrl.current = "";
    }
  }

  useEffect(() => () => {
    releasePhotoObjectUrl();
    releaseSignatureObjectUrl();
  }, []);

  useEffect(() => {
    const controller = new AbortController();

    apiFetch("/personnel/options", { signal: controller.signal })
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
      if (typeFilter) params.set("type", typeFilter);
      if (statusFilter) params.set("status", statusFilter);
      if (departmentFilter) params.set("department_id", departmentFilter);

      try {
        const query = params.toString();
        const payload = await apiFetch(`/personnel${query ? `?${query}` : ""}`, {
          signal: controller.signal,
        }).then(readResponse);

        setRecords(payload.data);
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
  }, [search, typeFilter, statusFilter, departmentFilter, refreshKey]);

  function openCreateModal() {
    releasePhotoObjectUrl();
    releaseSignatureObjectUrl();
    setEditingRecord(null);
    setForm({ ...emptyForm, ...defaultCardValidity() });
    setPhotoFile(null);
    setPhotoPreview("");
    setRemovePhoto(false);
    setSignatureFile(null);
    setSignaturePreview("");
    setRemoveSignature(false);
    setFieldErrors({});
    setModalOpen(true);
  }

  function openEditModal(record) {
    releasePhotoObjectUrl();
    releaseSignatureObjectUrl();
    setEditingRecord(record);
    setForm({
      employee_number: record.employee_number || "",
      auto_generate_employee_number: "0",
      first_name: record.first_name || "",
      middle_name: record.middle_name || "",
      last_name: record.last_name || "",
      suffix: record.suffix || "",
      sex: record.sex || "",
      personnel_type: record.personnel_type,
      position_title: record.position_title || "",
      department_id: record.department_id ? String(record.department_id) : "",
      employment_start_date: record.employment_start_date || "",
      employment_end_date: record.employment_end_date || "",
      qr_valid_from: record.qr_valid_from || "",
      qr_valid_until: record.qr_valid_until || "",
      email: record.email || "",
      contact_number: record.contact_number || "",
      address: record.address || "",
      status: record.status,
    });
    setPhotoFile(null);
    setPhotoPreview(record.photo_url || "");
    setRemovePhoto(false);
    setSignatureFile(null);
    setSignaturePreview(record.signature_url || "");
    setRemoveSignature(false);
    setFieldErrors({});
    setModalOpen(true);
  }

  function closeModal() {
    if (saving) return;
    setModalOpen(false);
    setEditingRecord(null);
    releasePhotoObjectUrl();
    releaseSignatureObjectUrl();
    setPhotoFile(null);
    setPhotoPreview("");
    setRemovePhoto(false);
    setSignatureFile(null);
    setSignaturePreview("");
    setRemoveSignature(false);
    setFieldErrors({});
  }

  function updateForm(event) {
    const { name, value } = event.target;
    setForm((current) => ({
      ...current,
      [name]: value,
      ...(name === "personnel_type" && value === "GIP"
        ? { auto_generate_employee_number: "1" }
        : {}),
    }));
    setFieldErrors((current) => ({ ...current, [name]: undefined }));
  }

  function selectPhoto(event) {
    const file = event.target.files?.[0];
    event.target.value = "";

    if (!file) return;

    const allowedTypes = ["image/jpeg", "image/png", "image/webp"];

    if (!allowedTypes.includes(file.type)) {
      setFieldErrors((current) => ({
        ...current,
        photo: ["Choose a JPEG, PNG, or WebP image."],
      }));
      return;
    }

    if (file.size > 3 * 1024 * 1024) {
      setFieldErrors((current) => ({
        ...current,
        photo: ["The photo must not be larger than 3 MB."],
      }));
      return;
    }

    releasePhotoObjectUrl();
    photoObjectUrl.current = URL.createObjectURL(file);
    setPhotoFile(file);
    setPhotoPreview(photoObjectUrl.current);
    setRemovePhoto(false);
    setFieldErrors((current) => ({ ...current, photo: undefined }));
  }

  function clearPhoto() {
    releasePhotoObjectUrl();
    setPhotoFile(null);
    setPhotoPreview("");
    setRemovePhoto(Boolean(editingRecord?.photo_url));
    setFieldErrors((current) => ({ ...current, photo: undefined }));
  }

  function selectSignature(event) {
    const file = event.target.files?.[0];
    event.target.value = "";

    if (!file) return;

    const allowedTypes = ["image/jpeg", "image/png", "image/webp"];

    if (!allowedTypes.includes(file.type)) {
      setFieldErrors((current) => ({
        ...current,
        signature: ["Choose a JPEG, PNG, or WebP signature image."],
      }));
      return;
    }

    if (file.size > 2 * 1024 * 1024) {
      setFieldErrors((current) => ({
        ...current,
        signature: ["The signature image must not be larger than 2 MB."],
      }));
      return;
    }

    releaseSignatureObjectUrl();
    signatureObjectUrl.current = URL.createObjectURL(file);
    setSignatureFile(file);
    setSignaturePreview(signatureObjectUrl.current);
    setRemoveSignature(false);
    setFieldErrors((current) => ({ ...current, signature: undefined }));
  }

  function clearSignature() {
    releaseSignatureObjectUrl();
    setSignatureFile(null);
    setSignaturePreview("");
    setRemoveSignature(Boolean(editingRecord?.signature_url));
    setFieldErrors((current) => ({ ...current, signature: undefined }));
  }

  async function submitForm(event) {
    event.preventDefault();
    setSaving(true);
    setFieldErrors({});

    const body = new FormData();
    Object.entries(form).forEach(([key, value]) => body.append(key, value));

    if (photoFile) body.append("photo", photoFile);
    if (removePhoto) body.append("remove_photo", "1");
    if (signatureFile) body.append("signature", signatureFile);
    if (removeSignature) body.append("remove_signature", "1");
    if (editingRecord) body.append("_method", "PUT");

    try {
      const response = await apiFetch(
        editingRecord ? `/personnel/${editingRecord.personnel_id}` : "/personnel",
        {
          method: "POST",
          body,
        },
      );
      const payload = await readResponse(response);

      setNotice(payload.message);
      setModalOpen(false);
      setEditingRecord(null);
      releasePhotoObjectUrl();
      releaseSignatureObjectUrl();
      setPhotoFile(null);
      setPhotoPreview("");
      setRemovePhoto(false);
      setSignatureFile(null);
      setSignaturePreview("");
      setRemoveSignature(false);
      setRefreshKey((key) => key + 1);
      window.setTimeout(() => setNotice(""), 3500);
    } catch (error) {
      setFieldErrors(
        Object.keys(error.fields || {}).length
          ? error.fields
          : { general: [error.message] },
      );
    } finally {
      setSaving(false);
    }
  }

  async function deleteRecord(record) {
    const confirmed = await confirm({
      title: "Delete personnel record?",
      message: `Delete the personnel record for ${record.full_name}?`,
      note: "This action is permanent and may be blocked when attendance or DTR records exist.",
      confirmLabel: "Delete personnel",
    });
    if (!confirmed) return;

    setDeletingId(record.personnel_id);
    setPageError("");

    try {
      const payload = await apiFetch(`/personnel/${record.personnel_id}`, {
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

  const summaryItems = [
    { label: "Total Personnel", value: summary.total, icon: Users, tone: "blue" },
    { label: "Active", value: summary.active, icon: UserCheck, tone: "green" },
    { label: "GIP Participants", value: summary.gip, icon: GraduationCap, tone: "orange" },
    { label: "Other Staff", value: summary.other_staff, icon: BriefcaseBusiness, tone: "gray" },
  ];

  return (
    <section className="personnel-page">
      <div className="page-title users-page-title">
        <div>
          <h1>Personnel Directory</h1>
          <nav className="breadcrumb" aria-label="Breadcrumb">
            <span>Home</span><span>/</span><strong>Personnel</strong>
          </nav>
        </div>
        <button type="button" className="primary-action" onClick={openCreateModal}>
          <Plus size={18} />
          Add personnel
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
        <div className="users-toolbar personnel-toolbar">
          <div className="users-search">
            <Search size={18} />
            <input
              type="search"
              value={search}
              onChange={(event) => setSearch(event.target.value)}
              placeholder="Search name, employee number, email..."
              aria-label="Search personnel"
            />
          </div>
          <select value={typeFilter} onChange={(event) => setTypeFilter(event.target.value)}>
            <option value="">All personnel types</option>
            {options.types.map((type) => <option key={type}>{type}</option>)}
          </select>
          <select value={departmentFilter} onChange={(event) => setDepartmentFilter(event.target.value)}>
            <option value="">All departments</option>
            {options.departments.map((department) => (
              <option key={department.department_id} value={department.department_id}>
                {department.department_code}
              </option>
            ))}
          </select>
          <select value={statusFilter} onChange={(event) => setStatusFilter(event.target.value)}>
            <option value="">All statuses</option>
            {options.statuses.map((status) => <option key={status}>{status}</option>)}
          </select>
        </div>

        <div className="users-table-wrap">
          <table className="users-table personnel-table">
            <thead>
              <tr>
                <th>Personnel</th>
                <th>Employment</th>
                <th>Department</th>
                <th>Contact</th>
                <th>Status</th>
                <th><span className="sr-only">Actions</span></th>
              </tr>
            </thead>
            <tbody>
              {loading ? (
                <tr><td colSpan="6" className="users-empty">Loading personnel records…</td></tr>
              ) : records.length === 0 ? (
                <tr><td colSpan="6" className="users-empty">No personnel records match your filters.</td></tr>
              ) : records.map((record) => (
                <tr key={record.personnel_id}>
                  <td>
                    <div className="user-identity">
                      <PersonnelAvatar record={record} />
                      <div>
                        <strong>{record.full_name}</strong>
                        <small>{record.employee_number}</small>
                      </div>
                    </div>
                  </td>
                  <td>
                    <div className="personnel-cell">
                      <strong>{record.personnel_type}</strong>
                      <small>{record.position_title || "No position specified"}</small>
                    </div>
                  </td>
                  <td>
                    {record.department ? (
                      <div className="department-cell">
                        <Building2 size={15} />
                        <div>
                          <strong>{record.department.department_code}</strong>
                          <small>{record.department.department_name}</small>
                        </div>
                      </div>
                    ) : <span className="muted-cell">Not assigned</span>}
                  </td>
                  <td>
                    <div className="contact-cell">
                      <span><Mail size={13} />{record.email || "No email"}</span>
                      <span><Phone size={13} />{record.contact_number || "No contact number"}</span>
                    </div>
                  </td>
                  <td><span className={`status-chip ${record.status.toLowerCase()}`}>{record.status}</span></td>
                  <td>
                    <div className="table-actions">
                      <button type="button" onClick={() => openEditModal(record)} aria-label={`Edit ${record.full_name}`}>
                        <Pencil size={16} />
                      </button>
                      <button
                        type="button"
                        className="danger"
                        onClick={() => deleteRecord(record)}
                        disabled={deletingId === record.personnel_id}
                        aria-label={`Delete ${record.full_name}`}
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
          Showing {records.length} personnel {records.length === 1 ? "record" : "records"}
        </div>
      </div>

      {modalOpen && (
        <div
          className="modal-backdrop"
          role="presentation"
          onMouseDown={(event) => {
            if (event.target === event.currentTarget) closeModal();
          }}
        >
          <div className="user-modal form-modal personnel-modal" role="dialog" aria-modal="true" aria-labelledby="personnel-modal-title">
            <div className="user-modal-header">
              <div>
                <span className="modal-icon"><IdCard size={21} /></span>
                <div>
                  <h2 id="personnel-modal-title">{editingRecord ? "Edit personnel" : "Add personnel"}</h2>
                  <p>{editingRecord ? "Update personal and employment information." : "Create a new GIP or employee record."}</p>
                </div>
              </div>
              <button type="button" onClick={closeModal} aria-label="Close"><X size={20} /></button>
            </div>

            <form className="user-form personnel-form" onSubmit={submitForm}>
              {fieldErrors.general && <div className="form-error-banner">{fieldErrors.general[0]}</div>}

              <div className="personnel-photo-field">
                <div className={`personnel-photo-preview ${photoPreview ? "has-photo" : ""}`}>
                  <span>{initials(form.first_name, form.last_name)}</span>
                  {photoPreview && <img src={photoPreview} alt="Personnel preview" />}
                  <i><Camera size={16} /></i>
                </div>
                <div className="personnel-photo-controls">
                  <strong>Personnel photo</strong>
                  <p>Use a clear, square image. JPEG, PNG or WebP, up to 3 MB.</p>
                  <div>
                    <label className="secondary-action personnel-photo-button">
                      <ImagePlus size={16} />
                      {photoPreview ? "Change photo" : "Upload photo"}
                      <input
                        type="file"
                        accept="image/jpeg,image/png,image/webp"
                        onChange={selectPhoto}
                      />
                    </label>
                    {photoPreview && (
                      <button type="button" className="personnel-photo-remove" onClick={clearPhoto}>
                        <Trash2 size={15} />
                        Remove
                      </button>
                    )}
                  </div>
                  <FieldError errors={fieldErrors} name="photo" />
                </div>
              </div>

              <div className="personnel-signature-field">
                <div className={`personnel-signature-preview ${signaturePreview ? "has-signature" : ""}`}>
                  {signaturePreview
                    ? <img src={signaturePreview} alt="Personnel signature preview" />
                    : <><PenLine size={28} /><span>No signature uploaded</span></>}
                </div>
                <div className="personnel-photo-controls">
                  <strong>Personnel signature <span className="optional-label">Optional</span></strong>
                  <p>Upload a clear signature on a white or transparent background. JPEG, PNG or WebP, up to 2 MB.</p>
                  <div>
                    <label className="secondary-action personnel-photo-button">
                      <PenLine size={16} />
                      {signaturePreview ? "Change signature" : "Upload signature"}
                      <input
                        type="file"
                        accept="image/jpeg,image/png,image/webp"
                        onChange={selectSignature}
                      />
                    </label>
                    {signaturePreview && (
                      <button type="button" className="personnel-photo-remove" onClick={clearSignature}>
                        <Trash2 size={15} />
                        Remove
                      </button>
                    )}
                  </div>
                  <FieldError errors={fieldErrors} name="signature" />
                </div>
              </div>

              <h3 className="form-section-title">Personal information</h3>
              <FormField label="First name" name="first_name" form={form} errors={fieldErrors} onChange={updateForm} required />
              <FormField label="Middle name" name="middle_name" form={form} errors={fieldErrors} onChange={updateForm} />
              <FormField label="Last name" name="last_name" form={form} errors={fieldErrors} onChange={updateForm} required />
              <FormField label="Suffix" name="suffix" form={form} errors={fieldErrors} onChange={updateForm} placeholder="Jr., Sr., III" />

              <div className="form-field">
                <label htmlFor="sex">Sex</label>
                <select id="sex" name="sex" value={form.sex} onChange={updateForm}>
                  <option value="">Not specified</option>
                  {options.sex_options.map((option) => <option key={option}>{option}</option>)}
                </select>
                <FieldError errors={fieldErrors} name="sex" />
              </div>
              <FormField label="Contact number" name="contact_number" form={form} errors={fieldErrors} onChange={updateForm} />
              <FormField label="Email address" name="email" type="email" form={form} errors={fieldErrors} onChange={updateForm} />
              <FormField label="Address" name="address" form={form} errors={fieldErrors} onChange={updateForm} full />

              <h3 className="form-section-title">Employment information</h3>
              <div className="form-field">
                <label htmlFor="personnel_type">Personnel type</label>
                <select id="personnel_type" name="personnel_type" value={form.personnel_type} onChange={updateForm} required>
                  {options.types.map((type) => <option key={type}>{type}</option>)}
                </select>
                <FieldError errors={fieldErrors} name="personnel_type" />
              </div>

              {!editingRecord && form.personnel_type !== "GIP" && (
                <label className="form-field form-field-full personnel-number-mode">
                  <span>Employee-number source</span>
                  <span className="personnel-number-toggle">
                    <input
                      type="checkbox"
                      checked={form.auto_generate_employee_number === "1"}
                      onChange={(event) => setForm((current) => ({
                        ...current,
                        auto_generate_employee_number: event.target.checked ? "1" : "0",
                      }))}
                    />
                    Generate an internal employee number automatically
                  </span>
                  <small className="field-hint">
                    Turn this off when the employee already has an official DILG number.
                  </small>
                </label>
              )}

              <div className="form-field">
                <label htmlFor="department_id">Department</label>
                <select
                  id="department_id"
                  name="department_id"
                  value={form.department_id}
                  onChange={updateForm}
                  required={
                    form.personnel_type === "GIP"
                    || (!editingRecord && form.auto_generate_employee_number === "1")
                  }
                >
                  <option value="">Not assigned</option>
                  {options.departments.map((department) => (
                    <option key={department.department_id} value={department.department_id}>
                      {department.department_code} — {department.department_name}
                    </option>
                  ))}
                </select>
                <FieldError errors={fieldErrors} name="department_id" />
              </div>

              {form.personnel_type === "GIP"
                || (!editingRecord && form.auto_generate_employee_number === "1") ? (
                <div className="form-field form-field-full">
                  <label htmlFor="employee_number">Employee number</label>
                  <input
                    id="employee_number"
                    value={
                      editingRecord
                        ? editingRecord.employee_number
                        : "Generated automatically when saved"
                    }
                    readOnly
                    aria-describedby="employee-number-hint"
                  />
                  <small id="employee-number-hint" className="field-hint">
                    Uses personnel type, office code, employment year, and a permanent sequence number.
                  </small>
                  <FieldError errors={fieldErrors} name="employee_number" />
                </div>
              ) : (
                <FormField
                  label="Official employee number"
                  name="employee_number"
                  form={form}
                  errors={fieldErrors}
                  onChange={updateForm}
                  required
                  full
                />
              )}

              <FormField label="Position title" name="position_title" form={form} errors={fieldErrors} onChange={updateForm} full />
              <FormField label="Employment start" name="employment_start_date" type="date" form={form} errors={fieldErrors} onChange={updateForm} />
              <FormField label="Employment end" name="employment_end_date" type="date" form={form} errors={fieldErrors} onChange={updateForm} />

              <h3 className="form-section-title">Personnel card validity</h3>
              <div className="form-field form-field-full">
                <small className="field-hint">
                  These dates control the QR card independently. They must remain within the configured employment period.
                </small>
              </div>
              <FormField label="Card valid from" name="qr_valid_from" type="date" form={form} errors={fieldErrors} onChange={updateForm} required />
              <FormField label="Card valid until" name="qr_valid_until" type="date" form={form} errors={fieldErrors} onChange={updateForm} required />

              <div className="form-field form-field-full">
                <label htmlFor="personnel_status">Status</label>
                <select id="personnel_status" name="status" value={form.status} onChange={updateForm} required>
                  {options.statuses.map((status) => <option key={status}>{status}</option>)}
                </select>
                <FieldError errors={fieldErrors} name="status" />
              </div>

              <div className="user-modal-actions">
                <button type="button" className="secondary-action" onClick={closeModal}>Cancel</button>
                <button type="submit" className="primary-action" disabled={saving}>
                  {saving ? "Saving…" : editingRecord ? "Save changes" : "Create personnel"}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
      {confirmationDialog}
    </section>
  );
}

function initials(firstName, lastName) {
  return `${firstName?.[0] || ""}${lastName?.[0] || ""}`.toUpperCase() || "ID";
}

function PersonnelAvatar({ record }) {
  return (
    <span className={`personnel-list-avatar ${record.photo_url ? "has-photo" : ""}`}>
      {initials(record.first_name, record.last_name)}
      {record.photo_url && <img src={record.photo_url} alt="" loading="lazy" />}
    </span>
  );
}

function FieldError({ errors, name }) {
  return errors[name] ? <small className="field-error">{errors[name][0]}</small> : null;
}

function FormField({
  label,
  name,
  form,
  errors,
  onChange,
  type = "text",
  placeholder,
  required = false,
  full = false,
}) {
  return (
    <div className={`form-field ${full ? "form-field-full" : ""}`}>
      <label htmlFor={name}>{label}</label>
      <input
        id={name}
        name={name}
        type={type}
        value={form[name]}
        onChange={onChange}
        placeholder={placeholder}
        required={required}
      />
      <FieldError errors={errors} name={name} />
    </div>
  );
}

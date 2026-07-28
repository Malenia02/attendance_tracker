import { useEffect, useMemo, useState } from "react";
import {
  CalendarCheck,
  CalendarDays,
  ChevronLeft,
  ChevronRight,
  Clock3,
  Edit3,
  MapPin,
  Plus,
  Sparkles,
  Trash2,
  X,
} from "lucide-react";
import useConfirmDialog from "../hooks/useConfirmDialog";
import { apiFetch, getStoredUser } from "../lib/auth";

const weekDays = ["Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat"];

const emptyForm = {
  holiday_date: "",
  holiday_name: "",
  holiday_type: "Regular Holiday",
  scope: "National",
  department_id: "",
  description: "",
};

function dateKey(date) {
  const year = date.getFullYear();
  const month = String(date.getMonth() + 1).padStart(2, "0");
  const day = String(date.getDate()).padStart(2, "0");
  return `${year}-${month}-${day}`;
}

function parseDate(value) {
  return new Date(`${value}T00:00:00`);
}

function longDate(value) {
  return new Intl.DateTimeFormat("en-PH", {
    weekday: "long",
    month: "long",
    day: "numeric",
    year: "numeric",
  }).format(parseDate(value));
}

function shortDate(value) {
  return new Intl.DateTimeFormat("en-PH", {
    month: "short",
    day: "numeric",
  }).format(parseDate(value));
}

function typeTone(type) {
  if (type === "Regular Holiday") return "regular";
  if (type === "Special Non-Working Holiday") return "special";
  if (type === "Special Working Holiday") return "working";
  if (type === "Office Suspension") return "suspension";
  return "local";
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

export default function HolidayCalendar() {
  const { confirm, confirmationDialog } = useConfirmDialog();
  const today = useMemo(() => new Date(), []);
  const todayKey = dateKey(today);
  const currentUser = getStoredUser();
  const canManage = ["Administrator", "HR"].includes(currentUser?.user_role);

  const [visibleMonth, setVisibleMonth] = useState(
    () => new Date(today.getFullYear(), today.getMonth(), 1),
  );
  const [selectedDate, setSelectedDate] = useState(todayKey);
  const [holidays, setHolidays] = useState([]);
  const [summary, setSummary] = useState({ total: 0, regular: 0, special: 0, local: 0 });
  const [options, setOptions] = useState({ types: [], scopes: [], departments: [] });
  const [loading, setLoading] = useState(true);
  const [pageError, setPageError] = useState("");
  const [notice, setNotice] = useState("");
  const [refreshKey, setRefreshKey] = useState(0);
  const [modalOpen, setModalOpen] = useState(false);
  const [editingHoliday, setEditingHoliday] = useState(null);
  const [form, setForm] = useState(emptyForm);
  const [fieldErrors, setFieldErrors] = useState({});
  const [saving, setSaving] = useState(false);
  const [deletingId, setDeletingId] = useState(null);

  const year = visibleMonth.getFullYear();
  const month = visibleMonth.getMonth();

  useEffect(() => {
    const controller = new AbortController();

    Promise.all([
      apiFetch(`/holidays?year=${year}`, { signal: controller.signal }).then(readResponse),
      apiFetch("/holidays/options", { signal: controller.signal }).then(readResponse),
    ])
      .then(([holidayPayload, optionPayload]) => {
        setHolidays(holidayPayload.data);
        setSummary(holidayPayload.summary);
        setOptions(optionPayload);
        setPageError("");
      })
      .catch((error) => {
        if (error.name !== "AbortError") setPageError(error.message);
      })
      .finally(() => {
        if (!controller.signal.aborted) setLoading(false);
      });

    return () => controller.abort();
  }, [year, refreshKey]);

  const holidaysByDate = useMemo(
    () =>
      holidays.reduce((map, holiday) => {
        const items = map.get(holiday.holiday_date) || [];
        items.push(holiday);
        map.set(holiday.holiday_date, items);
        return map;
      }, new Map()),
    [holidays],
  );

  const calendarDays = useMemo(() => {
    const first = new Date(year, month, 1);
    const gridStart = new Date(year, month, 1 - first.getDay());

    return Array.from({ length: 42 }, (_, index) => {
      const day = new Date(gridStart);
      day.setDate(gridStart.getDate() + index);
      return day;
    });
  }, [year, month]);

  const selectedHolidays = holidaysByDate.get(selectedDate) || [];
  const selectedDayNumber = parseDate(selectedDate).getDay();
  const selectedIsStandardDuty = selectedDayNumber >= 1 && selectedDayNumber <= 4;
  const selectedIsApprovedWorking = selectedHolidays.some(
    (holiday) => holiday.holiday_type === "Special Working Holiday",
  );
  const isWorkingDayForm = form.holiday_type === "Special Working Holiday";
  const upcomingHolidays = holidays
    .filter((holiday) => holiday.holiday_date >= todayKey)
    .slice(0, 6);

  function changeMonth(offset) {
    const next = new Date(year, month + offset, 1);
    setVisibleMonth(next);
    setSelectedDate(dateKey(next));
    setLoading(true);
  }

  function goToToday() {
    setVisibleMonth(new Date(today.getFullYear(), today.getMonth(), 1));
    setSelectedDate(todayKey);
  }

  function openCreate(date = selectedDate) {
    setEditingHoliday(null);
    setForm({ ...emptyForm, holiday_date: date });
    setFieldErrors({});
    setModalOpen(true);
  }

  function openWorkingDay(date = selectedDate) {
    setEditingHoliday(null);
    setForm({
      ...emptyForm,
      holiday_date: date,
      holiday_name: "Approved Working Day",
      holiday_type: "Special Working Holiday",
      scope: "Office",
      description: "Optional working day approved by the Regional Director.",
    });
    setFieldErrors({});
    setModalOpen(true);
  }

  function openEdit(holiday) {
    setEditingHoliday(holiday);
    setForm({
      holiday_date: holiday.holiday_date,
      holiday_name: holiday.holiday_name,
      holiday_type: holiday.holiday_type,
      scope: holiday.scope,
      department_id: holiday.department_id ? String(holiday.department_id) : "",
      description: holiday.description || "",
    });
    setFieldErrors({});
    setModalOpen(true);
  }

  function closeModal() {
    if (saving) return;
    setModalOpen(false);
    setEditingHoliday(null);
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

    try {
      const response = await apiFetch(
        editingHoliday ? `/holidays/${editingHoliday.holiday_id}` : "/holidays",
        {
          method: editingHoliday ? "PUT" : "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({
            ...form,
            department_id: form.department_id ? Number(form.department_id) : null,
            description: form.description || null,
          }),
        },
      );
      const payload = await readResponse(response);

      setNotice(payload.message);
      setSelectedDate(form.holiday_date);
      setVisibleMonth(
        new Date(parseDate(form.holiday_date).getFullYear(), parseDate(form.holiday_date).getMonth(), 1),
      );
      setModalOpen(false);
      setEditingHoliday(null);
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

  async function deleteHoliday(holiday) {
    const confirmed = await confirm({
      title: "Remove calendar entry?",
      message: `Remove “${holiday.holiday_name}” from the attendance calendar?`,
      note: "Attendance and DTR calculations may change for this date.",
      confirmLabel: "Remove entry",
    });
    if (!confirmed) return;

    setDeletingId(holiday.holiday_id);
    setPageError("");

    try {
      const payload = await apiFetch(`/holidays/${holiday.holiday_id}`, {
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

  return (
    <section className="holiday-page">
      <div className="holiday-hero">
        <div className="holiday-hero-copy">
          <span className="holiday-kicker"><Sparkles size={14} /> Work calendar</span>
          <h1>Calendar & Holidays</h1>
          <p>View official holidays, local observances, and office suspensions in one place.</p>
        </div>
        {canManage && (
          <button type="button" className="holiday-add-button" onClick={() => openCreate()}>
            <Plus size={18} />
            Add holiday
          </button>
        )}
        <div className="holiday-orb holiday-orb-one"></div>
        <div className="holiday-orb holiday-orb-two"></div>
      </div>

      {notice && <div className="users-notice success holiday-notice"><CalendarCheck size={18} />{notice}</div>}
      {pageError && <div className="users-notice error holiday-notice"><X size={18} />{pageError}</div>}

      <div className="holiday-summary-grid">
        <article><span>Total</span><strong>{summary.total}</strong><small>{year} holidays</small></article>
        <article><span>Regular</span><strong>{summary.regular}</strong><small>Official holidays</small></article>
        <article><span>Special</span><strong>{summary.special}</strong><small>Working & non-working</small></article>
        <article><span>Local</span><strong>{summary.local}</strong><small>Local & office events</small></article>
      </div>

      <div className="calendar-layout">
        <div className="calendar-card">
          <header className="calendar-toolbar">
            <div>
              <span className="calendar-month-label">Monthly calendar</span>
              <h2>{visibleMonth.toLocaleDateString("en-PH", { month: "long", year: "numeric" })}</h2>
            </div>
            <div className="calendar-controls">
              <button type="button" onClick={() => changeMonth(-1)} aria-label="Previous month"><ChevronLeft size={19} /></button>
              <button type="button" className="today-button" onClick={goToToday}>Today</button>
              <button type="button" onClick={() => changeMonth(1)} aria-label="Next month"><ChevronRight size={19} /></button>
            </div>
          </header>

          <div className="calendar-duty-legend">
            <span><i className="standard"></i>Duty days <strong>Mon–Thu</strong></span>
            <span><i className="optional"></i>Optional days <strong>Fri–Sun</strong></span>
            <span><i className="holiday"></i>Holiday</span>
          </div>

          <div className={`calendar-grid ${loading ? "calendar-loading" : ""}`}>
            {weekDays.map((day) => <div className="weekday" key={day}>{day}</div>)}
            {calendarDays.map((day) => {
              const key = dateKey(day);
              const dayHolidays = holidaysByDate.get(key) || [];
              const isOutside = day.getMonth() !== month;
              const isToday = key === todayKey;
              const isSelected = key === selectedDate;
              const dayNumber = day.getDay();
              const isStandardDuty = dayNumber >= 1 && dayNumber <= 4;
              const isApprovedWorking = dayHolidays.some(
                (holiday) => holiday.holiday_type === "Special Working Holiday",
              );
              const hasNonWorkingHoliday = dayHolidays.some(
                (holiday) => holiday.holiday_type !== "Special Working Holiday",
              );

              return (
                <button
                  type="button"
                  key={key}
                  className={[
                    "calendar-day",
                    isOutside ? "outside" : "",
                    isToday ? "today" : "",
                    isSelected ? "selected" : "",
                    hasNonWorkingHoliday ? "has-holiday" : "",
                    isApprovedWorking && !hasNonWorkingHoliday ? "has-working-day" : "",
                    isStandardDuty ? "standard-duty" : "optional-duty",
                  ].filter(Boolean).join(" ")}
                  onClick={() => setSelectedDate(key)}
                  onDoubleClick={() => canManage && openCreate(key)}
                >
                  <span className="day-number">{day.getDate()}</span>
                  <div className="day-events">
                    {dayHolidays.slice(0, 2).map((holiday) => (
                      <span className={`day-event ${typeTone(holiday.holiday_type)}`} key={holiday.holiday_id}>
                        {holiday.holiday_name}
                      </span>
                    ))}
                    {dayHolidays.length > 2 && <small>+{dayHolidays.length - 2} more</small>}
                  </div>
                </button>
              );
            })}
          </div>
        </div>

        <aside className="calendar-sidebar">
          <div className="selected-date-card">
            <div className="selected-date-heading">
              <div>
                <span>Selected date</span>
                <h2>{longDate(selectedDate)}</h2>
              </div>
              {canManage && (
                <button type="button" onClick={() => openCreate(selectedDate)} aria-label="Add holiday on selected date">
                  <Plus size={18} />
                </button>
              )}
            </div>

            <div className={`selected-duty-status ${selectedIsStandardDuty || selectedIsApprovedWorking ? "standard" : "optional"}`}>
              <span>
                {selectedIsApprovedWorking
                  ? "Approved working day"
                  : selectedIsStandardDuty ? "Standard working day" : "Optional working day"}
              </span>
              <p>
                {selectedIsApprovedWorking
                  ? "Attendance and QR scanning are enabled for this approved date."
                  : selectedIsStandardDuty
                    ? "Included in the regular Monday–Thursday work schedule."
                    : "Work may be scheduled with approval from the Regional Director."}
              </p>
              {canManage && !selectedIsStandardDuty && !selectedIsApprovedWorking && (
                <button type="button" className="set-working-day-button" onClick={() => openWorkingDay(selectedDate)}>
                  <CalendarCheck size={16} />
                  Set as working day
                </button>
              )}
            </div>

            <div className="selected-events">
              {selectedHolidays.length ? selectedHolidays.map((holiday) => (
                <article className={`selected-event ${typeTone(holiday.holiday_type)}`} key={holiday.holiday_id}>
                  <i></i>
                  <div>
                    <span>{holiday.holiday_type}</span>
                    <h3>{holiday.holiday_name}</h3>
                    <p><MapPin size={13} />{holiday.department?.department_code || holiday.scope}</p>
                    {holiday.description && <small>{holiday.description}</small>}
                  </div>
                  {canManage && (
                    <div className="holiday-actions">
                      <button type="button" onClick={() => openEdit(holiday)} aria-label={`Edit ${holiday.holiday_name}`}><Edit3 size={15} /></button>
                      <button type="button" onClick={() => deleteHoliday(holiday)} disabled={deletingId === holiday.holiday_id} aria-label={`Delete ${holiday.holiday_name}`}><Trash2 size={15} /></button>
                    </div>
                  )}
                </article>
              )) : (
                <div className="no-events">
                  <CalendarDays size={27} />
                  <strong>No holidays scheduled</strong>
                  <span>This day is clear on the work calendar.</span>
                </div>
              )}
            </div>
          </div>

          <div className="upcoming-card">
            <div className="upcoming-heading">
              <div><Clock3 size={17} /><h2>Upcoming</h2></div>
              <span>{year}</span>
            </div>
            <div className="upcoming-list">
              {upcomingHolidays.length ? upcomingHolidays.map((holiday) => (
                <button
                  type="button"
                  key={holiday.holiday_id}
                  onClick={() => {
                    const date = parseDate(holiday.holiday_date);
                    setVisibleMonth(new Date(date.getFullYear(), date.getMonth(), 1));
                    setSelectedDate(holiday.holiday_date);
                  }}
                >
                  <time><strong>{shortDate(holiday.holiday_date).split(" ")[1]}</strong><span>{shortDate(holiday.holiday_date).split(" ")[0]}</span></time>
                  <div><strong>{holiday.holiday_name}</strong><span>{holiday.scope}</span></div>
                  <i className={typeTone(holiday.holiday_type)}></i>
                </button>
              )) : <p className="upcoming-empty">No upcoming holidays for {year}.</p>}
            </div>
          </div>
        </aside>
      </div>

      {modalOpen && (
        <div className="modal-backdrop holiday-modal-backdrop" role="presentation" onMouseDown={(event) => {
          if (event.target === event.currentTarget) closeModal();
        }}>
          <div className="user-modal holiday-modal" role="dialog" aria-modal="true" aria-labelledby="holiday-modal-title">
            <div className="user-modal-header">
              <div>
                <span className="modal-icon"><CalendarCheck size={21} /></span>
                <div>
                  <h2 id="holiday-modal-title">
                    {editingHoliday
                      ? isWorkingDayForm ? "Edit working day" : "Edit holiday"
                      : isWorkingDayForm ? "Add working day" : "Add holiday"}
                  </h2>
                  <p>
                    {isWorkingDayForm
                      ? "Approve an optional date for attendance and DTR tracking."
                      : "Keep the official attendance calendar accurate."}
                  </p>
                </div>
              </div>
              <button type="button" onClick={closeModal} aria-label="Close"><X size={20} /></button>
            </div>

            <form className="user-form holiday-form" onSubmit={submitForm}>
              {fieldErrors.general && <div className="form-error-banner">{fieldErrors.general[0]}</div>}

              <div className="form-field">
                <label htmlFor="holiday_date">Date</label>
                <input id="holiday_date" name="holiday_date" type="date" value={form.holiday_date} onChange={updateForm} required />
                {fieldErrors.holiday_date && <small className="field-error">{fieldErrors.holiday_date[0]}</small>}
              </div>

              <div className="form-field">
                <label htmlFor="holiday_scope">Scope</label>
                <select id="holiday_scope" name="scope" value={form.scope} onChange={updateForm} required>
                  {options.scopes.map((scope) => <option key={scope}>{scope}</option>)}
                </select>
                {fieldErrors.scope && <small className="field-error">{fieldErrors.scope[0]}</small>}
              </div>

              <div className="form-field form-field-full">
                <label htmlFor="holiday_name">{isWorkingDayForm ? "Working day name" : "Holiday name"}</label>
                <input
                  id="holiday_name"
                  name="holiday_name"
                  value={form.holiday_name}
                  onChange={updateForm}
                  placeholder={isWorkingDayForm ? "Enter the approved working day name" : "Enter the official holiday name"}
                  required
                />
                {fieldErrors.holiday_name && <small className="field-error">{fieldErrors.holiday_name[0]}</small>}
              </div>

              <div className="form-field form-field-full">
                <label htmlFor="holiday_type">Calendar entry type</label>
                <select id="holiday_type" name="holiday_type" value={form.holiday_type} onChange={updateForm} required>
                  {options.types.map((type) => (
                    <option key={type} value={type}>
                      {type === "Special Working Holiday" ? "Approved Working Day" : type}
                    </option>
                  ))}
                </select>
                {fieldErrors.holiday_type && <small className="field-error">{fieldErrors.holiday_type[0]}</small>}
              </div>

              <div className="form-field form-field-full">
                <label htmlFor="holiday_department">Department <span>Optional</span></label>
                <select id="holiday_department" name="department_id" value={form.department_id} onChange={updateForm}>
                  <option value="">All departments</option>
                  {options.departments.map((department) => (
                    <option key={department.department_id} value={department.department_id}>
                      {department.department_code} — {department.department_name}
                    </option>
                  ))}
                </select>
                {fieldErrors.department_id && <small className="field-error">{fieldErrors.department_id[0]}</small>}
              </div>

              <div className="form-field form-field-full">
                <label htmlFor="holiday_description">Description <span>Optional</span></label>
                <textarea id="holiday_description" name="description" value={form.description} onChange={updateForm} rows="3" maxLength="255" placeholder="Add notes or coverage details…" />
                {fieldErrors.description && <small className="field-error">{fieldErrors.description[0]}</small>}
              </div>

              <div className="user-modal-actions">
                <button type="button" className="secondary-action" onClick={closeModal}>Cancel</button>
                <button type="submit" className="primary-action" disabled={saving}>
                  {saving
                    ? "Saving…"
                    : editingHoliday
                      ? `Save ${isWorkingDayForm ? "working day" : "holiday"}`
                      : `Add ${isWorkingDayForm ? "working day" : "holiday"}`}
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

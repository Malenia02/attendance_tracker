import { useEffect, useMemo, useState } from "react";
import {
  AlertCircle,
  BriefcaseBusiness,
  CalendarCheck2,
  CalendarClock,
  Check,
  Clock3,
  Edit3,
  Plus,
  Search,
  ShieldCheck,
  TimerReset,
  Trash2,
  UserPlus,
  Users,
  X,
} from "lucide-react";
import useConfirmDialog from "../hooks/useConfirmDialog";
import { apiFetch } from "../lib/auth";

const days = [
  ["monday", "Mon"],
  ["tuesday", "Tue"],
  ["wednesday", "Wed"],
  ["thursday", "Thu"],
  ["friday", "Fri"],
  ["saturday", "Sat"],
  ["sunday", "Sun"],
];

const emptySchedule = {
  schedule_name: "DILG GIP Compressed Workweek",
  morning_start: "07:00",
  morning_end: "12:00",
  afternoon_start: "13:00",
  afternoon_end: "18:00",
  morning_time_in_start: "05:00",
  morning_time_in_end: "11:00",
  morning_time_out_start: "11:30",
  morning_time_out_end: "12:30",
  afternoon_time_in_start: "12:30",
  afternoon_time_in_end: "14:00",
  afternoon_time_out_start: "17:30",
  afternoon_time_out_end: "20:00",
  grace_period_minutes: "0",
  required_minutes_per_day: "600",
  monday: true,
  tuesday: true,
  wednesday: true,
  thursday: true,
  friday: false,
  saturday: false,
  sunday: false,
  status: "Active",
};

function localDateInputValue(date = new Date()) {
  const timezoneOffset = date.getTimezoneOffset() * 60_000;
  return new Date(date.getTime() - timezoneOffset).toISOString().slice(0, 10);
}

function createEmptyAssignment() {
  return {
    schedule_id: "",
    effective_from: localDateInputValue(),
    effective_to: "",
  };
}

async function readResponse(response) {
  const payload = await response.json().catch(() => ({}));

  if (!response.ok) {
    const validationMessage = Object.values(payload.errors || {}).flat()[0];
    const error = new Error(validationMessage || payload.message || "The request could not be completed.");
    error.fields = payload.errors || {};
    throw error;
  }

  return payload;
}

function shortTime(value) {
  return value ? value.slice(0, 5) : "";
}

function displayTime(value) {
  if (!value) return "—";
  const [hour, minute] = value.split(":").map(Number);
  const suffix = hour >= 12 ? "PM" : "AM";
  const displayHour = hour % 12 || 12;
  return `${displayHour}:${String(minute).padStart(2, "0")} ${suffix}`;
}

function durationLabel(minutes) {
  const hours = Math.floor(minutes / 60);
  const remainder = minutes % 60;
  return remainder ? `${hours}h ${remainder}m` : `${hours} hours`;
}

function FieldError({ errors, name }) {
  return errors[name]?.length ? <small className="field-error">{errors[name][0]}</small> : null;
}

export default function Schedules() {
  const { confirm, confirmationDialog } = useConfirmDialog();
  const [schedules, setSchedules] = useState([]);
  const [personnel, setPersonnel] = useState([]);
  const [summary, setSummary] = useState({
    total: 0,
    active: 0,
    assigned_personnel: 0,
    unassigned_personnel: 0,
  });
  const [loading, setLoading] = useState(true);
  const [pageError, setPageError] = useState("");
  const [notice, setNotice] = useState("");
  const [refreshKey, setRefreshKey] = useState(0);
  const [scheduleSearch, setScheduleSearch] = useState("");
  const [personnelSearch, setPersonnelSearch] = useState("");
  const [assignmentFilter, setAssignmentFilter] = useState("");
  const [scheduleModal, setScheduleModal] = useState(false);
  const [editingSchedule, setEditingSchedule] = useState(null);
  const [scheduleForm, setScheduleForm] = useState(emptySchedule);
  const [scheduleErrors, setScheduleErrors] = useState({});
  const [savingSchedule, setSavingSchedule] = useState(false);
  const [assignmentModal, setAssignmentModal] = useState(false);
  const [assignmentForm, setAssignmentForm] = useState(createEmptyAssignment);
  const [selectedPersonnel, setSelectedPersonnel] = useState([]);
  const [assignmentSearch, setAssignmentSearch] = useState("");
  const [assignmentErrors, setAssignmentErrors] = useState({});
  const [savingAssignment, setSavingAssignment] = useState(false);
  const [deletingId, setDeletingId] = useState(null);

  useEffect(() => {
    const controller = new AbortController();

    apiFetch("/schedules", { signal: controller.signal })
      .then(readResponse)
      .then((payload) => {
        setSchedules(payload.data);
        setPersonnel(payload.personnel);
        setSummary(payload.summary);
        setPageError("");
      })
      .catch((error) => {
        if (error.name !== "AbortError") setPageError(error.message);
      })
      .finally(() => {
        if (!controller.signal.aborted) setLoading(false);
      });

    return () => controller.abort();
  }, [refreshKey]);

  const filteredSchedules = useMemo(() => {
    const query = scheduleSearch.trim().toLowerCase();
    return schedules.filter((schedule) => !query || schedule.schedule_name.toLowerCase().includes(query));
  }, [scheduleSearch, schedules]);

  const filteredPersonnel = useMemo(() => {
    const query = personnelSearch.trim().toLowerCase();
    return personnel.filter((person) => {
      const matchesQuery = !query
        || person.full_name.toLowerCase().includes(query)
        || person.employee_number.toLowerCase().includes(query)
        || person.department?.code.toLowerCase().includes(query);
      const matchesFilter = !assignmentFilter
        || (assignmentFilter === "assigned" && person.current_assignment)
        || (assignmentFilter === "unassigned" && !person.current_assignment);
      return matchesQuery && matchesFilter;
    });
  }, [assignmentFilter, personnel, personnelSearch]);

  const selectablePersonnel = useMemo(() => {
    const query = assignmentSearch.trim().toLowerCase();
    return personnel.filter((person) => !query
      || person.full_name.toLowerCase().includes(query)
      || person.employee_number.toLowerCase().includes(query)
      || person.department?.code.toLowerCase().includes(query));
  }, [assignmentSearch, personnel]);

  const cards = [
    { label: "Schedule templates", value: summary.total, icon: CalendarClock, tone: "blue" },
    { label: "Active schedules", value: summary.active, icon: ShieldCheck, tone: "green" },
    { label: "Personnel assigned", value: summary.assigned_personnel, icon: Users, tone: "purple" },
    { label: "Needs assignment", value: summary.unassigned_personnel, icon: AlertCircle, tone: "orange" },
  ];

  function showNotice(message) {
    setNotice(message);
    window.setTimeout(() => setNotice(""), 3500);
  }

  function openCreateSchedule() {
    setEditingSchedule(null);
    setScheduleForm(emptySchedule);
    setScheduleErrors({});
    setScheduleModal(true);
  }

  function openEditSchedule(schedule) {
    setEditingSchedule(schedule);
    setScheduleForm({
      ...schedule,
      schedule_name: schedule.schedule_name,
      morning_start: shortTime(schedule.morning_start),
      morning_end: shortTime(schedule.morning_end),
      afternoon_start: shortTime(schedule.afternoon_start),
      afternoon_end: shortTime(schedule.afternoon_end),
      morning_time_in_start: shortTime(schedule.morning_time_in_start),
      morning_time_in_end: shortTime(schedule.morning_time_in_end),
      morning_time_out_start: shortTime(schedule.morning_time_out_start),
      morning_time_out_end: shortTime(schedule.morning_time_out_end),
      afternoon_time_in_start: shortTime(schedule.afternoon_time_in_start),
      afternoon_time_in_end: shortTime(schedule.afternoon_time_in_end),
      afternoon_time_out_start: shortTime(schedule.afternoon_time_out_start),
      afternoon_time_out_end: shortTime(schedule.afternoon_time_out_end),
      grace_period_minutes: String(schedule.grace_period_minutes),
      required_minutes_per_day: String(schedule.required_minutes_per_day),
    });
    setScheduleErrors({});
    setScheduleModal(true);
  }

  function updateScheduleForm(event) {
    const { name, type, checked, value } = event.target;
    setScheduleForm((current) => ({ ...current, [name]: type === "checkbox" ? checked : value }));
    setScheduleErrors((current) => ({ ...current, [name]: undefined, working_days: undefined }));
  }

  async function saveSchedule(event) {
    event.preventDefault();
    setSavingSchedule(true);
    setScheduleErrors({});

    try {
      const payload = await apiFetch(
        editingSchedule ? `/schedules/${editingSchedule.schedule_id}` : "/schedules",
        {
          method: editingSchedule ? "PUT" : "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({
            ...scheduleForm,
            schedule_name: scheduleForm.schedule_name.trim(),
            grace_period_minutes: Number(scheduleForm.grace_period_minutes),
            required_minutes_per_day: Number(scheduleForm.required_minutes_per_day),
          }),
        },
      ).then(readResponse);
      setScheduleModal(false);
      setEditingSchedule(null);
      showNotice(payload.message);
      setRefreshKey((key) => key + 1);
    } catch (error) {
      setScheduleErrors(
        Object.keys(error.fields || {}).length ? error.fields : { general: [error.message] },
      );
    } finally {
      setSavingSchedule(false);
    }
  }

  async function deleteSchedule(schedule) {
    const confirmed = await confirm({
      title: "Remove work schedule?",
      message: `Remove the schedule “${schedule.schedule_name}”?`,
      note: "Assigned personnel must be moved to another schedule before removal.",
      confirmLabel: "Remove schedule",
    });
    if (!confirmed) return;
    setDeletingId(`schedule-${schedule.schedule_id}`);
    setPageError("");

    try {
      const payload = await apiFetch(`/schedules/${schedule.schedule_id}`, {
        method: "DELETE",
      }).then(readResponse);
      showNotice(payload.message);
      setRefreshKey((key) => key + 1);
    } catch (error) {
      setPageError(error.message);
    } finally {
      setDeletingId(null);
    }
  }

  function openAssignment(scheduleId = "", personnelIds = []) {
    setAssignmentForm({
      ...createEmptyAssignment(),
      schedule_id: scheduleId ? String(scheduleId) : "",
    });
    setSelectedPersonnel(personnelIds.map(Number));
    setAssignmentSearch("");
    setAssignmentErrors({});
    setAssignmentModal(true);
  }

  function togglePersonnel(personnelId) {
    setSelectedPersonnel((current) => current.includes(personnelId)
      ? current.filter((id) => id !== personnelId)
      : [...current, personnelId]);
    setAssignmentErrors((current) => ({ ...current, personnel_ids: undefined }));
  }

  function toggleVisiblePersonnel() {
    const visibleIds = selectablePersonnel.map((person) => person.personnel_id);
    const allSelected = visibleIds.length && visibleIds.every((id) => selectedPersonnel.includes(id));

    setSelectedPersonnel((current) => allSelected
      ? current.filter((id) => !visibleIds.includes(id))
      : [...new Set([...current, ...visibleIds])]);
  }

  async function saveAssignments(event) {
    event.preventDefault();
    setSavingAssignment(true);
    setAssignmentErrors({});

    try {
      const payload = await apiFetch("/schedules/assignments", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          schedule_id: Number(assignmentForm.schedule_id),
          personnel_ids: selectedPersonnel,
          effective_from: assignmentForm.effective_from,
          effective_to: assignmentForm.effective_to || null,
        }),
      }).then(readResponse);
      setAssignmentModal(false);
      showNotice(payload.message);
      setRefreshKey((key) => key + 1);
    } catch (error) {
      setAssignmentErrors(
        Object.keys(error.fields || {}).length ? error.fields : { general: [error.message] },
      );
    } finally {
      setSavingAssignment(false);
    }
  }

  async function removeAssignment(person) {
    const assignment = person.current_assignment;
    if (!assignment) return;
    const confirmed = await confirm({
      title: "Remove schedule assignment?",
      message: `Remove ${person.full_name}’s current schedule assignment?`,
      note: "Attendance rules will fall back to the applicable default schedule.",
      confirmLabel: "Remove assignment",
    });
    if (!confirmed) return;

    setDeletingId(`assignment-${assignment.personnel_schedule_id}`);
    setPageError("");

    try {
      const payload = await apiFetch(
        `/schedules/assignments/${assignment.personnel_schedule_id}`,
        { method: "DELETE" },
      ).then(readResponse);
      showNotice(payload.message);
      setRefreshKey((key) => key + 1);
    } catch (error) {
      setPageError(error.message);
    } finally {
      setDeletingId(null);
    }
  }

  return (
    <section className="schedules-page">
      <header className="schedules-hero">
        <div>
          <span><CalendarCheck2 size={14} /> Workforce scheduling</span>
          <h1>Work Schedules</h1>
          <p>Set official hours, attendance windows, regular duty days, and effective-dated personnel assignments.</p>
        </div>
        <div>
          <button type="button" className="schedule-secondary-button" onClick={() => openAssignment()}>
            <UserPlus size={17} />Assign personnel
          </button>
          <button type="button" className="schedule-primary-button" onClick={openCreateSchedule}>
            <Plus size={17} />New schedule
          </button>
        </div>
      </header>

      {notice && <div className="users-notice success"><Check size={18} />{notice}</div>}
      {pageError && <div className="users-notice error"><X size={18} />{pageError}</div>}

      <div className="schedule-summary-grid">
        {cards.map(({ label, value, icon: Icon, tone }) => (
          <article key={label}>
            <span className={tone}><Icon size={20} /></span>
            <div><strong>{loading ? "—" : value}</strong><small>{label}</small></div>
          </article>
        ))}
      </div>

      <div className="schedule-section-heading">
        <div><span>Reusable policies</span><h2>Schedule templates</h2></div>
        <label><Search size={15} /><input value={scheduleSearch} onChange={(event) => setScheduleSearch(event.target.value)} placeholder="Search schedules..." /></label>
      </div>

      <div className="schedule-template-grid">
        {loading ? (
          <div className="schedule-empty panel">Loading schedule templates…</div>
        ) : filteredSchedules.length ? filteredSchedules.map((schedule) => (
          <article className={`schedule-template-card ${schedule.status.toLowerCase()}`} key={schedule.schedule_id}>
            <div className="schedule-card-top">
              <span className={`status-badge ${schedule.status.toLowerCase()}`}>{schedule.status}</span>
              <div>
                <button type="button" onClick={() => openEditSchedule(schedule)} aria-label={`Edit ${schedule.schedule_name}`}><Edit3 size={15} /></button>
                <button type="button" className="danger" onClick={() => deleteSchedule(schedule)} disabled={deletingId === `schedule-${schedule.schedule_id}`} aria-label={`Delete ${schedule.schedule_name}`}><Trash2 size={15} /></button>
              </div>
            </div>
            <div className="schedule-card-title">
              <span><CalendarClock size={20} /></span>
              <div><h3>{schedule.schedule_name}</h3><small>{durationLabel(schedule.required_minutes_per_day)} required daily</small></div>
            </div>
            <div className="schedule-official-hours">
              <div><small>Morning</small><strong>{displayTime(schedule.morning_start)} – {displayTime(schedule.morning_end)}</strong></div>
              <span></span>
              <div><small>Afternoon</small><strong>{displayTime(schedule.afternoon_start)} – {displayTime(schedule.afternoon_end)}</strong></div>
            </div>
            <div className="schedule-day-row">
              {days.map(([key, label]) => <span className={schedule[key] ? "active" : ""} key={key}>{label}</span>)}
            </div>
            <div className="schedule-window-note">
              <Clock3 size={14} />
              <span>Late after <strong>{displayTime(schedule.morning_start)}</strong>{schedule.grace_period_minutes ? ` + ${schedule.grace_period_minutes} min grace` : ""}</span>
            </div>
            <footer>
              <div><Users size={15} /><strong>{schedule.active_personnel_count}</strong><span>currently assigned</span></div>
              <button type="button" disabled={schedule.status !== "Active"} onClick={() => openAssignment(schedule.schedule_id)}>
                Assign <UserPlus size={14} />
              </button>
            </footer>
          </article>
        )) : <div className="schedule-empty panel">No schedules match your search.</div>}
      </div>

      <div className="panel schedule-assignment-panel">
        <div className="schedule-assignment-header">
          <div><span>Effective today</span><h2>Personnel assignments</h2></div>
          <div>
            <label><Search size={15} /><input value={personnelSearch} onChange={(event) => setPersonnelSearch(event.target.value)} placeholder="Search personnel..." /></label>
            <select value={assignmentFilter} onChange={(event) => setAssignmentFilter(event.target.value)}>
              <option value="">All personnel</option>
              <option value="assigned">Assigned</option>
              <option value="unassigned">Unassigned</option>
            </select>
          </div>
        </div>

        <div className="users-table-wrap">
          <table className="users-table schedule-assignment-table">
            <thead><tr><th>Personnel</th><th>Department</th><th>Current schedule</th><th>Effective period</th><th>Upcoming</th><th><span className="sr-only">Actions</span></th></tr></thead>
            <tbody>
              {loading ? (
                <tr><td colSpan="6" className="users-empty">Loading assignments…</td></tr>
              ) : filteredPersonnel.length ? filteredPersonnel.map((person) => (
                <tr key={person.personnel_id}>
                  <td><strong>{person.full_name}</strong><small>{person.employee_number} · {person.personnel_type}</small></td>
                  <td><span className="schedule-department-pill">{person.department?.code || "Not assigned"}</span></td>
                  <td>
                    {person.current_assignment
                      ? <strong className="assigned-schedule-name">{person.current_assignment.schedule_name}</strong>
                      : <span className="unassigned-label">Needs assignment</span>}
                  </td>
                  <td>
                    {person.current_assignment ? (
                      <span className="schedule-effective-date">
                        {person.current_assignment.effective_from} → {person.current_assignment.effective_to || "Ongoing"}
                      </span>
                    ) : "—"}
                  </td>
                  <td>
                    {person.upcoming_assignment
                      ? <span className="upcoming-schedule">{person.upcoming_assignment.schedule_name}<small>Starts {person.upcoming_assignment.effective_from}</small></span>
                      : "—"}
                  </td>
                  <td>
                    <div className="schedule-row-actions">
                      <button type="button" onClick={() => openAssignment(person.current_assignment?.schedule_id || "", [person.personnel_id])}>
                        {person.current_assignment ? <Edit3 size={14} /> : <UserPlus size={14} />}
                      </button>
                      {person.current_assignment && (
                        <button type="button" className="danger" onClick={() => removeAssignment(person)} disabled={deletingId === `assignment-${person.current_assignment.personnel_schedule_id}`}>
                          <Trash2 size={14} />
                        </button>
                      )}
                    </div>
                  </td>
                </tr>
              )) : <tr><td colSpan="6" className="users-empty">No personnel match the selected filters.</td></tr>}
            </tbody>
          </table>
        </div>
      </div>

      {scheduleModal && (
        <div className="modal-backdrop schedule-modal-backdrop" role="presentation" onMouseDown={(event) => {
          if (event.target === event.currentTarget && !savingSchedule) setScheduleModal(false);
        }}>
          <div className="user-modal schedule-modal" role="dialog" aria-modal="true" aria-labelledby="schedule-modal-title">
            <div className="user-modal-header">
              <div>
                <span className="modal-icon"><CalendarClock size={21} /></span>
                <div><h2 id="schedule-modal-title">{editingSchedule ? "Edit work schedule" : "Create work schedule"}</h2><p>Configure official hours and safe attendance windows.</p></div>
              </div>
              <button type="button" onClick={() => setScheduleModal(false)} disabled={savingSchedule} aria-label="Close"><X size={20} /></button>
            </div>

            <form className="schedule-form" onSubmit={saveSchedule}>
              {scheduleErrors.general && <div className="form-error-banner">{scheduleErrors.general[0]}</div>}

              <div className="schedule-form-section">
                <div className="schedule-form-section-title"><BriefcaseBusiness size={17} /><div><strong>Schedule identity</strong><small>Name and availability</small></div></div>
                <div className="schedule-form-grid">
                  <div className="form-field form-field-wide">
                    <label htmlFor="schedule_name">Schedule name</label>
                    <input id="schedule_name" name="schedule_name" value={scheduleForm.schedule_name} onChange={updateScheduleForm} required />
                    <FieldError errors={scheduleErrors} name="schedule_name" />
                  </div>
                  <div className="form-field">
                    <label htmlFor="schedule_status">Status</label>
                    <select id="schedule_status" name="status" value={scheduleForm.status} onChange={updateScheduleForm}><option>Active</option><option>Inactive</option></select>
                    <FieldError errors={scheduleErrors} name="status" />
                  </div>
                </div>
              </div>

              <div className="schedule-form-section">
                <div className="schedule-form-section-title"><CalendarCheck2 size={17} /><div><strong>Regular working days</strong><small>Special Friday–Sunday duties remain controlled by the calendar</small></div></div>
                <div className="schedule-day-picker">
                  {days.map(([key, label]) => (
                    <label className={scheduleForm[key] ? "selected" : ""} key={key}>
                      <input type="checkbox" name={key} checked={scheduleForm[key]} onChange={updateScheduleForm} />
                      <span>{scheduleForm[key] && <Check size={13} />}{label}</span>
                    </label>
                  ))}
                </div>
                <FieldError errors={scheduleErrors} name="working_days" />
              </div>

              <div className="schedule-form-section">
                <div className="schedule-form-section-title"><Clock3 size={17} /><div><strong>Official working hours</strong><small>Used for late, undertime, and required-hours calculations</small></div></div>
                <div className="schedule-time-grid">
                  <TimeField id="morning_start" label="Morning starts" form={scheduleForm} errors={scheduleErrors} onChange={updateScheduleForm} />
                  <TimeField id="morning_end" label="Morning ends" form={scheduleForm} errors={scheduleErrors} onChange={updateScheduleForm} />
                  <TimeField id="afternoon_start" label="Afternoon starts" form={scheduleForm} errors={scheduleErrors} onChange={updateScheduleForm} />
                  <TimeField id="afternoon_end" label="Afternoon ends" form={scheduleForm} errors={scheduleErrors} onChange={updateScheduleForm} />
                </div>
              </div>

              <div className="schedule-form-section">
                <div className="schedule-form-section-title"><TimerReset size={17} /><div><strong>Attendance windows</strong><small>Times when kiosk and manual attendance actions are accepted</small></div></div>
                <div className="attendance-window-grid">
                  <WindowFields title="Morning time-in" prefix="morning_time_in" form={scheduleForm} errors={scheduleErrors} onChange={updateScheduleForm} />
                  <WindowFields title="Morning time-out" prefix="morning_time_out" form={scheduleForm} errors={scheduleErrors} onChange={updateScheduleForm} />
                  <WindowFields title="Afternoon time-in" prefix="afternoon_time_in" form={scheduleForm} errors={scheduleErrors} onChange={updateScheduleForm} />
                  <WindowFields title="Afternoon time-out" prefix="afternoon_time_out" form={scheduleForm} errors={scheduleErrors} onChange={updateScheduleForm} />
                </div>
              </div>

              <div className="schedule-form-section">
                <div className="schedule-form-section-title"><ShieldCheck size={17} /><div><strong>Attendance policy</strong><small>Daily target and allowed grace period</small></div></div>
                <div className="schedule-form-grid">
                  <div className="form-field">
                    <label htmlFor="required_minutes_per_day">Required minutes per day</label>
                    <input id="required_minutes_per_day" name="required_minutes_per_day" type="number" min="60" max="1440" value={scheduleForm.required_minutes_per_day} onChange={updateScheduleForm} required />
                    <small className="field-hint">{durationLabel(Number(scheduleForm.required_minutes_per_day || 0))}</small>
                    <FieldError errors={scheduleErrors} name="required_minutes_per_day" />
                  </div>
                  <div className="form-field">
                    <label htmlFor="grace_period_minutes">Grace period in minutes</label>
                    <input id="grace_period_minutes" name="grace_period_minutes" type="number" min="0" max="180" value={scheduleForm.grace_period_minutes} onChange={updateScheduleForm} required />
                    <small className="field-hint">Late after {displayTime(scheduleForm.morning_start)}{Number(scheduleForm.grace_period_minutes) ? ` + ${scheduleForm.grace_period_minutes} minutes` : ""}</small>
                    <FieldError errors={scheduleErrors} name="grace_period_minutes" />
                  </div>
                </div>
              </div>

              <div className="user-modal-actions">
                <button type="button" className="secondary-action" onClick={() => setScheduleModal(false)} disabled={savingSchedule}>Cancel</button>
                <button type="submit" className="primary-action" disabled={savingSchedule}>{savingSchedule ? "Saving…" : editingSchedule ? "Save schedule" : "Create schedule"}</button>
              </div>
            </form>
          </div>
        </div>
      )}

      {assignmentModal && (
        <div className="modal-backdrop schedule-modal-backdrop" role="presentation" onMouseDown={(event) => {
          if (event.target === event.currentTarget && !savingAssignment) setAssignmentModal(false);
        }}>
          <div className="user-modal assignment-modal" role="dialog" aria-modal="true" aria-labelledby="assignment-modal-title">
            <div className="user-modal-header">
              <div>
                <span className="modal-icon"><UserPlus size={21} /></span>
                <div><h2 id="assignment-modal-title">Assign personnel schedule</h2><p>The previous assignment will end before the new effective date.</p></div>
              </div>
              <button type="button" onClick={() => setAssignmentModal(false)} disabled={savingAssignment} aria-label="Close"><X size={20} /></button>
            </div>

            <form className="assignment-form" onSubmit={saveAssignments}>
              {assignmentErrors.general && <div className="form-error-banner">{assignmentErrors.general[0]}</div>}
              <div className="schedule-form-grid">
                <div className="form-field form-field-wide">
                  <label htmlFor="assignment_schedule">Active schedule</label>
                  <select id="assignment_schedule" value={assignmentForm.schedule_id} onChange={(event) => setAssignmentForm((current) => ({ ...current, schedule_id: event.target.value }))} required>
                    <option value="">Select a schedule</option>
                    {schedules.filter((schedule) => schedule.status === "Active").map((schedule) => <option value={schedule.schedule_id} key={schedule.schedule_id}>{schedule.schedule_name}</option>)}
                  </select>
                  <FieldError errors={assignmentErrors} name="schedule_id" />
                </div>
                <div className="form-field">
                  <label htmlFor="effective_from">Effective from</label>
                  <input id="effective_from" type="date" value={assignmentForm.effective_from} onChange={(event) => setAssignmentForm((current) => ({ ...current, effective_from: event.target.value }))} required />
                  <FieldError errors={assignmentErrors} name="effective_from" />
                </div>
                <div className="form-field">
                  <label htmlFor="effective_to">Effective until <span>Optional</span></label>
                  <input id="effective_to" type="date" min={assignmentForm.effective_from} value={assignmentForm.effective_to} onChange={(event) => setAssignmentForm((current) => ({ ...current, effective_to: event.target.value }))} />
                  <FieldError errors={assignmentErrors} name="effective_to" />
                </div>
              </div>

              <div className="assignment-picker-heading">
                <div><strong>Select personnel</strong><small>{selectedPersonnel.length} selected</small></div>
                <label><Search size={15} /><input value={assignmentSearch} onChange={(event) => setAssignmentSearch(event.target.value)} placeholder="Search name, ID, office..." /></label>
              </div>
              <button type="button" className="assignment-select-all" onClick={toggleVisiblePersonnel}>
                <Check size={14} />Select or clear all visible personnel
              </button>
              <FieldError errors={assignmentErrors} name="personnel_ids" />
              <FieldError errors={assignmentErrors} name="personnel_ids.0" />

              <div className="assignment-personnel-list">
                {selectablePersonnel.map((person) => {
                  const checked = selectedPersonnel.includes(person.personnel_id);
                  return (
                    <label className={checked ? "selected" : ""} key={person.personnel_id}>
                      <input type="checkbox" checked={checked} onChange={() => togglePersonnel(person.personnel_id)} />
                      <span className="assignment-check">{checked && <Check size={13} />}</span>
                      <span><strong>{person.full_name}</strong><small>{person.employee_number} · {person.department?.code || "No department"}</small></span>
                      <em>{person.current_assignment?.schedule_name || "Unassigned"}</em>
                    </label>
                  );
                })}
              </div>

              <div className="user-modal-actions">
                <button type="button" className="secondary-action" onClick={() => setAssignmentModal(false)} disabled={savingAssignment}>Cancel</button>
                <button type="submit" className="primary-action" disabled={savingAssignment}>{savingAssignment ? "Assigning…" : `Assign ${selectedPersonnel.length || ""} personnel`}</button>
              </div>
            </form>
          </div>
        </div>
      )}
      {confirmationDialog}
    </section>
  );
}

function TimeField({ id, label, form, errors, onChange }) {
  return (
    <div className="form-field">
      <label htmlFor={id}>{label}</label>
      <input id={id} name={id} type="time" value={form[id]} onChange={onChange} required />
      <FieldError errors={errors} name={id} />
    </div>
  );
}

function WindowFields({ title, prefix, form, errors, onChange }) {
  return (
    <div className="attendance-window-card">
      <strong>{title}</strong>
      <div>
        <TimeField id={`${prefix}_start`} label="Opens" form={form} errors={errors} onChange={onChange} />
        <TimeField id={`${prefix}_end`} label="Closes" form={form} errors={errors} onChange={onChange} />
      </div>
    </div>
  );
}

import { Navigate, Route, Routes } from "react-router-dom";
import MainLayout from "../components/layout/MainLayout";
import Dashboard from "../pages/Dashboard";
import Attendance from "../pages/Attendance";
import Personnel from "../pages/Personnel";
import Login from "../pages/Login";
import SystemUsers from "../pages/SystemUsers";
import RequireAuth from "../components/auth/RequireAuth";
import HolidayCalendar from "../pages/HolidayCalendar";
import DtrMonitoring from "../pages/DtrMonitoring";
import QrAttendance from "../pages/QrAttendance";
import Departments from "../pages/Departments";
import ActivityLogs from "../pages/ActivityLogs";
import ErrorPage from "../pages/ErrorPage";
import Schedules from "../pages/Schedules";
import LeaveRequests from "../pages/LeaveRequests";

function PlaceholderPage({ title, description }) {
  return (
    <section className="dashboard-page">
      <div className="page-title">
        <h1>{title}</h1>
        <p>{description}</p>
      </div>

      <div className="panel">
        <p>{title} content will be developed next.</p>
      </div>
    </section>
  );
}

export default function AppRoutes() {
  return (
    <Routes>
      <Route path="/login" element={<Login />} />

      <Route
        path="/"
        element={
          <RequireAuth>
            <MainLayout />
          </RequireAuth>
        }
      >
        <Route index element={<Navigate to="/dashboard" replace />} />

        <Route path="dashboard" element={<Dashboard />} />
        <Route path="attendance" element={<Attendance />} />
        <Route path="leave-requests" element={<LeaveRequests />} />
        <Route path="personnel" element={<Personnel />} />
        <Route path="departments" element={<Departments />} />
        <Route path="calendar" element={<HolidayCalendar />} />

        <Route path="schedules" element={<Schedules />} />

        <Route path="dtr" element={<DtrMonitoring />} />

        <Route path="qr-attendance" element={<QrAttendance />} />

        <Route path="activity-logs" element={<ActivityLogs />} />

        <Route path="system-users" element={<SystemUsers />} />
        <Route path="authentication" element={<Navigate to="/system-users" replace />} />

        <Route
          path="settings"
          element={
            <PlaceholderPage
              title="Settings"
              description="Configure system preferences."
            />
          }
        />

        <Route path="*" element={<ErrorPage code={404} embedded />} />
      </Route>
    </Routes>
  );
}

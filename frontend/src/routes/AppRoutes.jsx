import { Navigate, Route, Routes } from "react-router-dom";
import MainLayout from "../components/layout/MainLayout";
import Dashboard from "../pages/Dashboard";
import Attendance from "../pages/Attendance";
import Personnel from "../pages/Personnel";
import Login from "../pages/Login";
import SystemUsers from "../pages/SystemUsers";
import RequireAuth from "../components/auth/RequireAuth";

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
        <Route index element={<Navigate to="/login" replace />} />

        <Route path="dashboard" element={<Dashboard />} />
        <Route path="attendance" element={<Attendance />} />
        <Route path="personnel" element={<Personnel />} />

        <Route
          path="schedules"
          element={
            <PlaceholderPage
              title="Schedules"
              description="Manage Monday to Thursday work schedules and working hours."
            />
          }
        />

        <Route
          path="dtr"
          element={
            <PlaceholderPage
              title="DTR Reports"
              description="Generate and print Daily Time Records."
            />
          }
        />

        <Route
          path="qr-attendance"
          element={
            <PlaceholderPage
              title="QR Attendance"
              description="Generate and manage QR-based attendance."
            />
          }
        />

        <Route
          path="activity-logs"
          element={
            <PlaceholderPage
              title="Activity Logs"
              description="View system activities and attendance changes."
            />
          }
        />

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

        <Route path="*" element={<Navigate to="/dashboard" replace />} />
      </Route>
    </Routes>
  );
}

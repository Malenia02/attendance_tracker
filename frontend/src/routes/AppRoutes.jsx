import { lazy, Suspense } from "react";
import { Navigate, Route, Routes } from "react-router";
import MainLayout from "../components/layout/MainLayout";
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
import About from "../pages/About";
import Landing from "../pages/Landing";
import { RoutePageSkeleton } from "../components/common/Skeletons";

const ActionCenter = lazy(() => import("../pages/ActionCenter"));
const Dashboard = lazy(() => import("../pages/Dashboard"));
const Notifications = lazy(() => import("../pages/Notifications"));
const PersonnelOnboarding = lazy(() => import("../pages/PersonnelOnboarding"));
const SystemHealth = lazy(() => import("../pages/SystemHealth"));
const IssueReports = lazy(() => import("../pages/IssueReports"));

export default function AppRoutes() {
  return (
    <Routes>
      <Route path="/" element={<Landing />} />
      <Route path="/login" element={<Login />} />

      <Route
        element={
          <RequireAuth>
            <MainLayout />
          </RequireAuth>
        }
      >
        <Route
          path="dashboard"
          element={
            <Suspense fallback={<RoutePageSkeleton title="Dashboard" description="Loading your secured workspace…" />}>
              <Dashboard />
            </Suspense>
          }
        />
        <Route
          path="action-center"
          element={
            <Suspense fallback={<RoutePageSkeleton title="Action Center" description="Loading secure action queues…" />}>
              <ActionCenter />
            </Suspense>
          }
        />
        <Route
          path="notifications"
          element={
            <Suspense fallback={<RoutePageSkeleton title="Notifications" description="Loading your secured notifications…" />}>
              <Notifications />
            </Suspense>
          }
        />
        <Route path="attendance" element={<Attendance />} />
        <Route path="about" element={<About />} />
        <Route
          path="issue-reports"
          element={
            <Suspense fallback={<RoutePageSkeleton title="System Support" description="Loading issue reports…" />}>
              <IssueReports />
            </Suspense>
          }
        />
        <Route path="leave-requests" element={<LeaveRequests />} />
        <Route path="personnel" element={<Personnel />} />
        <Route
          path="personnel-onboarding"
          element={
            <Suspense fallback={<RoutePageSkeleton title="Onboarding & Readiness" description="Checking workforce readiness…" />}>
              <PersonnelOnboarding />
            </Suspense>
          }
        />
        <Route path="departments" element={<Departments />} />
        <Route path="calendar" element={<HolidayCalendar />} />

        <Route path="schedules" element={<Schedules />} />

        <Route path="dtr" element={<DtrMonitoring />} />

        <Route path="qr-attendance" element={<QrAttendance />} />

        <Route path="activity-logs" element={<ActivityLogs />} />

        <Route path="system-users" element={<SystemUsers />} />
        <Route path="authentication" element={<Navigate to="/system-users" replace />} />

        <Route
          path="system-health"
          element={
            <Suspense fallback={<RoutePageSkeleton title="System Health" description="Checking operational safeguards…" />}>
              <SystemHealth />
            </Suspense>
          }
        />
        <Route path="settings" element={<Navigate to="/system-health" replace />} />

        <Route path="*" element={<ErrorPage code={404} embedded />} />
      </Route>
    </Routes>
  );
}

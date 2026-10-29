const SAFE_RETURN_ROUTES = [
  "/dashboard",
  "/action-center",
  "/notifications",
  "/attendance",
  "/about",
  "/leave-requests",
  "/personnel",
  "/personnel-onboarding",
  "/departments",
  "/calendar",
  "/schedules",
  "/dtr",
  "/qr-attendance",
  "/activity-logs",
  "/system-users",
  "/system-health",
  "/issue-reports",
];

export function safeReturnPath(value) {
  if (!value || value.startsWith("//")) return "/dashboard";

  return SAFE_RETURN_ROUTES.some((route) => value === route || value.startsWith(`${route}?`))
    ? value
    : "/dashboard";
}

import http from "k6/http";
import { check, sleep } from "k6";

export const options = {
  scenarios: {
    authenticated_browsing: {
      executor: "ramping-vus",
      stages: [
        { duration: "30s", target: 5 },
        { duration: "1m", target: 10 },
        { duration: "30s", target: 0 },
      ],
      gracefulRampDown: "10s",
    },
  },
  thresholds: {
    http_req_failed: ["rate<0.02"],
    http_req_duration: ["p(95)<2500"],
  },
};

const baseUrl = (__ENV.BASE_URL || "").replace(/\/$/, "");
const username = __ENV.TEST_USERNAME || "";
const password = __ENV.TEST_PASSWORD || "";

function xsrfToken(response) {
  const token = response.cookies["XSRF-TOKEN"]?.[0]?.value || "";
  return decodeURIComponent(token);
}

export function setup() {
  if (!baseUrl || !username || !password) {
    throw new Error("Set BASE_URL, TEST_USERNAME, and TEST_PASSWORD before running this test.");
  }

  const jar = http.cookieJar();
  const csrf = http.get(`${baseUrl}/sanctum/csrf-cookie`, {
    headers: { Accept: "application/json", Origin: baseUrl, Referer: `${baseUrl}/login` },
    jar,
  });
  const login = http.post(
    `${baseUrl}/api/auth/login`,
    JSON.stringify({ username, password }),
    {
      headers: {
        Accept: "application/json",
        "Content-Type": "application/json",
        "X-XSRF-TOKEN": xsrfToken(csrf),
        Origin: baseUrl,
        Referer: `${baseUrl}/login`,
      },
      jar,
    },
  );

  if (!check(login, { "test login succeeds": (response) => response.status === 200 })) {
    throw new Error(`Login failed with HTTP ${login.status}.`);
  }

  return { cookies: jar.cookiesForURL(baseUrl) };
}

export default function (data) {
  const cookieHeader = Object.entries(data.cookies)
    .map(([name, values]) => `${name}=${values[0]}`)
    .join("; ");
  const params = {
    headers: {
      Accept: "application/json",
      Cookie: cookieHeader,
      Origin: baseUrl,
      Referer: `${baseUrl}/dashboard`,
    },
  };
  const month = new Date().toISOString().slice(0, 7);
  const date = new Date().toISOString().slice(0, 10);
  const responses = http.batch([
    ["GET", `${baseUrl}/api/dashboard`, null, params],
    ["GET", `${baseUrl}/api/personnel?page=1&per_page=25`, null, params],
    ["GET", `${baseUrl}/api/attendance?date=${date}&page=1&per_page=25`, null, params],
    ["GET", `${baseUrl}/api/dtr?month=${month}&page=1&per_page=25`, null, params],
  ]);

  check(responses, {
    "authenticated pages respond": (batch) => batch.every((response) => response.status === 200),
    "responses stay bounded": (batch) => batch.every((response) => response.body.length < 2_000_000),
  });
  sleep(1);
}

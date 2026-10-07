/* global process, Buffer */
import crypto from "node:crypto";
import net from "node:net";

const BACKEND_ORIGIN = (
  process.env.BACKEND_API_URL
  || "https://malenia02-attendance-api-test.onrender.com"
).replace(/\/$/, "");

const REQUEST_HEADER_BLOCKLIST = new Set([
  "connection",
  "content-length",
  "host",
  "transfer-encoding",
  "x-dilg-client-ip",
  "x-dilg-proxy-signature",
  "x-dilg-proxy-timestamp",
  "x-forwarded-for",
  "x-real-ip",
  "x-vercel-forwarded-for",
]);

const RESPONSE_HEADER_BLOCKLIST = new Set([
  "connection",
  "content-encoding",
  "content-length",
  "set-cookie",
  "transfer-encoding",
]);

export const config = {
  api: { bodyParser: false },
};

function sendSafeError(response, status, requestId = "") {
  response.setHeader("Content-Type", "application/json; charset=utf-8");
  if (requestId) response.setHeader("X-Request-ID", requestId);

  const message = status >= 500
    ? "We could not complete your request. Please try again. If it continues, contact your administrator with the request ID."
    : status === 404
      ? "We could not find the requested item. Refresh the page and try again."
      : "We could not complete this request. Refresh the page and try again. If it continues, contact your administrator with the request ID.";

  return response.status(status).json({
    success: false,
    message,
    error: { code: status >= 500 ? "SERVICE_UNAVAILABLE" : "REQUEST_FAILED", message },
    request_id: requestId || undefined,
  });
}

export default async function handler(request, response) {
  try {
    return await forwardRequest(request, response);
  } catch (error) {
    const requestId = crypto.randomUUID();
    console.error("Attendance API proxy failed", { requestId, error });
    return sendSafeError(response, 502, requestId);
  }
}

async function forwardRequest(request, response) {
  const secret = (process.env.FRONTEND_PROXY_SIGNING_SECRET || "").trim();
  const forwarded = String(request.headers["x-vercel-forwarded-for"] || "")
    .split(",")[0]
    .trim();

  if (secret.length < 32) {
    const requestId = crypto.randomUUID();
    console.error("Attendance API proxy configuration is missing", { requestId });
    return sendSafeError(response, 503, requestId);
  }

  if (!net.isIP(forwarded)) {
    const requestId = crypto.randomUUID();
    console.error("Attendance API proxy client address is invalid", { requestId });
    return sendSafeError(response, 403, requestId);
  }

  const incomingUrl = new URL(request.url, "https://frontend.invalid");
  const proxiedPath = String(incomingUrl.searchParams.get("__dilg_path") || "")
    .replace(/^\/+|\/+$/g, "");

  if (!proxiedPath || proxiedPath.split("/").some((part) => part === "..")) {
    return sendSafeError(response, 404);
  }

  incomingUrl.searchParams.delete("__dilg_path");

  const upstreamPath = `/api/${proxiedPath}`;
  const upstreamTarget = `${upstreamPath}${incomingUrl.search}`;
  const timestamp = Math.floor(Date.now() / 1000).toString();
  const method = String(request.method || "GET").toUpperCase();
  const signaturePayload = [timestamp, method, upstreamPath, forwarded].join("\n");
  const signature = crypto
    .createHmac("sha256", secret)
    .update(signaturePayload)
    .digest("hex");
  const headers = new Headers();

  for (const [name, value] of Object.entries(request.headers)) {
    if (REQUEST_HEADER_BLOCKLIST.has(name.toLowerCase()) || value == null) continue;
    headers.set(name, Array.isArray(value) ? value.join(", ") : String(value));
  }

  headers.set("X-DILG-Client-IP", forwarded);
  headers.set("X-DILG-Proxy-Path", upstreamPath);
  headers.set("X-DILG-Proxy-Timestamp", timestamp);
  headers.set("X-DILG-Proxy-Signature", signature);

  let body;
  if (!["GET", "HEAD"].includes(method)) {
    const chunks = [];
    for await (const chunk of request) chunks.push(Buffer.from(chunk));
    body = Buffer.concat(chunks);
  }

  try {
    const upstream = await fetch(`${BACKEND_ORIGIN}${upstreamTarget}`, {
      method,
      headers,
      body,
      redirect: "manual",
    });

    const requestId = upstream.headers.get("x-request-id") || "";
    const contentType = upstream.headers.get("content-type") || "";
    if (upstream.status >= 500 || (upstream.status >= 400 && !contentType.includes("application/json"))) {
      console.error("Attendance API request failed", { method, path: upstreamPath, status: upstream.status, requestId });
      return sendSafeError(response, upstream.status, requestId);
    }

    for (const [name, value] of upstream.headers.entries()) {
      if (!RESPONSE_HEADER_BLOCKLIST.has(name.toLowerCase())) {
        response.setHeader(name, value);
      }
    }

    const cookies = upstream.headers.getSetCookie?.() || [];
    if (cookies.length) response.setHeader("Set-Cookie", cookies);

    const responseBody = Buffer.from(await upstream.arrayBuffer());
    if (upstream.status >= 400 && (responseBody.includes(Buffer.from('"exception"')) || responseBody.includes(Buffer.from('"trace"')))) {
      console.error("Attendance API returned developer details", { method, path: upstreamPath, status: upstream.status, requestId });
      return sendSafeError(response, upstream.status, requestId);
    }
    return response.status(upstream.status).send(responseBody);
  } catch (error) {
    const requestId = crypto.randomUUID();
    console.error("Attendance API proxy failed", { method, path: upstreamPath, requestId, error });
    return sendSafeError(response, 502, requestId);
  }
}

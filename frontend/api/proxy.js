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

export default async function handler(request, response) {
  const secret = (process.env.FRONTEND_PROXY_SIGNING_SECRET || "").trim();
  const forwarded = String(request.headers["x-vercel-forwarded-for"] || "")
    .split(",")[0]
    .trim();

  if (secret.length < 32) {
    return response.status(503).json({
      success: false,
      message: "The secure API proxy is not configured.",
    });
  }

  if (!net.isIP(forwarded)) {
    return response.status(403).json({
      success: false,
      message: "Vercel could not verify the original client address.",
    });
  }

  const incomingUrl = new URL(request.url, "https://frontend.invalid");
  const proxiedPath = String(incomingUrl.searchParams.get("__dilg_path") || "")
    .replace(/^\/+|\/+$/g, "");

  if (!proxiedPath || proxiedPath.split("/").some((part) => part === "..")) {
    return response.status(404).json({
      success: false,
      message: "The requested API route was not found.",
    });
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

    for (const [name, value] of upstream.headers.entries()) {
      if (!RESPONSE_HEADER_BLOCKLIST.has(name.toLowerCase())) {
        response.setHeader(name, value);
      }
    }

    const cookies = upstream.headers.getSetCookie?.() || [];
    if (cookies.length) response.setHeader("Set-Cookie", cookies);

    const responseBody = Buffer.from(await upstream.arrayBuffer());
    return response.status(upstream.status).send(responseBody);
  } catch {
    return response.status(502).json({
      success: false,
      message: "The attendance API is temporarily unavailable.",
    });
  }
}

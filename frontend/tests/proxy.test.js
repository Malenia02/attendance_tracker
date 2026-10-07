import assert from "node:assert/strict";
import process from "node:process";
import { Readable } from "node:stream";
import test from "node:test";
import handler from "../api/proxy.js";

function request() {
  const incoming = Readable.from([]);
  incoming.method = "GET";
  incoming.url = "/api/proxy?__dilg_path=personnel";
  incoming.headers = { "x-vercel-forwarded-for": "203.0.113.10" };
  return incoming;
}

function response() {
  return {
    headers: {},
    statusCode: 200,
    setHeader(name, value) {
      this.headers[name.toLowerCase()] = value;
    },
    status(code) {
      this.statusCode = code;
      return this;
    },
    json(body) {
      this.body = body;
      return this;
    },
    send(body) {
      this.body = body;
      return this;
    },
  };
}

async function withUpstream(upstream, callback) {
  const originalFetch = globalThis.fetch;
  const originalSecret = process.env.FRONTEND_PROXY_SIGNING_SECRET;
  process.env.FRONTEND_PROXY_SIGNING_SECRET = "proxy-test-signing-secret-at-least-32-characters";
  globalThis.fetch = upstream;

  try {
    return await callback();
  } finally {
    globalThis.fetch = originalFetch;
    if (originalSecret === undefined) delete process.env.FRONTEND_PROXY_SIGNING_SECRET;
    else process.env.FRONTEND_PROXY_SIGNING_SECRET = originalSecret;
  }
}

test("upstream server errors never forward raw error pages", async () => {
  await withUpstream(async () => new Response("<pre>Private SQL stack trace</pre>", {
    status: 500,
    headers: { "Content-Type": "text/html", "X-Request-ID": "upstream-error-test" },
  }), async () => {
    const outgoing = response();
    await handler(request(), outgoing);

    assert.equal(outgoing.statusCode, 500);
    assert.equal(outgoing.body.request_id, "upstream-error-test");
    assert.match(outgoing.body.message, /Please try again/);
    assert.doesNotMatch(JSON.stringify(outgoing.body), /Private SQL stack trace/);
  });
});

test("validation errors retain useful field guidance", async () => {
  await withUpstream(async () => Response.json({
    message: "Some fields are invalid.",
    errors: { email: ["Enter a valid email address."] },
  }, { status: 422 }), async () => {
    const outgoing = response();
    await handler(request(), outgoing);

    assert.equal(outgoing.statusCode, 422);
    assert.equal(JSON.parse(outgoing.body.toString()).errors.email[0], "Enter a valid email address.");
  });
});

test("proxy connection errors provide a safe reference", async () => {
  await withUpstream(async () => { throw new Error("Private upstream address"); }, async () => {
    const outgoing = response();
    await handler(request(), outgoing);

    assert.equal(outgoing.statusCode, 502);
    assert.match(outgoing.body.request_id, /^[0-9a-f-]{36}$/);
    assert.doesNotMatch(JSON.stringify(outgoing.body), /Private upstream address/);
  });
});

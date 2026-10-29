import assert from "node:assert/strict";
import test from "node:test";
import { safeReturnPath } from "../src/lib/navigation.js";

test("preserves approved internal destinations after login", () => {
  assert.equal(safeReturnPath("/dtr"), "/dtr");
  assert.equal(safeReturnPath("/issue-reports"), "/issue-reports");
  assert.equal(
    safeReturnPath("/action-center?queue=correction_requests"),
    "/action-center?queue=correction_requests",
  );
});

test("rejects external, malformed, and public return destinations", () => {
  assert.equal(safeReturnPath("https://example.com"), "/dashboard");
  assert.equal(safeReturnPath("//example.com"), "/dashboard");
  assert.equal(safeReturnPath("/dashboard-malicious"), "/dashboard");
  assert.equal(safeReturnPath("/login"), "/dashboard");
  assert.equal(safeReturnPath(null), "/dashboard");
});

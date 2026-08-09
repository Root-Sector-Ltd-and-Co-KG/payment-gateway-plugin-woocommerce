import assert from "node:assert/strict";
import { existsSync } from "node:fs";
import path from "node:path";
import test from "node:test";
import { fileURLToPath, pathToFileURL } from "node:url";

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const policyPath = path.join(root, "scripts/validate-release-policy.mjs");

test("release policy implementation is repository-local", () => {
  assert.ok(existsSync(policyPath), "scripts/validate-release-policy.mjs must exist");
});

test("release policy CLI maps documented kebab-case arguments", async () => {
  const { parseCliArgs } = await import(pathToFileURL(policyPath));
  assert.deepEqual(
    parseCliArgs([
      "--source-sha",
      "a".repeat(40),
      "--policy-revision",
      "b".repeat(40),
      "--changelog-heading",
      "1.2.0",
    ]),
    {
      sourceSha: "a".repeat(40),
      policyRevision: "b".repeat(40),
      changelogHeading: "1.2.0",
    },
  );
});

test("release policy binds exact source, SemVer impact, changelog, and policy identity", async () => {
  const { validateReleasePolicy } = await import(pathToFileURL(policyPath));
  const sourceRevision = "a".repeat(40);
  const policyRevision = "d".repeat(40);
  const evidence = validateReleasePolicy({
    sourceRevision,
    checkoutRevision: sourceRevision,
    previousTag: "1.1.1",
    requestedVersion: "1.2.0",
    commits: [
      { sha: "b".repeat(40), message: "fix(ipn): preserve claim state" },
      { sha: "c".repeat(40), message: "feat(ipn): add durable v2 receiver" },
    ],
    changelog: "= 1.2.0 =\n\n- Add IPN v2 support.\n",
    changelogHeading: "= 1.2.0 =",
    policyId: "woocommerce-plugin-release-v1",
    policyRevision,
  });

  assert.deepEqual(evidence, {
    schemaVersion: 1,
    validated: true,
    sourceRevision,
    version: "1.2.0",
    previousTag: "1.1.1",
    requiredBump: "minor",
    policy: {
      id: "woocommerce-plugin-release-v1",
      revision: policyRevision,
    },
  });
});

test("release policy rejects source retargeting, under-versioning, and malformed commits", async () => {
  const { validateReleasePolicy } = await import(pathToFileURL(policyPath));
  const base = {
    sourceRevision: "a".repeat(40),
    checkoutRevision: "a".repeat(40),
    previousTag: "1.1.1",
    requestedVersion: "1.2.0",
    commits: [{ sha: "b".repeat(40), message: "feat(ipn): add v2" }],
    changelog: "= 1.2.0 =\n",
    changelogHeading: "= 1.2.0 =",
    policyId: "woocommerce-plugin-release-v1",
    policyRevision: "d".repeat(40),
  };

  assert.throws(
    () => validateReleasePolicy({ ...base, checkoutRevision: "f".repeat(40) }),
    /does not match checked-out revision/,
  );
  assert.throws(
    () => validateReleasePolicy({ ...base, requestedVersion: "1.1.2", changelogHeading: "= 1.1.2 =", changelog: "= 1.1.2 =\n" }),
    /must equal calculated minor version 1\.2\.0/,
  );
  assert.throws(
    () => validateReleasePolicy({ ...base, commits: [{ sha: "b".repeat(40), message: "update receiver" }] }),
    /valid Conventional Commit/,
  );
});

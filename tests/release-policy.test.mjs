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

test("trusted release manifest binds the protected workflow, policy, source, version, and package inputs", async () => {
  const { validateTrustedRelease } = await import(pathToFileURL(policyPath));
  const manifest = {
    schemaVersion: 1,
    defaultBranch: "main",
    workflowPath: ".github/workflows/phpreleaser.yml",
    policy: {
      id: "woocommerce-plugin-release-v1",
      path: "scripts/validate-release-policy.mjs",
    },
    releases: {
      "1.2.0": {
        sourceRevision: "0dc997be6b8e39d391cb542f0ac6dd5377bc7d0f",
        artifactName: "woocommerce-payment-gateway-app_v1.2.0.zip",
        archiveRoot: "woocommerce-payment-gateway-app",
        prepareScript: "scripts/prepare-release.php",
        changelogFile: "README.md",
        changelogHeading: "= 1.2.0 =",
        releaseNotesFile: "RELEASE.md",
      },
    },
  };

  assert.deepEqual(validateTrustedRelease({
    manifest,
    requestedVersion: "1.2.0",
    requestedSourceRevision: "0dc997be6b8e39d391cb542f0ac6dd5377bc7d0f",
    workflowRef: "refs/heads/main",
    defaultBranch: "main",
    workflowPath: ".github/workflows/phpreleaser.yml",
    policyPath: "scripts/validate-release-policy.mjs",
  }), manifest.releases["1.2.0"]);

  for (const change of [
    { requestedSourceRevision: "f".repeat(40) },
    { requestedVersion: "1.2.1" },
    { workflowRef: "refs/heads/release/1.2.0" },
    { defaultBranch: "develop" },
    { workflowPath: ".github/workflows/other.yml" },
    { policyPath: "scripts/other-policy.mjs" },
  ]) {
    assert.throws(
      () => validateTrustedRelease({
        manifest,
        requestedVersion: "1.2.0",
        requestedSourceRevision: "0dc997be6b8e39d391cb542f0ac6dd5377bc7d0f",
        workflowRef: "refs/heads/main",
        defaultBranch: "main",
        workflowPath: ".github/workflows/phpreleaser.yml",
        policyPath: "scripts/validate-release-policy.mjs",
        ...change,
      }),
      /trusted release manifest|protected default branch/i,
    );
  }
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

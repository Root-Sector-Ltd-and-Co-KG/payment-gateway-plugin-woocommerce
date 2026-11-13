import assert from "node:assert/strict";
import { execFileSync, spawnSync } from "node:child_process";
import { createHash } from "node:crypto";
import { existsSync, mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from "node:fs";
import os from "node:os";
import path from "node:path";
import test from "node:test";
import { fileURLToPath, pathToFileURL } from "node:url";

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const policyPath = path.join(root, "scripts/validate-release-policy.mjs");
const finalReceiverRevision = "48f55852e1b7cb6eb4e72a745b4411236fbcb94a";
// SHA-256 of the raw plugin blob at finalReceiverRevision, independent of the evolving checkout.
const finalReceiverSha256 = "fb1e9e3ed72aca01f95689e7562d52676589f6e2c6f12129e7d78ac68a874d36";

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
        sourceRevision: finalReceiverRevision,
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
    requestedSourceRevision: finalReceiverRevision,
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
        requestedSourceRevision: finalReceiverRevision,
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

test("1.2.0 packages the exact final WooCommerce receiver source", () => {
  const manifest = JSON.parse(readFileSync(path.join(root, ".github/release-policy.json"), "utf8"));
  const sourceRevision = manifest.releases["1.2.0"].sourceRevision;
  assert.equal(sourceRevision, finalReceiverRevision);

  const pluginPath = "woocommerce-payment-gateway-app.php";
  const archive = execFileSync("git", ["-C", root, "-c", "core.autocrlf=false", "archive", sourceRevision, pluginPath]);
  const archivedPlugin = execFileSync("tar", ["-xOf", "-", pluginPath], {
    input: archive,
  });
  assert.equal(createHash("sha256").update(archivedPlugin).digest("hex"), finalReceiverSha256);
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
test("release policy CLI excludes merge envelopes and validates every source commit", async (t) => {
  const scenarios = [
    { name: "merged fix permits a patch", message: "fix(checkout): explain phone correction", version: "1.2.1", bump: "patch" },
    { name: "merged feature requires a minor", message: "feat(checkout): add a payment option", version: "1.3.0", bump: "minor" },
    { name: "merged feature rejects a patch", message: "feat(checkout): add a payment option", version: "1.2.1", error: /calculated minor version 1\.3\.0/ },
    { name: "merged breaking header requires a major", message: "fix(checkout)!: replace checkout contract", version: "2.0.0", bump: "major" },
    { name: "merged breaking footer requires a major", message: "fix(checkout): replace checkout contract\n\nBREAKING CHANGE: Clients must migrate to checkout v2.", version: "2.0.0", bump: "major" },
    { name: "merged breaking change rejects a minor", message: "feat(checkout)!: replace checkout contract", version: "1.3.0", error: /calculated major version 2\.0\.0/ },
    { name: "nonmerge message resembling an envelope is still rejected", message: "Merge pull request #42 from fixture/change", version: "1.2.1", error: /must use a valid Conventional Commit message/ },
  ];

  for (const scenario of scenarios) {
    await t.test(scenario.name, (t) => {
      const fixtureRoot = mkdtempSync(path.join(os.tmpdir(), "release-policy-history-"));
      t.after(() => rmSync(fixtureRoot, { recursive: true, force: true }));
      const repository = path.join(fixtureRoot, "source");
      const emptyHooks = path.join(fixtureRoot, "empty-hooks");
      mkdirSync(repository);
      mkdirSync(emptyHooks);
      const git = (...args) => execFileSync("git", [
        "-C", repository,
        "-c", "user.name=Release policy fixture",
        "-c", "user.email=release-policy@example.invalid",
        "-c", "commit.gpgsign=false",
        "-c", "tag.gpgsign=false",
        "-c", `core.hooksPath=${emptyHooks}`,
        ...args,
      ], { encoding: "utf8", stdio: ["ignore", "pipe", "pipe"] }).trim();
      git("init", "--initial-branch=main", `--template=${emptyHooks}`);
      writeFileSync(path.join(repository, "README.md"), "= 1.2.1 =\n= 1.3.0 =\n= 2.0.0 =\n");
      git("add", "README.md");
      git("commit", "-m", "chore: establish previous release");
      git("tag", "1.2.0");
      git("checkout", "-b", "change");
      writeFileSync(path.join(repository, "change.txt"), "source change\n");
      git("add", "change.txt");
      git("commit", "-m", scenario.message);
      const changeRevision = git("rev-parse", "HEAD");
      git("checkout", "main");
      writeFileSync(path.join(repository, "main.txt"), "main change\n");
      git("add", "main.txt");
      git("commit", "-m", "fix: preserve main branch changes");
      git("merge", "--no-ff", "change", "-m", "Merge pull request #42 from fixture/change");
      const sourceRevision = git("rev-parse", "HEAD");
      assert.equal(git("show", "-s", "--format=%P", sourceRevision).split(" ").length, 2);

      const manifestPath = path.join(fixtureRoot, "release-policy.json");
      const outputPath = path.join(fixtureRoot, "validation.json");
      writeFileSync(manifestPath, JSON.stringify({
        schemaVersion: 1,
        defaultBranch: "main",
        workflowPath: ".github/workflows/phpreleaser.yml",
        policy: { id: "woocommerce-plugin-release-v1", path: "scripts/validate-release-policy.mjs" },
        releases: {
          [scenario.version]: {
            sourceRevision,
            artifactName: `woocommerce-payment-gateway-app_v${scenario.version}.zip`,
            archiveRoot: "woocommerce-payment-gateway-app",
            prepareScript: "scripts/prepare-release.php",
            changelogFile: "README.md",
            changelogHeading: `= ${scenario.version} =`,
            releaseNotesFile: "RELEASE.md",
          },
        },
      }));
      const result = spawnSync(process.execPath, [
        policyPath,
        "--repository", repository,
        "--source-sha", sourceRevision,
        "--version", scenario.version,
        "--release-manifest", manifestPath,
        "--workflow-ref", "refs/heads/main",
        "--default-branch", "main",
        "--workflow-path", ".github/workflows/phpreleaser.yml",
        "--policy-path", "scripts/validate-release-policy.mjs",
        "--policy-revision", "d".repeat(40),
        "--output", outputPath,
      ], { encoding: "utf8" });
      assert.ifError(result.error);
      if (scenario.error) {
        assert.equal(result.status, 1);
        assert.match(result.stderr, scenario.error);
        if (scenario.error.source.includes("Conventional Commit")) {
          assert.ok(result.stderr.includes(changeRevision), "Reject the malformed source commit itself");
        }
        assert.equal(existsSync(outputPath), false, "Rejected releases must not emit validation evidence");
      } else {
        assert.equal(result.status, 0, result.stderr);
        const evidence = JSON.parse(readFileSync(outputPath, "utf8"));
        assert.equal(evidence.validated, true);
        assert.equal(evidence.sourceRevision, sourceRevision);
        assert.equal(evidence.previousTag, "1.2.0");
        assert.equal(evidence.version, scenario.version);
        assert.equal(evidence.requiredBump, scenario.bump);
      }
    });
  }
});

const controlBundlePath = path.join(root, "scripts/release-control-digest.mjs");

function discoverStaticReleaseControlInputs(entryPaths) {
  const discovered = new Set(entryPaths);
  const pending = [...entryPaths];
  while (pending.length > 0) {
    const relativePath = pending.shift();
    const source = readFileSync(path.join(root, relativePath), "utf8");
    const dependencies = [];
    for (const match of source.matchAll(/path\.join\(root,\s*["']([^"']+)["']\)/g)) {
      dependencies.push(match[1]);
    }
    for (const match of source.matchAll(/dirname\(__DIR__\)\s*\.\s*["']\/([^"']+)["']/g)) {
      dependencies.push(match[1]);
    }
    for (const match of source.matchAll(/(?:from\s+|import\s*\()\s*["'](\.\.?\/[^"']+)["']/g)) {
      dependencies.push(path.relative(root, path.resolve(path.dirname(path.join(root, relativePath)), match[1])));
    }
    for (const dependency of dependencies) {
      if (!discovered.has(dependency)) {
        discovered.add(dependency);
        pending.push(dependency);
      }
    }
  }
  return [...discovered].sort();
}

test("release-control digest binds the exact publication controls and fails on a missing file", async (t) => {
  const { RELEASE_CONTROL_FILES, calculateReleaseControlBundle } = await import(pathToFileURL(controlBundlePath));
  assert.deepEqual(RELEASE_CONTROL_FILES, [
    ".github/workflows/phpreleaser.yml",
    ".github/release-policy.json",
    "scripts/release-control-digest.mjs",
    "scripts/validate-release-policy.mjs",
    "scripts/prepare-release.php",
    "scripts/publish-release.mjs",
    "tests/release-workflow.test.php",
    "tests/release-policy.test.mjs",
    "tests/release-workflow.test.mjs",
    "tests/publish-release.test.mjs",
    ".github/workflows/validate-release-controls.yml",
    "README.md",
    ".wp-env.json",
    "tests/hpos-integration.php",
  ]);
  const staticInputs = discoverStaticReleaseControlInputs([
    "tests/release-workflow.test.php",
    "tests/release-policy.test.mjs",
    "tests/release-workflow.test.mjs",
    "tests/publish-release.test.mjs",
  ]);
  assert.deepEqual(
    staticInputs.filter((filePath) => !RELEASE_CONTROL_FILES.includes(filePath)),
    [],
    "Every statically read or imported repository input must be bound into the release-control digest",
  );
  const first = calculateReleaseControlBundle(root);
  const second = calculateReleaseControlBundle(root);
  assert.deepEqual(second, first);
  assert.match(first.digest, /^sha256:[0-9a-f]{64}$/);

  const fixtureRoot = mkdtempSync(path.join(os.tmpdir(), "release-controls-"));
  t.after(() => rmSync(fixtureRoot, { recursive: true, force: true }));
  assert.throws(() => calculateReleaseControlBundle(fixtureRoot), /missing release-control file/i);
});

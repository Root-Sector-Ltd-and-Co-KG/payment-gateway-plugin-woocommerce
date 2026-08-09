import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";
import { fileURLToPath } from "node:url";

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const workflow = fs.readFileSync(path.join(root, ".github/workflows/phpreleaser.yml"), "utf8");

test("Woo release is manually dispatched for an exact source and version", () => {
  assert.match(workflow, /workflow_dispatch:/);
  assert.match(workflow, /source_sha:/);
  assert.match(workflow, /version:/);
  assert.doesNotMatch(workflow, /push:\s*\n\s*tags:/);
  assert.match(workflow, /^permissions:\s*\n\s+contents: read$/m);
  assert.doesNotMatch(workflow, /issues: write|pull-requests: write|packages: write/);
  assert.doesNotMatch(workflow, /payment-gateway-release-orchestrator|workflow_call/);
  assert.match(workflow, /refs\/heads\/main/);
  assert.match(workflow, /github\.event\.repository\.default_branch/);
  assert.match(workflow, /github\.ref_protected/);
  assert.match(workflow, /WORKFLOW_REF_PROTECTED" != "true"/);
  assert.match(workflow, /\.github\/release-policy\.json/);
});

test("every action in the Woo release workflow is pinned to a reviewed commit", () => {
  const actionLines = workflow.split("\n").filter((line) => /\buses:/.test(line));
  assert.ok(actionLines.length > 0);
  for (const line of actionLines) {
    assert.match(line, /uses:\s+[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+@[0-9a-f]{40}\s+#\s+v\S+\s*$/);
  }
});

test("exact-source validation and genuine HPOS succeed before write-scoped publication", () => {
  assert.match(workflow, /validate:\s*\n[\s\S]*?permissions:\s*\n\s+contents: read/);
  assert.match(workflow, /php tests\/ipn-v2-receiver\.test\.php/);
  assert.match(workflow, /npm ci/);
  assert.match(workflow, /npm run wp-env -- start/);
  assert.match(workflow, /woocommerce_custom_orders_table_enabled yes/);
  assert.match(workflow, /woocommerce_custom_orders_table_data_sync_enabled no/);
  assert.match(workflow, /wp eval-file wp-content\/plugins\/payment-gateway-plugin-woocommerce\/tests\/hpos-integration\.php/);
  assert.match(workflow, /publish:\s*\n[\s\S]*?needs: validate[\s\S]*?permissions:\s*\n\s+contents: write/);
  assert.match(workflow, /needs\.validate\.result/);
  assert.match(workflow, /actions\/upload-artifact@[0-9a-f]{40}/);
  assert.match(workflow, /actions\/download-artifact@[0-9a-f]{40}/);
  assert.match(workflow, /php "\$GITHUB_WORKSPACE\/release-control\/\$PREPARE_SCRIPT" "\$PLUGIN_RELEASE_VERSION"/);
  assert.doesNotMatch(workflow, /\(cd "\$PACKAGE_DIR" && php "\$PREPARE_SCRIPT"/);

  const publish = workflow.slice(workflow.indexOf("  publish:"));
  assert.doesNotMatch(publish, /payment-gateway-plugin-woocommerce|prepare-release\.php|git archive/);
  for (const checkout of workflow.matchAll(/uses: actions\/checkout@[\s\S]*?(?=\n\s*- name:|\n\s*$)/g)) {
    assert.match(checkout[0], /persist-credentials: false/);
  }
  const tokenLines = workflow.split("\n").filter((line) => /GH_TOKEN:/.test(line));
  assert.ok(tokenLines.length > 0);
  assert.ok(tokenLines.every((line) => /^ {10}GH_TOKEN:/.test(line)), "GH_TOKEN must be scoped to an exact step");
});

test("publication resumes a verified draft and publishes only after exact asset readback", () => {
  const policy = workflow.indexOf("Validate trusted release manifest, policy, and source");
  const bundle = workflow.indexOf("Transfer validated release bundle");
  const publish = workflow.indexOf("  publish:");
  assert.ok(policy >= 0 && bundle > policy && publish > bundle);
  assert.match(workflow, /scripts\/validate-release-policy\.mjs/);
  assert.match(workflow, /sha256sum/);
  assert.match(workflow, /artifactSha256/);
  assert.match(workflow, /checksumAssetName/);
  assert.match(workflow, /validationJobResult/);
  assert.match(workflow, /publish-release\.mjs/);
  assert.match(workflow, /draft/i);
  assert.match(workflow, /release-bundle/);
  assert.doesNotMatch(workflow, /allowUpdates|--clobber|release-action|zip-release/);
});

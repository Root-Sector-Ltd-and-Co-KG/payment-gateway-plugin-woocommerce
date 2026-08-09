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
});

test("publication validates policy before tagging and publishes non-replaceable checksum evidence", () => {
  const policy = workflow.indexOf("Validate release policy and source");
  const tag = workflow.indexOf("Create immutable tag");
  const release = workflow.indexOf("Create immutable GitHub release");
  assert.ok(policy >= 0 && tag > policy && release > tag);
  assert.match(workflow, /scripts\/validate-release-policy\.mjs/);
  assert.match(workflow, /sha256sum/);
  assert.match(workflow, /artifactSha256/);
  assert.match(workflow, /checksumAssetName/);
  assert.match(workflow, /validationJobResult/);
  assert.match(workflow, /gh release view/);
  assert.match(workflow, /gh release create/);
  assert.doesNotMatch(workflow, /allowUpdates|--clobber|release-action|zip-release/);
});

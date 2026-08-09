import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";
import { fileURLToPath } from "node:url";

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const workflow = fs.readFileSync(path.join(root, ".github/workflows/phpreleaser.yml"), "utf8");
const prWorkflowPath = path.join(root, ".github/workflows/validate-release-controls.yml");
const prWorkflow = fs.existsSync(prWorkflowPath) ? fs.readFileSync(prWorkflowPath, "utf8") : "";

test("Woo publication is only repository-dispatched from default-branch controls", () => {
  assert.match(workflow, /repository_dispatch:\s*\n\s+types:\s*\[plugin-release-approved\]/);
  assert.match(workflow, /github\.event\.client_payload\.source_sha/);
  assert.match(workflow, /github\.event\.client_payload\.version/);
  assert.match(workflow, /keys \| sort == \[\"source_sha\", \"version\"\]/);
  assert.doesNotMatch(workflow, /workflow_dispatch:|pull_request:/);
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

test("Woo PR validation is separate and cannot publish", () => {
  assert.ok(prWorkflow, "read-only PR validation workflow must exist");
  assert.match(prWorkflow, /pull_request:/);
  assert.match(prWorkflow, /^permissions:\s*\n\s+contents: read$/m);
  assert.doesNotMatch(prWorkflow, /contents: write|repository_dispatch:|workflow_dispatch:|GH_TOKEN:/);
  assert.match(prWorkflow, /node --test tests\/release-policy\.test\.mjs tests\/release-workflow\.test\.mjs tests\/publish-release\.test\.mjs/);
});

test("Woo PR validation fetches the pinned parent source", () => {
  assert.match(prWorkflow, /ref: \$\{\{ github\.event\.pull_request\.head\.sha \}\}/);
  assert.match(prWorkflow, /fetch-depth: 2/);
});

test("Woo PR validation proves the checked-out source against authoritative HPOS", () => {
  const checkout = prWorkflow.indexOf("Checkout pull request source");
  const setupNode = prWorkflow.indexOf("Set up Node.js for HPOS");
  const install = prWorkflow.indexOf("npm ci");
  const start = prWorkflow.indexOf("npm run wp-env -- start");
  const enable = prWorkflow.indexOf("woocommerce_custom_orders_table_enabled yes");
  const disableSync = prWorkflow.indexOf("woocommerce_custom_orders_table_data_sync_enabled no");
  const integration = prWorkflow.indexOf("wp eval-file wp-content/plugins/payment-gateway-plugin-woocommerce/tests/hpos-integration.php");
  const stop = prWorkflow.indexOf("npm run wp-env -- stop");

  assert.ok(checkout >= 0 && setupNode > checkout && install > setupNode && start > install);
  assert.ok(enable > start && disableSync > enable && integration > disableSync && stop > integration);
  assert.match(prWorkflow, /ref: \$\{\{ github\.event\.pull_request\.head\.sha \}\}/);
  assert.match(prWorkflow, /actions\/setup-node@48b55a011bda9f5d6aeb4c2d9c7362e8dae4041e\s+#\s+v6\.4\.0/);
  assert.match(prWorkflow, /find \. -name '\*\.php' -print0 \| xargs -0 -n1 php -l/);
  assert.match(prWorkflow, /if: always\(\)[\s\S]*?npm run wp-env -- stop/);
  assert.equal((prWorkflow.match(/uses: actions\/checkout@/g) ?? []).length, 1, "HPOS must use the same checkout as PR validation");
});

test("every action in Woo release-control workflows is pinned to a reviewed commit", () => {
  const actionLines = `${workflow}\n${prWorkflow}`.split("\n").filter((line) => /\buses:/.test(line));
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
  assert.match(workflow, /git -C payment-gateway-plugin-woocommerce archive "\$SOURCE_SHA" \| tar -x -C "\$PACKAGE_DIR"/);
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

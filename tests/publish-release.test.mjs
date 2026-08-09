import assert from "node:assert/strict";
import { createHash } from "node:crypto";
import { mkdtempSync, rmSync, writeFileSync } from "node:fs";
import os from "node:os";
import path from "node:path";
import test from "node:test";

import { publishPluginRelease } from "../scripts/publish-release.mjs";

const sourceRevision = "a".repeat(40);
const digest = (body) => `sha256:${createHash("sha256").update(body).digest("hex")}`;

function fixture() {
  const root = mkdtempSync(path.join(os.tmpdir(), "plugin-publish-"));
  const files = [
    ["plugin.zip", Buffer.from("zip-body")],
    ["plugin.zip.sha256", Buffer.from(`${digest(Buffer.from("zip-body")).slice(7)}  plugin.zip\n`)],
    ["plugin-release-validation.json", Buffer.from('{"validated":true}\n')],
  ].map(([name, body]) => {
    const filePath = path.join(root, name);
    writeFileSync(filePath, body);
    return { name, path: filePath, body, digest: digest(body) };
  });
  return { root, files };
}

function fakeGitHub({ tag = null, release = null, failUploadName = null } = {}) {
  const calls = [];
  let currentTag = tag;
  let currentRelease = release && structuredClone(release);
  let nextAssetId = 100;

  return {
    calls,
    async resolveTag() {
      calls.push("resolve-tag");
      return currentTag;
    },
    async createTag(_version, sha) {
      calls.push("create-tag");
      currentTag = sha;
    },
    async getRelease() {
      calls.push("get-release");
      return currentRelease && structuredClone(currentRelease);
    },
    async createDraftRelease({ tagName }) {
      calls.push("create-draft");
      currentRelease = { id: 50, tagName, draft: true, assets: [] };
      return structuredClone(currentRelease);
    },
    async uploadAsset({ name, path: filePath }) {
      calls.push(`upload:${name}`);
      if (name === failUploadName) throw new Error("simulated upload failure");
      const body = await import("node:fs/promises").then(({ readFile }) => readFile(filePath));
      currentRelease.assets.push({ id: nextAssetId++, name, digest: digest(body), body });
    },
    async downloadAsset(asset) {
      calls.push(`download:${asset.name}`);
      const found = currentRelease.assets.find(({ id }) => id === asset.id);
      return Buffer.from(found.body);
    },
    async publishRelease() {
      calls.push("publish");
      currentRelease.draft = false;
    },
  };
}

async function run(files, github) {
  return publishPluginRelease({
    repository: "Root-Sector-Ltd-and-Co-KG/plugin",
    version: "1.2.0",
    sourceRevision,
    title: "Plugin 1.2.0",
    notes: "Release notes",
    assets: files.map(({ name, path: filePath }) => ({ name, path: filePath })),
    github,
  });
}

test("creates a draft, reads back every exact asset, then publishes", async (t) => {
  const { root, files } = fixture();
  t.after(() => rmSync(root, { recursive: true }));
  const github = fakeGitHub();

  await run(files, github);

  assert.deepEqual(github.calls, [
    "resolve-tag",
    "create-tag",
    "get-release",
    "create-draft",
    "get-release",
    "upload:plugin.zip",
    "upload:plugin.zip.sha256",
    "upload:plugin-release-validation.json",
    "get-release",
    "download:plugin.zip",
    "download:plugin.zip.sha256",
    "download:plugin-release-validation.json",
    "publish",
  ]);
});

test("resumes a partial draft by retaining a verified asset and uploading only missing names", async (t) => {
  const { root, files } = fixture();
  t.after(() => rmSync(root, { recursive: true }));
  const github = fakeGitHub({
    tag: sourceRevision,
    release: {
      id: 50,
      tagName: "1.2.0",
      draft: true,
      assets: [{ id: 10, name: files[0].name, digest: files[0].digest, body: files[0].body }],
    },
  });

  await run(files, github);

  assert.ok(!github.calls.includes("upload:plugin.zip"));
  assert.ok(github.calls.includes("upload:plugin.zip.sha256"));
  assert.equal(github.calls.at(-1), "publish");
});

test("refuses a published release or a draft asset with the wrong content", async (t) => {
  const { root, files } = fixture();
  t.after(() => rmSync(root, { recursive: true }));

  const published = fakeGitHub({
    tag: sourceRevision,
    release: { id: 50, tagName: "1.2.0", draft: false, assets: [] },
  });
  await assert.rejects(() => run(files, published), /published release.*refused/i);
  assert.ok(!published.calls.includes("publish"));

  const wrongBody = Buffer.from("wrong");
  const wrong = fakeGitHub({
    tag: sourceRevision,
    release: {
      id: 50,
      tagName: "1.2.0",
      draft: true,
      assets: [{ id: 10, name: files[0].name, digest: digest(wrongBody), body: wrongBody }],
    },
  });
  await assert.rejects(() => run(files, wrong), /existing asset.*does not match/i);
  assert.ok(!wrong.calls.some((call) => call.startsWith("upload:")));
  assert.ok(!wrong.calls.includes("publish"));
});

test("an interrupted upload leaves the release as a resumable draft", async (t) => {
  const { root, files } = fixture();
  t.after(() => rmSync(root, { recursive: true }));
  const github = fakeGitHub({ failUploadName: "plugin.zip.sha256" });

  await assert.rejects(() => run(files, github), /simulated upload failure/);

  assert.ok(github.calls.includes("create-draft"));
  assert.ok(!github.calls.includes("publish"));
});

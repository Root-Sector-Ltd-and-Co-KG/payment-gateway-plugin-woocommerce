import assert from "node:assert/strict";
import { createHash } from "node:crypto";
import { mkdtempSync, rmSync, writeFileSync } from "node:fs";
import os from "node:os";
import path from "node:path";
import test from "node:test";

import { publishPluginRelease } from "../scripts/publish-release.mjs";

const sourceRevision = "a".repeat(40);
const digest = (body) => `sha256:${createHash("sha256").update(body).digest("hex")}`;
const expectedMetadata = {
  tagName: "1.2.0",
  title: "Plugin 1.2.0",
  body: "Release notes",
  prerelease: false,
};

function draftRelease(overrides = {}) {
  return { id: 50, ...expectedMetadata, draft: true, assets: [], ...overrides };
}

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

function fakeGitHub({
  tag = null,
  release = null,
  failUploadName = null,
  publishError = null,
  publishResponseOverrides = {},
  finalReadbackOverrides = {},
} = {}) {
  const calls = [];
  let currentTag = tag;
  let currentRelease = release && structuredClone(release);
  let nextAssetId = 100;
  let published = false;

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
      if (!currentRelease) return null;
      return structuredClone(
        published ? { ...currentRelease, ...finalReadbackOverrides } : currentRelease,
      );
    },
    async createDraftRelease({ tagName, title, notes }) {
      calls.push("create-draft");
      currentRelease = draftRelease({ tagName, title, body: notes });
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
    async publishRelease(_releaseId, metadata = {
      title: currentRelease.title,
      notes: currentRelease.body,
    }) {
      calls.push("publish");
      if (publishError) throw publishError;
      currentRelease = {
        ...currentRelease,
        title: metadata.title,
        body: metadata.notes,
        prerelease: false,
        draft: false,
        ...publishResponseOverrides,
      };
      published = true;
      return structuredClone(currentRelease);
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
    "get-release",
    "download:plugin.zip",
    "download:plugin.zip.sha256",
    "download:plugin-release-validation.json",
  ]);
});

test("resumes a partial draft by retaining a verified asset and uploading only missing names", async (t) => {
  const { root, files } = fixture();
  t.after(() => rmSync(root, { recursive: true }));
  const github = fakeGitHub({
    tag: sourceRevision,
    release: draftRelease({
      assets: [{ id: 10, name: files[0].name, digest: files[0].digest, body: files[0].body }],
    }),
  });

  await run(files, github);

  assert.ok(!github.calls.includes("upload:plugin.zip"));
  assert.ok(github.calls.includes("upload:plugin.zip.sha256"));
  assert.equal(github.calls.at(-1), "download:plugin-release-validation.json");
});

test("refuses a published release, mismatched draft metadata, or a wrong asset", async (t) => {
  const { root, files } = fixture();
  t.after(() => rmSync(root, { recursive: true }));

  const published = fakeGitHub({
    tag: sourceRevision,
    release: draftRelease({ draft: false }),
  });
  await assert.rejects(() => run(files, published), /published release.*refused/i);
  assert.ok(!published.calls.includes("publish"));

  for (const mismatch of [
    { title: "Wrong title" },
    { body: "Wrong body" },
    { prerelease: true },
  ]) {
    const wrongMetadata = fakeGitHub({
      tag: sourceRevision,
      release: draftRelease(mismatch),
    });
    await assert.rejects(() => run(files, wrongMetadata), /draft release metadata/i);
    assert.ok(!wrongMetadata.calls.some((call) => call.startsWith("upload:")));
  }

  const wrongBody = Buffer.from("wrong");
  const wrong = fakeGitHub({
    tag: sourceRevision,
    release: draftRelease({
      assets: [{ id: 10, name: files[0].name, digest: digest(wrongBody), body: wrongBody }],
    }),
  });
  await assert.rejects(() => run(files, wrong), /existing asset.*does not match/i);
  assert.ok(!wrong.calls.some((call) => call.startsWith("upload:")));
  assert.ok(!wrong.calls.includes("publish"));
});

test("fails closed on publish failure or mismatched publish response", async (t) => {
  const { root, files } = fixture();
  t.after(() => rmSync(root, { recursive: true }));

  const failed = fakeGitHub({ publishError: new Error("publish failed") });
  await assert.rejects(() => run(files, failed), /publish failed/);

  const mismatched = fakeGitHub({ publishResponseOverrides: { title: "Wrong title" } });
  await assert.rejects(() => run(files, mismatched), /published release metadata/i);
});

test("fails closed when final release metadata or asset readback changes", async (t) => {
  const { root, files } = fixture();
  t.after(() => rmSync(root, { recursive: true }));

  const wrongMetadata = fakeGitHub({ finalReadbackOverrides: { body: "Wrong body" } });
  await assert.rejects(() => run(files, wrongMetadata), /final release metadata/i);

  const wrongAsset = fakeGitHub({
    finalReadbackOverrides: {
      assets: [{ id: 999, name: files[0].name, digest: digest(Buffer.from("wrong")), body: Buffer.from("wrong") }],
    },
  });
  await assert.rejects(() => run(files, wrongAsset), /all expected assets|does not match/i);
});

test("an interrupted upload leaves the release as a resumable draft", async (t) => {
  const { root, files } = fixture();
  t.after(() => rmSync(root, { recursive: true }));
  const github = fakeGitHub({ failUploadName: "plugin.zip.sha256" });

  await assert.rejects(() => run(files, github), /simulated upload failure/);

  assert.ok(github.calls.includes("create-draft"));
  assert.ok(!github.calls.includes("publish"));
});

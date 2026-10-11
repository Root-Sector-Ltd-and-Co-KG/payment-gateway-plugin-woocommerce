import assert from "node:assert/strict";
import { createHash } from "node:crypto";
import { chmodSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from "node:fs";
import os from "node:os";
import path from "node:path";
import test from "node:test";

import { createGitHubClient, publishPluginRelease } from "../scripts/publish-release.mjs";

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

function fakeGh(t, scenario) {
  const root = mkdtempSync(path.join(os.tmpdir(), "plugin-publish-gh-"));
  const executable = path.resolve(root, "gh");
  const scenarioPath = path.resolve(root, "scenario.json");
  const callsPath = path.resolve(root, "calls.jsonl");
  writeFileSync(scenarioPath, JSON.stringify(scenario));
  writeFileSync(executable, `#!/usr/bin/env node
const fs = require("node:fs");
const args = process.argv.slice(2);
const callsPath = ${JSON.stringify(callsPath)};
const scenarioPath = ${JSON.stringify(scenarioPath)};
fs.appendFileSync(callsPath, JSON.stringify(args) + "\\n");
const scenario = JSON.parse(fs.readFileSync(scenarioPath, "utf8"));
const endpoint = args.find((arg) => arg.startsWith("repos/"));
const method = args[args.indexOf("--method") + 1];
function fail(message, status = 1) {
  process.stderr.write(message + "\\n");
  process.exit(status);
}
if (method === "GET" && endpoint?.includes("/releases/tags/")) {
  if (scenario.tagError) fail(scenario.tagError, 2);
  if (Object.hasOwn(scenario, "tagOutput")) {
    process.stdout.write(scenario.tagOutput);
  } else {
    if (!scenario.published) fail("HTTP 404: Not Found");
    process.stdout.write(JSON.stringify(scenario.published));
  }
} else if (method === "GET" && endpoint?.endsWith("/releases?per_page=100")) {
  if (scenario.listError) fail(scenario.listError, 2);
  process.stdout.write(JSON.stringify(scenario.pages || [[]]));
} else if (method === "POST" && endpoint?.endsWith("/releases")) {
  const input = JSON.parse(fs.readFileSync(0, "utf8"));
  const release = {
    id: 99,
    tag_name: input.tag_name,
    name: input.name,
    body: input.body,
    draft: true,
    prerelease: false,
    assets: [],
  };
  scenario.pages = scenario.pages || [[]];
  scenario.pages[0].push(release);
  fs.writeFileSync(scenarioPath, JSON.stringify(scenario));
  process.stdout.write(JSON.stringify(release));
} else {
  fail("Unexpected fake gh invocation: " + JSON.stringify(args), 3);
}
`);
  chmodSync(executable, 0o755);

  t.after(() => rmSync(root, { recursive: true }));
  return {
    executable,
    calls: () => readFileSync(callsPath, "utf8").trim().split("\n").filter(Boolean).map(JSON.parse),
  };
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

test("GitHub adapter prefers the published by-tag endpoint without listing drafts", async (t) => {
  const published = {
    id: 40,
    tag_name: "1.2.0",
    name: "Plugin 1.2.0",
    body: "Release notes",
    draft: false,
    prerelease: false,
    assets: [],
  };
  const gh = fakeGh(t, { published, listError: "draft listing must not run" });

  assert.deepEqual(await createGitHubClient("owner/plugin", gh.executable).getRelease("1.2.0"), {
    id: 40,
    ...expectedMetadata,
    draft: false,
    assets: [],
  });
  assert.equal(gh.calls().length, 1);
});

test("GitHub adapter reads back a newly created draft through the paginated release list", async (t) => {
  const gh = fakeGh(t, { pages: [[], []] });
  const github = createGitHubClient("owner/plugin", gh.executable);

  await github.createDraftRelease({
    tagName: "1.2.0",
    title: "Plugin 1.2.0",
    notes: "Release notes",
  });

  assert.deepEqual(await github.getRelease("1.2.0"), draftRelease({ id: 99 }));
  const calls = gh.calls();
  assert.equal(calls.length, 3);
  assert.ok(calls[2].includes("--paginate"));
  assert.ok(calls[2].includes("--slurp"));
});

test("GitHub adapter discovers one exact matching draft on a later page for resume", async (t) => {
  const draft = {
    id: 51,
    tag_name: "1.2.0",
    name: "Plugin 1.2.0",
    body: "Release notes",
    draft: true,
    prerelease: false,
    assets: [],
  };
  const gh = fakeGh(t, {
    pages: [
      [{ ...draft, id: 41, tag_name: "1.1.0" }],
      [draft],
    ],
  });

  assert.deepEqual(
    await createGitHubClient("owner/plugin", gh.executable).getRelease("1.2.0"),
    draftRelease({ id: 51 }),
  );
});

test("GitHub adapter returns absent when no release has the exact tag", async (t) => {
  const gh = fakeGh(t, { pages: [[{ tag_name: "1.1.0" }], []] });
  assert.equal(await createGitHubClient("owner/plugin", gh.executable).getRelease("1.2.0"), null);
});

test("GitHub adapter rejects duplicate exact draft matches", async (t) => {
  const draft = {
    id: 51,
    tag_name: "1.2.0",
    name: "Plugin 1.2.0",
    body: "Release notes",
    draft: true,
    prerelease: false,
    assets: [],
  };
  const gh = fakeGh(t, { pages: [[draft], [{ ...draft, id: 52 }]] });

  await assert.rejects(
    () => createGitHubClient("owner/plugin", gh.executable).getRelease("1.2.0"),
    /multiple releases.*exact tag/i,
  );
});

test("GitHub adapter preserves non-404 by-tag and draft-list API failures", async (t) => {
  const gh = fakeGh(t, { tagError: "HTTP 500: tag lookup failed" });
  await assert.rejects(
    () => createGitHubClient("owner/plugin", gh.executable).getRelease("1.2.0"),
    /tag lookup failed/i,
  );
});

test("GitHub adapter does not treat a non-404 error containing not found as absence", async (t) => {
  const gh = fakeGh(t, { tagError: "HTTP 500: upstream index not found" });
  await assert.rejects(
    () => createGitHubClient("owner/plugin", gh.executable).getRelease("1.2.0"),
    /upstream index not found/i,
  );
  assert.equal(gh.calls().length, 1);
});

test("GitHub adapter does not treat malformed successful output as absence", async (t) => {
  const gh = fakeGh(t, { tagOutput: "Not Found" });
  await assert.rejects(
    () => createGitHubClient("owner/plugin", gh.executable).getRelease("1.2.0"),
    /JSON|unexpected token/i,
  );
  assert.equal(gh.calls().length, 1);
});

test("GitHub adapter does not treat a blank successful response as absence", async (t) => {
  const gh = fakeGh(t, { tagOutput: "" });
  await assert.rejects(
    () => createGitHubClient("owner/plugin", gh.executable).getRelease("1.2.0"),
    /empty response/i,
  );
  assert.equal(gh.calls().length, 1);
});

test("GitHub adapter does not treat falsey JSON responses as absence", async (t) => {
  for (const tagOutput of ["null", "false"]) {
    const gh = fakeGh(t, { tagOutput });
    await assert.rejects(
      () => createGitHubClient("owner/plugin", gh.executable).getRelease("1.2.0"),
      /falsey JSON response/i,
    );
    assert.equal(gh.calls().length, 1);
  }
});

test("GitHub adapter fails when paginated draft discovery fails", async (t) => {
  const gh = fakeGh(t, { listError: "HTTP 503: draft listing failed" });
  await assert.rejects(
    () => createGitHubClient("owner/plugin", gh.executable).getRelease("1.2.0"),
    /draft listing failed/i,
  );
});

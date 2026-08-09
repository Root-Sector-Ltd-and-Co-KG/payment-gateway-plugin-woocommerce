#!/usr/bin/env node

import { createHash } from "node:crypto";
import { execFileSync } from "node:child_process";
import fs from "node:fs";
import path from "node:path";
import { pathToFileURL } from "node:url";

function sha256(body) {
  return `sha256:${createHash("sha256").update(body).digest("hex")}`;
}

function requireFullRevision(value, label) {
  if (!/^[0-9a-f]{40}$/.test(String(value || ""))) {
    throw new Error(`${label} must be a full lowercase commit SHA`);
  }
  return value;
}

function localAssets(assets) {
  if (!Array.isArray(assets) || assets.length !== 3) {
    throw new Error("Publication requires exactly three release assets");
  }
  const names = new Set();
  return assets.map((asset) => {
    if (!asset?.name || !asset?.path || names.has(asset.name)) {
      throw new Error("Publication asset names and paths must be unique");
    }
    names.add(asset.name);
    const body = fs.readFileSync(asset.path);
    return { ...asset, body, digest: sha256(body) };
  });
}

async function validateRemoteAsset(github, remote, local) {
  if (remote.digest !== local.digest) {
    throw new Error(`Existing asset ${remote.name} does not match the validated local digest`);
  }
  const downloaded = await github.downloadAsset(remote);
  if (!Buffer.from(downloaded).equals(local.body)) {
    throw new Error(`Existing asset ${remote.name} does not match the validated local content`);
  }
}

function indexAssets(release, expectedNames) {
  const indexed = new Map();
  for (const asset of release.assets || []) {
    if (!expectedNames.has(asset.name)) {
      throw new Error(`Draft release contains unexpected asset ${asset.name}`);
    }
    if (indexed.has(asset.name)) {
      throw new Error(`Draft release contains duplicate asset ${asset.name}`);
    }
    indexed.set(asset.name, asset);
  }
  return indexed;
}

export async function publishPluginRelease({
  repository,
  version,
  sourceRevision,
  title,
  notes,
  assets,
  github,
}) {
  requireFullRevision(sourceRevision, "source revision");
  if (!/^\d+\.\d+\.\d+$/.test(String(version || ""))) {
    throw new Error("release version must use x.y.z SemVer");
  }
  const expected = localAssets(assets);
  const expectedNames = new Set(expected.map(({ name }) => name));

  const tagTarget = await github.resolveTag(version);
  if (tagTarget === null) {
    await github.createTag(version, sourceRevision);
  } else if (tagTarget !== sourceRevision) {
    throw new Error(`Existing tag ${version} targets ${tagTarget} instead of ${sourceRevision}`);
  }

  let release = await github.getRelease(version);
  if (release && release.draft !== true) {
    throw new Error(`Published release ${version} is immutable and must be refused`);
  }
  if (release && release.tagName !== version) {
    throw new Error(`Draft release tag ${release.tagName} does not match ${version}`);
  }
  if (!release) {
    await github.createDraftRelease({ tagName: version, title, notes });
    release = await github.getRelease(version);
  }
  if (!release || release.draft !== true) {
    throw new Error(`Could not establish draft release ${version}`);
  }

  let indexed = indexAssets(release, expectedNames);
  for (const local of expected) {
    const remote = indexed.get(local.name);
    if (remote) await validateRemoteAsset(github, remote, local);
  }

  for (const local of expected) {
    if (!indexed.has(local.name)) {
      await github.uploadAsset({
        tagName: version,
        name: local.name,
        path: local.path,
      });
    }
  }

  release = await github.getRelease(version);
  if (!release || release.draft !== true) {
    throw new Error(`Draft release ${version} disappeared before verification`);
  }
  indexed = indexAssets(release, expectedNames);
  if (indexed.size !== expected.length) {
    throw new Error(`Draft release ${version} does not contain all expected assets`);
  }
  for (const local of expected) {
    await validateRemoteAsset(github, indexed.get(local.name), local);
  }

  await github.publishRelease(release.id);
}

function ghFailureIs404(error) {
  return /(?:HTTP 404|not found)/i.test(String(error?.stderr || error?.message || ""));
}

function ghJson(args, { input, allow404 = false } = {}) {
  try {
    const output = execFileSync("gh", args, {
      encoding: "utf8",
      input,
      stdio: ["pipe", "pipe", "pipe"],
    });
    return output.trim() ? JSON.parse(output) : null;
  } catch (error) {
    if (allow404 && ghFailureIs404(error)) return null;
    throw error;
  }
}

export function createGitHubClient(repository) {
  return {
    async resolveTag(version) {
      let reference = ghJson(
        ["api", "--method", "GET", `repos/${repository}/git/ref/tags/${encodeURIComponent(version)}`],
        { allow404: true },
      );
      if (!reference) return null;
      let object = reference.object;
      for (let depth = 0; depth < 2 && object?.type === "tag"; depth += 1) {
        object = ghJson([
          "api",
          "--method",
          "GET",
          `repos/${repository}/git/tags/${object.sha}`,
        ]).object;
      }
      return requireFullRevision(object?.sha, "resolved tag target");
    },
    async createTag(version, sourceRevision) {
      ghJson(
        ["api", "--method", "POST", `repos/${repository}/git/refs`, "--input", "-"],
        {
          input: JSON.stringify({
            ref: `refs/tags/${version}`,
            sha: sourceRevision,
          }),
        },
      );
    },
    async getRelease(version) {
      const release = ghJson(
        ["api", "--method", "GET", `repos/${repository}/releases/tags/${encodeURIComponent(version)}`],
        { allow404: true },
      );
      if (!release) return null;
      return {
        id: release.id,
        tagName: release.tag_name,
        draft: release.draft,
        assets: (release.assets || []).map((asset) => ({
          id: asset.id,
          name: asset.name,
          digest: asset.digest,
        })),
      };
    },
    async createDraftRelease({ tagName, title, notes }) {
      return ghJson(
        ["api", "--method", "POST", `repos/${repository}/releases`, "--input", "-"],
        {
          input: JSON.stringify({
            tag_name: tagName,
            name: title,
            body: notes,
            draft: true,
            prerelease: false,
          }),
        },
      );
    },
    async uploadAsset({ tagName, name, path: filePath }) {
      execFileSync(
        "gh",
        ["release", "upload", tagName, `${filePath}#${name}`, "--repo", repository],
        { stdio: ["ignore", "pipe", "pipe"] },
      );
    },
    async downloadAsset(asset) {
      return execFileSync(
        "gh",
        [
          "api",
          "--method",
          "GET",
          "-H",
          "Accept: application/octet-stream",
          `repos/${repository}/releases/assets/${asset.id}`,
        ],
        { stdio: ["ignore", "pipe", "pipe"] },
      );
    },
    async publishRelease(releaseId) {
      ghJson(
        ["api", "--method", "PATCH", `repos/${repository}/releases/${releaseId}`, "--input", "-"],
        { input: JSON.stringify({ draft: false }) },
      );
    },
  };
}

function parseArgs(argv) {
  const args = {};
  for (let index = 0; index < argv.length; index += 2) {
    const name = argv[index]?.replace(/^--/, "").replace(/-([a-z])/g, (_, letter) => letter.toUpperCase());
    const value = argv[index + 1];
    if (!name || value === undefined) throw new Error("Invalid publication arguments");
    args[name] = value;
  }
  return args;
}

function validateBundle(args) {
  if (args.validationJobResult !== "success") {
    throw new Error("Publication requires a successful validation job");
  }
  const manifest = JSON.parse(fs.readFileSync(args.releaseManifest, "utf8"));
  const release = manifest.releases?.[args.version];
  if (
    manifest.schemaVersion !== 1
    || manifest.defaultBranch !== "main"
    || release?.sourceRevision !== args.sourceSha
    || release?.artifactName !== path.basename(args.artifact)
    || `${release.artifactName}.sha256` !== path.basename(args.checksum)
    || path.basename(args.validation) !== "plugin-release-validation.json"
  ) {
    throw new Error("Publication bundle does not match the trusted release manifest");
  }
  const artifactBody = fs.readFileSync(args.artifact);
  const artifactHash = sha256(artifactBody).slice("sha256:".length);
  const checksum = fs.readFileSync(args.checksum, "utf8");
  if (checksum !== `${artifactHash}  ${release.artifactName}\n`) {
    throw new Error("Publication checksum does not bind the exact release artifact");
  }
  const evidence = JSON.parse(fs.readFileSync(args.validation, "utf8"));
  if (
    evidence.validated !== true
    || evidence.sourceRevision !== args.sourceSha
    || evidence.version !== args.version
    || evidence.policy?.id !== manifest.policy?.id
    || evidence.policy?.revision !== args.policyRevision
    || evidence.workflowPath !== manifest.workflowPath
    || evidence.artifactName !== release.artifactName
    || evidence.artifactSha256 !== artifactHash
    || evidence.checksumAssetName !== `${release.artifactName}.sha256`
    || evidence.validationJobResult !== "success"
  ) {
    throw new Error("Publication validation evidence does not match the trusted release bundle");
  }
  return release;
}

async function runCli(argv) {
  const args = parseArgs(argv);
  const release = validateBundle(args);
  await publishPluginRelease({
    repository: args.repository,
    version: args.version,
    sourceRevision: args.sourceSha,
    title: args.title,
    notes: fs.readFileSync(args.notes, "utf8"),
    assets: [
      { name: release.artifactName, path: args.artifact },
      { name: `${release.artifactName}.sha256`, path: args.checksum },
      { name: "plugin-release-validation.json", path: args.validation },
    ],
    github: createGitHubClient(args.repository),
  });
}

if (import.meta.url === pathToFileURL(process.argv[1]).href) {
  runCli(process.argv.slice(2)).catch((error) => {
    console.error(`ERROR: ${error.message}`);
    process.exit(1);
  });
}

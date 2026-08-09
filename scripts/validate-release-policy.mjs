#!/usr/bin/env node

import { execFileSync } from "node:child_process";
import fs from "node:fs";
import path from "node:path";
import { pathToFileURL } from "node:url";

const conventionalHeader =
  /^(build|chore|ci|docs|feat|fix|perf|refactor|revert|style|test)(\([^)\r\n]+\))?(!)?: .+/;

function requireFullRevision(value, label) {
  const revision = String(value || "");
  if (!/^[0-9a-f]{40}$/.test(revision)) {
    throw new Error(`${label} must be a full lowercase commit SHA`);
  }
  return revision;
}

function requireSafeRelativePath(value, label) {
  const text = String(value || "");
  if (!text || path.isAbsolute(text) || text.split(/[\\/]/).includes("..")) {
    throw new Error(`trusted release manifest has invalid ${label}`);
  }
  return text;
}

export function validateTrustedRelease({
  manifest,
  requestedVersion,
  requestedSourceRevision,
  workflowRef,
  defaultBranch,
  workflowPath,
  policyPath,
}) {
  if (
    !manifest
    || manifest.schemaVersion !== 1
    || manifest.defaultBranch !== "main"
    || defaultBranch !== manifest.defaultBranch
    || workflowRef !== `refs/heads/${manifest.defaultBranch}`
  ) {
    throw new Error("Release must run from the protected default branch in the trusted release manifest");
  }
  if (
    manifest.workflowPath !== workflowPath
    || manifest.policy?.path !== policyPath
    || !/^[a-z0-9][a-z0-9-]*$/.test(String(manifest.policy?.id || ""))
  ) {
    throw new Error("Workflow or policy does not match the trusted release manifest");
  }
  const release = manifest.releases?.[requestedVersion];
  if (
    !/^\d+\.\d+\.\d+$/.test(String(requestedVersion || ""))
    || !release
    || release.sourceRevision !== requestedSourceRevision
    || !/^[0-9a-f]{40}$/.test(String(release.sourceRevision || ""))
    || release.artifactName !== `${release.archiveRoot}_v${requestedVersion}.zip`
    || release.changelogHeading?.includes(requestedVersion) !== true
  ) {
    throw new Error("Requested source or version does not match the trusted release manifest");
  }
  for (const field of [
    "artifactName",
    "archiveRoot",
    "prepareScript",
    "changelogFile",
    "releaseNotesFile",
  ]) {
    requireSafeRelativePath(release[field], `releases.${requestedVersion}.${field}`);
  }
  return release;
}

function parseVersion(value, label) {
  const match = /^(\d+)\.(\d+)\.(\d+)$/.exec(String(value || ""));
  if (!match) {
    throw new Error(`${label} must use x.y.z SemVer`);
  }
  return {
    text: match[0],
    major: Number(match[1]),
    minor: Number(match[2]),
    patch: Number(match[3]),
  };
}

function nextVersion(version, bump) {
  if (bump === "major") {
    return `${version.major + 1}.0.0`;
  }
  if (bump === "minor") {
    return `${version.major}.${version.minor + 1}.0`;
  }
  return `${version.major}.${version.minor}.${version.patch + 1}`;
}

function commitBump(commit) {
  const message = String(commit?.message || "");
  const header = message.split(/\r?\n/, 1)[0];
  const match = conventionalHeader.exec(header);
  if (!match) {
    throw new Error(`Commit ${commit?.sha || "<unknown>"} must use a valid Conventional Commit message`);
  }
  if (match[3] === "!" || /(?:^|\r?\n)BREAKING CHANGE: [^\r\n]+/.test(message)) {
    return "major";
  }
  return match[1] === "feat" ? "minor" : "patch";
}

export function validateReleasePolicy({
  sourceRevision,
  checkoutRevision,
  previousTag,
  requestedVersion,
  commits,
  changelog,
  changelogHeading,
  policyId,
  policyRevision,
}) {
  const source = requireFullRevision(sourceRevision, "source revision");
  const checkout = requireFullRevision(checkoutRevision, "checked-out revision");
  if (source !== checkout) {
    throw new Error(`Source revision ${source} does not match checked-out revision ${checkout}`);
  }
  const policy = requireFullRevision(policyRevision, "policy revision");
  const current = parseVersion(String(previousTag || "").replace(/^v/, ""), "previous tag");
  const requested = parseVersion(requestedVersion, "requested version");
  if (!Array.isArray(commits) || commits.length === 0) {
    throw new Error("Release policy requires at least one commit after the previous tag");
  }

  const bumpRanks = { patch: 1, minor: 2, major: 3 };
  let requiredBump = "patch";
  for (const commit of commits) {
    requireFullRevision(commit?.sha, "commit SHA");
    const bump = commitBump(commit);
    if (bumpRanks[bump] > bumpRanks[requiredBump]) {
      requiredBump = bump;
    }
  }

  const expected = nextVersion(current, requiredBump);
  if (requested.text !== expected) {
    throw new Error(
      `Requested version ${requested.text} must equal calculated ${requiredBump} version ${expected}`,
    );
  }
  if (typeof changelogHeading !== "string" || !changelogHeading.trim()) {
    throw new Error("Release policy requires an exact changelog heading");
  }
  if (!String(changelog || "").includes(changelogHeading)) {
    throw new Error(`Release changelog is missing ${changelogHeading}`);
  }
  if (!/^[a-z0-9][a-z0-9-]*$/.test(String(policyId || ""))) {
    throw new Error("Release policy identity is invalid");
  }

  return {
    schemaVersion: 1,
    validated: true,
    sourceRevision: source,
    version: requested.text,
    previousTag: String(previousTag),
    requiredBump,
    policy: {
      id: policyId,
      revision: policy,
    },
  };
}

export function parseCliArgs(argv) {
  const args = {};
  for (let index = 0; index < argv.length; index += 2) {
    const key = argv[index];
    const value = argv[index + 1];
    if (!key?.startsWith("--") || value === undefined) {
      throw new Error(`Invalid argument ${key || "<missing>"}`);
    }
    const name = key
      .slice(2)
      .replace(/-([a-z])/g, (_, letter) => letter.toUpperCase());
    args[name] = value;
  }
  return args;
}

function git(repository, args) {
  return execFileSync("git", ["-C", repository, ...args], {
    encoding: "utf8",
    stdio: ["ignore", "pipe", "pipe"],
  }).trim();
}

function runCli(argv) {
  const args = parseCliArgs(argv);
  const repository = args.repository;
  if (!repository) {
    throw new Error("--repository is required");
  }
  const sourceRevision = requireFullRevision(args.sourceSha, "source revision");
  const manifest = JSON.parse(fs.readFileSync(args.releaseManifest, "utf8"));
  const trustedRelease = validateTrustedRelease({
    manifest,
    requestedVersion: args.version,
    requestedSourceRevision: sourceRevision,
    workflowRef: args.workflowRef,
    defaultBranch: args.defaultBranch,
    workflowPath: args.workflowPath,
    policyPath: args.policyPath,
  });
  git(repository, ["cat-file", "-e", `${sourceRevision}^{commit}`]);
  const checkoutRevision = git(repository, ["rev-parse", "HEAD"]);
  const tags = git(repository, [
    "tag",
    "--merged",
    sourceRevision,
    "--sort=-version:refname",
  ]).split(/\r?\n/).filter(Boolean);
  const previousTag = tags.find(
    (tag) => /^v?\d+\.\d+\.\d+$/.test(tag) && tag.replace(/^v/, "") !== args.version,
  );
  if (!previousTag) {
    throw new Error("Release policy could not find a previous immutable SemVer tag");
  }

  const shas = git(repository, [
    "log",
    `${previousTag}..${sourceRevision}`,
    "--format=%H",
  ]).split(/\r?\n/).filter(Boolean);
  const commits = shas.map((sha) => ({
    sha,
    message: git(repository, ["show", "-s", "--format=%B", sha]),
  }));
  const changelog = fs.readFileSync(
    path.join(repository, trustedRelease.changelogFile),
    "utf8",
  );
  const evidence = validateReleasePolicy({
    sourceRevision,
    checkoutRevision,
    previousTag,
    requestedVersion: args.version,
    commits,
    changelog,
    changelogHeading: trustedRelease.changelogHeading,
    policyId: manifest.policy.id,
    policyRevision: args.policyRevision,
  });
  evidence.workflowPath = manifest.workflowPath;
  evidence.policyPath = manifest.policy.path;
  evidence.package = {
    artifactName: trustedRelease.artifactName,
    archiveRoot: trustedRelease.archiveRoot,
    prepareScript: trustedRelease.prepareScript,
    releaseNotesFile: trustedRelease.releaseNotesFile,
  };
  fs.writeFileSync(args.output, `${JSON.stringify(evidence, null, 2)}\n`, {
    flag: "wx",
  });
  process.stdout.write(
    `Validated ${evidence.requiredBump} release ${evidence.version} at ${evidence.sourceRevision}\n`,
  );
}

if (import.meta.url === pathToFileURL(process.argv[1]).href) {
  try {
    runCli(process.argv.slice(2));
  } catch (error) {
    console.error(`ERROR: ${error.message}`);
    process.exit(1);
  }
}

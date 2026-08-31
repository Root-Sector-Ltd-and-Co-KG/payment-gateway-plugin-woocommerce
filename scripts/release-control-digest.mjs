#!/usr/bin/env node

import { createHash } from "node:crypto";
import fs from "node:fs";
import path from "node:path";

export const RELEASE_CONTROL_FILES = Object.freeze([
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

function sha256(body) {
  return `sha256:${createHash("sha256").update(body).digest("hex")}`;
}

export function calculateReleaseControlBundleFromContents(contentsByPath) {
  const indexed = contentsByPath instanceof Map
    ? contentsByPath
    : new Map(Object.entries(contentsByPath || {}));
  const fileDigests = RELEASE_CONTROL_FILES.map((filePath) => {
    if (!indexed.has(filePath)) {
      throw new Error(`Missing release-control file ${filePath}`);
    }
    return {
      path: filePath,
      digest: sha256(Buffer.from(indexed.get(filePath))),
    };
  });
  const canonical = JSON.stringify({
    schemaVersion: 1,
    algorithm: "sha256",
    files: fileDigests,
  });
  return {
    schemaVersion: 1,
    algorithm: "sha256",
    files: [...RELEASE_CONTROL_FILES],
    digest: sha256(Buffer.from(canonical)),
  };
}

export function calculateReleaseControlBundle(repositoryRoot) {
  const contents = new Map();
  for (const filePath of RELEASE_CONTROL_FILES) {
    try {
      contents.set(filePath, fs.readFileSync(path.join(repositoryRoot, filePath)));
    } catch (error) {
      if (error?.code === "ENOENT") {
        throw new Error(`Missing release-control file ${filePath}`);
      }
      throw error;
    }
  }
  return calculateReleaseControlBundleFromContents(contents);
}

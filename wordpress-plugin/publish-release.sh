#!/usr/bin/env bash
# Release step of the GitLab pipeline: builds the plugin ZIP for a tag and publishes it.
#
#   wordpress-plugin/publish-release.sh
#
# Uses the GitLab CI variables CI_COMMIT_TAG, CI_API_V4_URL, CI_PROJECT_ID and CI_JOB_TOKEN.
# DRY_RUN=1 builds and checks everything but uploads nothing (for local tests; TAG=v3.1.0 stands in for CI_COMMIT_TAG).
#
# Publishes:
#   - ondisos-<version>.zip and .sha256 in the project's Generic Package Registry (package "ondisos-plugin")
#   - a GitLab release for the tag that links both files
# Both are readable without a login if the project is public. The tag must be v<plugin version> (e.g. v3.1.0).
# The release links carry fixed file paths, so the newest ZIP has a stable address (GitLab 15.9+):
#   <project>/-/releases/permalink/latest/downloads/ondisos-plugin.zip   (and ondisos-plugin.zip.sha256)
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

TAG="${CI_COMMIT_TAG:-${TAG:-}}"
if [[ ! "$TAG" =~ ^v([0-9]+\.[0-9]+\.[0-9]+)$ ]]; then
    echo "Tag '$TAG' is not a release tag (expected vMAJOR.MINOR.PATCH)" >&2
    exit 1
fi
VERSION="${BASH_REMATCH[1]}"

HEADER_VERSION="$(sed -n 's/^ \* Version: *//p' wordpress-plugin/ondisos.php | head -1 | tr -d '[:space:]')"
if [ "$HEADER_VERSION" != "$VERSION" ]; then
    echo "Tag $TAG does not match the plugin version $HEADER_VERSION in wordpress-plugin/ondisos.php" >&2
    exit 1
fi

OUT="$ROOT/dist"
./wordpress-plugin/build-zip.sh "$OUT"
ZIP="ondisos-$VERSION.zip"
(cd "$OUT" && { sha256sum -c "$ZIP.sha256" 2>/dev/null || shasum -a 256 -c "$ZIP.sha256"; })

if [ "${DRY_RUN:-0}" = "1" ]; then
    echo "DRY_RUN: would publish $ZIP and $ZIP.sha256 for $TAG"
    exit 0
fi

: "${CI_API_V4_URL:?}" "${CI_PROJECT_ID:?}" "${CI_JOB_TOKEN:?}"
PKG="$CI_API_V4_URL/projects/$CI_PROJECT_ID/packages/generic/ondisos-plugin/$VERSION"

for f in "$ZIP" "$ZIP.sha256"; do
    curl --fail --silent --show-error --header "JOB-TOKEN: $CI_JOB_TOKEN" --upload-file "$OUT/$f" "$PKG/$f" >/dev/null
    echo "Uploaded $f"
done

# Release for the tag (the tag message becomes the description)
BODY="$(jq -n \
    --arg tag "$TAG" \
    --arg name "ondisos $VERSION" \
    --arg desc "${CI_COMMIT_TAG_MESSAGE:-WordPress plugin $VERSION}" \
    --arg zip "$PKG/$ZIP" --arg sha "$PKG/$ZIP.sha256" \
    '{tag_name:$tag, name:$name, description:$desc,
      assets:{links:[{name:"WordPress plugin (ZIP)", url:$zip, link_type:"package", direct_asset_path:"/ondisos-plugin.zip"},
                     {name:"SHA-256 checksum", url:$sha, link_type:"other", direct_asset_path:"/ondisos-plugin.zip.sha256"}]}}')"

curl --fail --silent --show-error --request POST \
    --header "JOB-TOKEN: $CI_JOB_TOKEN" --header "Content-Type: application/json" \
    --data "$BODY" "$CI_API_V4_URL/projects/$CI_PROJECT_ID/releases" >/dev/null
echo "Release $TAG created: $PKG/$ZIP"

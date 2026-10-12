#!/bin/sh
# Copies the SurveyJS runtime files the preview needs from the frontend into backend/public/assets/preview/.
# The backend deployment cannot see frontend/, so the preview carries its own copies; a unit test
# (PreviewAssetsTest) fails if they differ from the frontend's files. Run this after updating SurveyJS in the frontend.
set -eu
cd "$(dirname "$0")/.."
SRC=../frontend/public
DST=public/assets/preview

cp "$SRC/assets/survey.core.min.js"            "$DST/survey.core.min.js"
cp "$SRC/assets/survey-js-ui.min.js"           "$DST/survey-js-ui.min.js"
cp "$SRC/assets/survey-core.min.css"           "$DST/survey-core.min.css"
mkdir -p "$DST/i18n"
cp "$SRC/assets/i18n/german.min.js"            "$DST/i18n/german.min.js"
cp "$SRC/js/survey-handler-base.js"            "$DST/survey-handler-base.js"
cp "$SRC/assets/fonts/opensans/"*.woff2        "$DST/fonts/"
echo "Preview assets updated from $SRC"

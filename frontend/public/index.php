<?php
// frontend/public/index.php

declare(strict_types=1);

require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../inc/bootstrap.php';

use Frontend\Config\FormConfig;
use Frontend\Utils\CsrfProtection;

// Get form key from request
$formKey = $_REQUEST['form'] ?? '';

// Validate form exists
if (empty($formKey) || !FormConfig::exists($formKey)) {
    http_response_code(404);
    ?>
    <!DOCTYPE html>
    <html lang="de">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Formular nicht gefunden</title>
        <style>
            body { font-family: Arial, sans-serif; text-align: center; padding: 50px; }
            .error { color: #dc3545; }
        </style>
    </head>
    <body>
        <div class="error">
            <h1>404 - Formular nicht gefunden</h1>
            <p>Das angeforderte Formular existiert nicht.</p>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// Load form configuration
$formConfig = FormConfig::get($formKey);
$formPath = FormConfig::getFormPath($formKey);
$themePath = FormConfig::getThemePath($formKey);

// Load survey JSON
if (!file_exists($formPath)) {
    http_response_code(500);
    die('Survey definition not found: ' . htmlspecialchars($formConfig['form']));
}

$surveyJson = file_get_contents($formPath);

// Load theme JSON (optional)
$themeJson = '{}';
if (file_exists($themePath)) {
    $themeJson = file_get_contents($themePath);
}

// Generate CSRF token
$csrfToken = CsrfProtection::getToken();
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($formKey) ?> - Anmeldung</title>
    
    <!-- SurveyJS Core -->
    <link href="assets/survey-core.fontless.min.css" rel="stylesheet">
    <script src="assets/survey.core.min.js"></script>
    <script src="assets/survey-js-ui.min.js"></script>
    
    <!-- Custom Fonts (DSGVO-konform) -->
    <style>
        @font-face {
            font-family: 'Open Sans';
            font-weight: 400;
            font-style: normal;
            src: url('assets/fonts/opensans/open-sans-v44-latin-regular.woff2') format('woff2');
        }

        @font-face {
            font-family: 'Open Sans';
            font-weight: 700;
            font-style: normal;
            src: url('assets/fonts/opensans/open-sans-v44-latin-700.woff2') format('woff2');
        }

        :root {
            --font-family: 'Open Sans', sans-serif !important;
        }

        body {
            font-family: var(--font-family);
            margin: 0;
            padding: 20px;
            background-color: #f5f5f5;
        }

        #surveyContainer {
            margin: 0 auto;
            max-width: 900px;
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
    </style>
</head>
<body>
    <div id="surveyContainer"></div>

    <!-- Survey Handler -->
    <script src="js/survey-handler.js"></script>
    
    <script>
        // Configure survey
        window.surveyConfig = {
            formKey: <?= json_encode($formKey) ?>,
            version: '2026-01-v3',
            containerId: 'surveyContainer',
            surveyJson: <?= $surveyJson ?>,
            themeJson: <?= $themeJson ?>
        };
    </script>
</body>
</html>
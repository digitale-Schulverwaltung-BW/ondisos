<?php
/**
 * Signature on a smartphone (3.2.0a)
 *
 * Two admin-ajax endpoints, both also for visitors (nopriv):
 * - admin-ajax.php?action=ondisos_sig   Relay to the backend (POST JSON {"action": create|info|submit|poll, …});
 *                                       create and poll need the nonce of the form page, info and submit come from the
 *                                       phone and are authorized by the write token from the QR code.
 * - admin-ajax.php?action=ondisos_sign  Phone page (GET); session id and write token stay in the URL fragment.
 *
 * The logic is shared with the standalone frontend (Frontend\Services\SignatureRelay); this class only adds
 * the WordPress parts (nonce, URLs, output).
 *
 * @package Ondisos
 */

declare(strict_types=1);

namespace Ondisos;

use Frontend\Config\SurveySource;
use Frontend\Services\BackendApiClient;
use Frontend\Services\MessageService;
use Frontend\Services\SignatureRelay;
use Frontend\Utils\ContentSecurityPolicy;

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Relay and phone page of the signature handoff
 */
class Signature_Handler
{
    /** Nonce action of create/poll (one nonce for all forms on a page). */
    public const NONCE_ACTION = 'ondisos_signature';

    public function __construct()
    {
        add_action('wp_ajax_ondisos_sig', [$this, 'handle_relay']);
        add_action('wp_ajax_nopriv_ondisos_sig', [$this, 'handle_relay']);
        add_action('wp_ajax_ondisos_sign', [$this, 'handle_sign_page']);
        add_action('wp_ajax_nopriv_ondisos_sign', [$this, 'handle_sign_page']);
    }

    /** URL of the relay for the browser. */
    public static function relay_url(): string
    {
        return admin_url('admin-ajax.php?action=ondisos_sig');
    }

    /** URL of the phone page; the backend appends #s=…&t=… for the QR code. */
    public static function sign_url(): string
    {
        return admin_url('admin-ajax.php?action=ondisos_sign');
    }

    /** Texts of the phone page and the dialog (with local overrides from messages.local.php). */
    public static function messages(): array
    {
        $all = MessageService::getAll();

        return is_array($all['signature'] ?? null) ? $all['signature'] : [];
    }

    /**
     * Relay: admin-ajax.php?action=ondisos_sig
     */
    public function handle_relay(): void
    {
        nocache_headers();
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            wp_send_json(['ok' => false, 'error' => 'method'], 405);
        }

        $raw = (string)file_get_contents('php://input', false, null, 0, SignatureRelay::MAX_BODY_BYTES + 1);
        $input = strlen($raw) <= SignatureRelay::MAX_BODY_BYTES ? json_decode($raw, true) : null;
        if (!is_array($input)) {
            wp_send_json(['ok' => false, 'error' => 'invalid_input'], 400);
        }

        $csrf_valid = in_array($input['action'] ?? null, SignatureRelay::ACTIONS_NEEDING_CSRF, true)
            && is_string($input['nonce'] ?? null)
            && wp_verify_nonce($input['nonce'], self::NONCE_ACTION) !== false;

        $form_title = static function (string $form_key): string {
            try {
                return Form_Config_Loader::ensure_with_survey($form_key)
                    ? SignatureRelay::surveyTitle(SurveySource::survey($form_key))
                    : '';
            } catch (\RuntimeException) {
                return '';
            }
        };

        $relay = new SignatureRelay(new BackendApiClient(), $form_title);
        $result = $relay->handle($input, $csrf_valid, self::sign_url());

        wp_send_json($result['body'], $result['code']);
    }

    /**
     * Phone page: admin-ajax.php?action=ondisos_sign (same markup as frontend/public/sign.php)
     */
    public function handle_sign_page(): void
    {
        $messages = self::messages();
        $t = static fn(string $key): string => esc_html((string)($messages[$key] ?? $key));
        $assets = Plugin::get_instance()->get_assets();
        $version = rawurlencode(ONDISOS_PLUGIN_VERSION);

        nocache_headers();
        header('Content-Type: text/html; charset=utf-8');
        header('Referrer-Policy: no-referrer');
        header('X-Robots-Tag: noindex, nofollow');
        // The page belongs to the plugin alone (no theme), so it gets the CSP of the standalone form
        ContentSecurityPolicy::send();
        ?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?php echo $t('page_title'); ?></title>
    <link href="<?php echo esc_url($assets->frontend_assets_url() . 'survey-core.fontless.min.css?v=' . $version); ?>" rel="stylesheet">
    <script src="<?php echo esc_url($assets->frontend_assets_url() . 'survey.core.min.js?v=' . $version); ?>"></script>
    <script src="<?php echo esc_url($assets->frontend_assets_url() . 'survey-js-ui.min.js?v=' . $version); ?>"></script>
    <link href="<?php echo esc_url($assets->frontend_assets_url() . 'sign-page.css?v=' . $version); ?>" rel="stylesheet">
</head>
<body>
    <main class="sign">
        <header class="sign__head">
            <div class="sign__school" id="signSchool"></div>
            <h1 class="sign__form" id="signForm"><?php echo $t('page_title'); ?></h1>
            <div class="sign__field" id="signField"></div>
        </header>

        <div class="sign__bar" id="signBar" hidden>
            <span class="sign__countdown" id="signCountdown"></span>
            <button type="button" class="sign__landscape" id="signLandscape" hidden><?php echo $t('landscape_button'); ?></button>
        </div>
        <p class="sign__rotate" id="signRotate" hidden><?php echo $t('rotate_hint'); ?></p>

        <div class="sign__padwrap"><div id="signPad"></div></div>
        <button type="button" class="sign__send" id="signSend" hidden><?php echo $t('send'); ?></button>

        <p class="sign__status" id="signStatus" role="status" aria-live="polite"><?php echo $t('loading'); ?></p>
    </main>

    <script src="<?php echo esc_url($assets->frontend_js_url() . 'sign-page.js?v=' . $version); ?>"></script>
    <script nonce="<?php echo esc_attr(ContentSecurityPolicy::nonce()); ?>">
        SignPage.start({
            endpoint: <?php echo wp_json_encode(self::relay_url()); ?>,
            messages: <?php echo wp_json_encode($messages, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>
        });
    </script>
</body>
</html>
        <?php
        exit;
    }
}

/**
 * Phone page of the signature on a smartphone (3.2.0a).
 *
 * Reads session id and write token from the URL fragment (#s=…&t=…), asks the relay for the session (info),
 * shows a single SurveyJS signaturepad in the size of the field on the computer and sends the PNG (submit).
 * The countdown runs on the remaining seconds from the server, not on the phone's clock.
 *
 * Shared by the standalone frontend and the WordPress plugin: config.endpoint is the relay URL.
 */
const SignPage = (() => {
    let cfg = { endpoint: '', messages: {} };
    let id = '';
    let token = '';
    let deadline = 0;
    let timer = null;
    let survey = null;
    let ratio = 1.5;
    let finished = false;

    const el = (name) => document.getElementById(name);

    function msg(key, replacements = {}) {
        const text = cfg.messages[key] || key;
        return text.replace(/\{\{(\w+)\}\}/g, (_, name) => (name in replacements ? String(replacements[name]) : ''));
    }

    async function call(action, data = {}) {
        try {
            const response = await fetch(cfg.endpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action, id, token, ...data }),
                credentials: 'omit',
                cache: 'no-store',
            });
            const body = await response.json().catch(() => null);
            return body && typeof body === 'object' ? body : { ok: false, error: 'unavailable' };
        } catch (e) {
            return { ok: false, error: 'unavailable' };
        }
    }

    function errorText(code) {
        const known = ['expired', 'used', 'invalid', 'bad_image', 'rate_limit'];
        return msg('error_' + (known.includes(code) ? code : 'unavailable'));
    }

    function setStatus(text, kind = '') {
        const status = el('signStatus');
        status.textContent = text;
        status.className = 'sign__status' + (kind ? ' sign__status--' + kind : '');
    }

    /** Final state without the pad: expired, used, invalid link. */
    function stop(code) {
        finished = true;
        clearInterval(timer);
        document.body.classList.remove('sign-active');
        el('signPad').hidden = true;
        el('signSend').hidden = true;
        el('signBar').hidden = true;
        setStatus(errorText(code), 'error');
    }

    function tick() {
        const remaining = Math.max(0, Math.round((deadline - Date.now()) / 1000));
        const minutes = Math.floor(remaining / 60);
        const seconds = String(remaining % 60).padStart(2, '0');
        const countdown = el('signCountdown');
        countdown.textContent = msg('valid_for', { time: minutes + ':' + seconds });
        countdown.classList.toggle('sign__countdown--urgent', remaining <= 60);
        if (remaining === 0 && !finished) {
            stop('expired');
        }
    }

    /**
     * Width of the pad: the full width, but only so tall that the pad itself fits on the screen,
     * keeping the aspect ratio of the field on the computer. Header and button may scroll away:
     * in landscape (Safari's bars take much of the height) the drawing area gets the space.
     */
    function fitPad() {
        const wrap = document.querySelector('.sign__padwrap');
        const canvas = wrap.querySelector('canvas');
        if (!canvas || !canvas.offsetHeight) {
            return;
        }
        const bar = el('signBar');
        const landscape = window.matchMedia('(orientation: landscape)').matches;
        const frameWidth = wrap.offsetWidth - canvas.offsetWidth;
        const frameHeight = wrap.offsetHeight - canvas.offsetHeight;
        // Portrait: the countdown line sits above the pad. Landscape: countdown and ✅ sit beside it (CSS grid)
        const sideWidth = landscape ? Math.max(bar.offsetWidth, el('signSend').offsetWidth) + 12 : 0;
        const available = window.innerHeight - frameHeight - (landscape ? 0 : bar.offsetHeight) - 16;
        const canvasWidth = Math.max(160, available * ratio);
        const maxWidth = Math.min(document.documentElement.clientWidth - 16 - sideWidth, canvasWidth + frameWidth);
        wrap.style.maxWidth = Math.floor(maxWidth) + 'px';
        // In the landscape grid the pad column takes the pad's width, so pad and ✅ are centered together
        wrap.style.width = landscape ? Math.floor(maxWidth) + 'px' : '';
        if (landscape) {
            // Pad (with countdown and ✅ beside it) fills the screen once SurveyJS has rescaled it
            setTimeout(() => wrap.scrollIntoView({ block: 'center', behavior: 'smooth' }), 150);
        }
    }

    /**
     * SurveyJS rescales the canvas asynchronously: measure again once it has settled.
     * Only width changes count (turning the phone): iOS changes the height whenever its bars
     * collapse while scrolling, and refitting then would make the pad jump.
     */
    let fitTimer = null;
    let fittedWidth = 0;
    function scheduleFit() {
        const width = document.documentElement.clientWidth;
        if (width === fittedWidth) {
            return;
        }
        fittedWidth = width;
        clearTimeout(fitTimer);
        const wrap = document.querySelector('.sign__padwrap');
        wrap.style.maxWidth = '';
        // Landscape grid: the pad column has no width of its own, give it one until fitPad() measures
        wrap.style.width = window.matchMedia('(orientation: landscape)').matches ? (width - 140) + 'px' : '';
        fitTimer = setTimeout(fitPad, 150);
    }

    function renderPad(info) {
        ratio = info.width / info.height;
        survey = new Survey.Model({
            showQuestionNumbers: 'off',
            showNavigationButtons: false,
            showCompletedPage: false,
            elements: [{
                type: 'signaturepad',
                name: 'signature',
                titleLocation: 'hidden',
                signatureWidth: info.width,
                signatureHeight: info.height,
                signatureAutoScaleEnabled: true,
                dataFormat: 'png',
                allowClear: true,
                placeholder: msg('pad_placeholder'),
                // Same colors as the field on the computer (null = SurveyJS default)
                ...(info.pen_color ? { penColor: info.pen_color } : {}),
                ...(info.background_color ? { backgroundColor: info.background_color } : {}),
            }],
        });
        survey.render(el('signPad'));
        scheduleFit();
        window.addEventListener('resize', scheduleFit);
    }

    async function send() {
        const png = survey ? survey.getValue('signature') : '';
        if (!png) {
            setStatus(msg('empty'), 'error');
            return;
        }

        const button = el('signSend');
        button.disabled = true;
        setStatus(msg('sending'));
        const result = await call('submit', { png });

        if (result.ok) {
            finished = true;
            clearInterval(timer);
            document.body.classList.remove('sign-active');
            el('signPad').hidden = true;
            button.hidden = true;
            el('signBar').hidden = true;
            el('signForm').textContent = msg('done_title');
            setStatus(msg('done_text'), 'done');
            return;
        }
        if (['bad_image', 'rate_limit', 'unavailable'].includes(result.error)) {
            button.disabled = false;
            setStatus(errorText(result.error), 'error');
            return;
        }
        stop(result.error);
    }

    /** Android: full screen and landscape lock on request; browsers without it (iOS) never show the button. */
    function setupLandscapeButton() {
        const button = el('signLandscape');
        const root = document.documentElement;
        if (!root.requestFullscreen || !screen.orientation || typeof screen.orientation.lock !== 'function') {
            return;
        }
        button.hidden = false;
        button.addEventListener('click', async () => {
            try {
                await root.requestFullscreen();
                await screen.orientation.lock('landscape');
            } catch (e) {
                button.hidden = true;
            }
        });
    }

    async function start(config) {
        cfg = config;
        // A new QR code opened in the same tab only changes the fragment: start over with it
        window.addEventListener('hashchange', () => window.location.reload());
        const params = new URLSearchParams(window.location.hash.slice(1));
        id = params.get('s') || '';
        token = params.get('t') || '';
        if (!/^[a-f0-9]{32}$/.test(id) || !/^[a-f0-9]{64}$/.test(token)) {
            stop('invalid');
            return;
        }

        const info = await call('info');
        if (!info.ok) {
            stop(info.error);
            return;
        }

        el('signSchool').textContent = info.school || '';
        el('signForm').textContent = info.form_title || msg('page_title');
        el('signField').textContent = msg('for_field', { field: info.field_title || '' });
        document.title = msg('page_title') + (info.form_title ? ' – ' + info.form_title : '');

        deadline = Date.now() + Math.max(0, Number(info.expires_in) || 0) * 1000;
        el('signBar').hidden = false;
        el('signSend').hidden = false;
        el('signSend').addEventListener('click', send);
        document.body.classList.add('sign-active');
        setupLandscapeButton();
        setStatus('');
        renderPad(info);
        tick();
        timer = setInterval(tick, 1000);
    }

    return { start };
})();

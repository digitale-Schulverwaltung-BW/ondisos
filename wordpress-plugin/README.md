# Anmeldung Forms - WordPress Plugin

WordPress integration wrapper for the Anmeldung Forms system using **symlink architecture** for seamless git-based updates.

## Quick Start

```bash
# 1. Create TWO symlinks in WordPress plugins directory
cd /var/www/html/wp-content/plugins/
ln -s /path/to/ondisos/wordpress-plugin anmeldung-forms
ln -s /path/to/ondisos/frontend anmeldung-forms-frontend

# 2. Activate plugin in WordPress Admin
# 3. Configure settings (Settings → Anmeldung Forms)
# 4. Use shortcode in pages: [anmeldung form="bs"]
```

## Features

- ✅ **SurveyJS Integration** - Full SurveyJS form builder support
- ✅ **Symlink Architecture** - Update via `git pull` without file copying
- ✅ **Multiple Forms** - Support for multiple forms via shortcode parameter
- ✅ **DSGVO Compliant** - Local fonts, no external CDN requests
- ✅ **CSRF Protection** - WordPress nonce-based security
- ✅ **File Uploads** - Integrated file upload handling
- ✅ **Prefill Support** - Pre-populate forms for repeat submissions
- ✅ **Backend Integration** - Seamless API communication with backend
- ✅ **Type-Safe PHP** - PHP 8.1+ with strict types
- ✅ **Clean Architecture** - MVC pattern with service layer

## Requirements

- WordPress 5.8+
- PHP 8.1+
- Apache/Nginx with symlink support
- Git repository access

## Installation

See **[INSTALL.md](INSTALL.md)** for detailed installation instructions.

## Usage

### Basic Shortcode

```
[anmeldung form="bs"]
```

### With Prefill (via URL)

```
https://yoursite.com/page/?prefill=base64_encoded_json
```

The form parameter is **mandatory**. Available forms are configured in `frontend/config/forms-config.php`.

## Directory Structure

```
wordpress-plugin/
├── anmeldung-forms.php          # Main plugin file
├── readme.txt                    # WordPress.org format readme
├── uninstall.php                 # Cleanup script
├── INSTALL.md                    # Installation guide
├── README.md                     # This file
├── includes/                     # PHP classes
│   ├── class-plugin.php         # Core orchestrator
│   ├── class-autoloader.php     # PSR-4 autoloader
│   ├── class-shortcode.php      # [anmeldung] handler
│   ├── class-ajax-handler.php   # AJAX endpoints
│   ├── class-assets.php         # Asset enqueuing
│   └── class-settings.php       # Settings page
└── assets/                       # Frontend assets
    ├── js/
    │   └── survey-handler-wp.js # WordPress-adapted JS handler
    └── css/
        └── anmeldung.css        # Custom styles
```

## Architecture

### Namespace Strategy

The plugin supports **two namespaces** via custom autoloader:

1. **`Anmeldung_Forms\*`** - WordPress plugin classes (wordpress-plugin/includes/)
2. **`Frontend\*`** - Shared frontend services (frontend/src/) - **Reused without modification!**

This allows the plugin to leverage existing frontend code without duplication.

### Key Differences from Standalone Frontend

| Feature | Standalone | WordPress |
|---------|-----------|-----------|
| CSRF | Session tokens | WP nonces |
| Submit URL | save.php | admin-ajax.php |
| Config | Global object | Data attributes |
| Initialization | Single form | Multiple forms |

### Data Flow

```
User fills form
    ↓
JavaScript collects data
    ↓
POST to admin-ajax.php (action: anmeldung_submit)
    ↓
Ajax_Handler validates nonce
    ↓
AnmeldungService processes (REUSED from frontend/)
    ↓
BackendApiClient submits to backend API
    ↓
EmailService sends notification
    ↓
Success response with prefill link
```

## Configuration

### WordPress Settings

Settings → Anmeldung Forms:

- **Backend API URL** - Overrides .env value
- **From Email** - Overrides .env value
- **Available Forms** - Lists all configured forms with shortcodes

### Configuration Priority

1. **WordPress Options** (highest) - from Settings page
2. **.env file** - from frontend/.env
3. **Hardcoded defaults** (lowest)

## Development

### Making Changes

```bash
# Navigate to repository
cd /path/to/ondisos/

# Make changes to wordpress-plugin/ or frontend/
vim wordpress-plugin/includes/class-shortcode.php

# Commit and push
git add .
git commit -m "Update feature"
git push origin main

# On production server
cd /path/to/ondisos/
git pull origin main
# Changes are immediately live in WordPress!
```

### Adding New Features

1. Create class in `includes/class-your-feature.php`
2. Namespace: `Anmeldung_Forms\Your_Feature`
3. Initialize in `class-plugin.php`
4. Autoloader will handle loading

### Debugging

Enable WordPress debugging in `wp-config.php`:

```php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);
```

Check logs:

```bash
tail -f /var/www/html/wp-content/debug.log
```

## WordPress Hooks & Filters

### Actions

- `anmeldung_enqueue_assets` - Fired when shortcode is rendered, used to enqueue scripts

### AJAX Endpoints

- `wp_ajax_anmeldung_submit` - Handle form submission (logged in users)
- `wp_ajax_nopriv_anmeldung_submit` - Handle form submission (public users)

## Security

- ✅ CSRF via WordPress nonces (user-bound, time-limited)
- ✅ XSS prevention via `esc_html()`, `esc_attr()`, `esc_url()`
- ✅ SQL injection N/A (no WordPress DB queries)
- ✅ File upload validation in backend
- ✅ Nonce verification on all AJAX requests
- ✅ Sanitization of all user inputs

## Testing Checklist

After installation:

- [ ] Plugin activates without errors
- [ ] Settings page loads
- [ ] Forms are listed with shortcodes
- [ ] Shortcode renders form on page
- [ ] Assets load correctly (check browser console)
- [ ] Fonts display (no external requests)
- [ ] Form submission works
- [ ] Backend receives data
- [ ] Email notifications sent
- [ ] Prefill link works
- [ ] Multiple forms on same page work
- [ ] Error messages display correctly

## Troubleshooting

### Common Issues

**Plugin not visible in WordPress:**
- Verify symlink exists and points to correct directory
- Check main plugin file has WordPress header comment
- Ensure web server follows symlinks

**Assets not loading (404):**
- Verify frontend directory exists and is readable
- Check permissions (755 for dirs, 644 for files)
- Test asset URL directly in browser

**Form not rendering:**
- Check browser console for JavaScript errors
- Verify form exists in forms-config.php
- Check survey JSON files are valid

**Submission fails:**
- Check browser Network tab for AJAX response
- Verify backend API URL in settings
- Check WordPress debug.log for errors
- Test backend API directly with curl

See **[INSTALL.md](INSTALL.md)** for detailed troubleshooting.

## Updates

To update the plugin:

```bash
cd /path/to/ondisos/
git pull origin main
```

No WordPress restart needed! Changes are immediately available.

Optional: Clear WordPress cache if using caching plugin.

## Uninstalling

1. Deactivate plugin in WordPress
2. Delete plugin (runs uninstall.php)
3. Remove symlink: `rm /var/www/html/wp-content/plugins/anmeldung-forms`

## Contributing

1. Fork repository
2. Create feature branch
3. Make changes
4. Test in WordPress
5. Submit pull request

## License

GPL v2 or later

## Support

- **Issues:** https://github.com/yourusername/ondisos/issues
- **Documentation:** See CLAUDE.md in repository root
- **Installation Guide:** See INSTALL.md in this directory

## Credits

- **SurveyJS:** https://surveyjs.io/
- **WordPress:** https://wordpress.org/
- **Open Sans Font:** https://fonts.google.com/specimen/Open+Sans

---

**Version:** 2.0.0
**Author:** Your Name
**Last Updated:** January 2026

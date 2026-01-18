# WordPress Plugin Installation Guide

## Overview

This WordPress plugin is designed to be installed via **symlink** to enable seamless updates via `git pull`. This approach allows the plugin to stay in sync with the git repository without manually copying files.

## Prerequisites

- WordPress 5.8 or higher
- PHP 8.1 or higher
- Apache/Nginx with symlink support enabled
- Git repository cloned on the server

## Installation Steps

### 1. Clone or Update Repository

If you haven't already cloned the repository:

```bash
# Clone repository
cd /path/to/your/projects/
git clone https://github.com/yourusername/ondisos.git
cd ondisos
```

If repository already exists, ensure it's up to date:

```bash
cd /path/to/your/projects/ondisos
git pull origin main
```

### 2. Verify Directory Structure

Ensure your repository has this structure:

```
/path/to/your/projects/ondisos/
├── frontend/              # Existing frontend code
├── backend/               # Existing backend code
└── wordpress-plugin/      # NEW: WordPress wrapper (this directory)
    ├── anmeldung-forms.php
    ├── includes/
    ├── assets/
    └── ...
```

### 3. Create Symlink

Navigate to your WordPress plugins directory and create the symlink:

```bash
# Navigate to WordPress plugins directory
cd /var/www/html/wp-content/plugins/

# Create symlink (adjust source path to your repository location)
ln -s /path/to/your/projects/ondisos/wordpress-plugin anmeldung-forms

# Verify symlink was created
ls -la anmeldung-forms
# Should show: anmeldung-forms -> /path/to/your/projects/ondisos/wordpress-plugin
```

**Important:** Use **absolute paths** for the symlink source, not relative paths.

### 4. Set Permissions

Ensure proper permissions:

```bash
# Set directory permissions
find /path/to/your/projects/ondisos/wordpress-plugin -type d -exec chmod 755 {} \;

# Set file permissions
find /path/to/your/projects/ondisos/wordpress-plugin -type f -exec chmod 644 {} \;

# Also set permissions for frontend (needed for assets)
find /path/to/your/projects/ondisos/frontend -type d -exec chmod 755 {} \;
find /path/to/your/projects/ondisos/frontend -type f -exec chmod 644 {} \;
```

### 5. Configure Apache (if applicable)

Ensure Apache is configured to follow symlinks.

**Method 1: Via .htaccess** (if AllowOverride is enabled)

Create/edit `/var/www/html/.htaccess`:

```apache
Options +FollowSymLinks
```

**Method 2: Via VirtualHost configuration** (recommended)

Edit your Apache VirtualHost configuration:

```apache
<VirtualHost *:80>
    ServerName yoursite.com
    DocumentRoot /var/www/html

    <Directory /var/www/html>
        Options +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

Restart Apache:

```bash
sudo systemctl restart apache2
```

### 6. Configure Nginx (if applicable)

Nginx follows symlinks by default, but ensure `disable_symlinks` is not set:

```nginx
server {
    server_name yoursite.com;
    root /var/www/html;

    # Ensure symlinks are allowed (default behavior)
    # disable_symlinks off;  # This is the default

    location ~ \.php$ {
        # Your PHP-FPM configuration
    }
}
```

Restart Nginx:

```bash
sudo systemctl restart nginx
```

### 7. Activate Plugin in WordPress

1. Log in to WordPress admin panel
2. Go to **Plugins** → **Installed Plugins**
3. Find "Anmeldung Forms"
4. Click **Activate**

### 8. Configure Plugin Settings

1. Go to **Settings** → **Anmeldung Forms**
2. Configure:
   - **Backend API URL**: URL to your backend API (e.g., `http://intranet.example.com/backend/api`)
   - **From Email**: Sender email for notifications (e.g., `noreply@example.com`)
3. Click **Save Settings**
4. Verify that available forms are listed
5. Copy shortcodes for use in pages/posts

### 9. Test Installation

1. Create a new WordPress page
2. Add the shortcode: `[anmeldung form="bs"]` (replace "bs" with your form key)
3. Preview/publish the page
4. Verify the form loads correctly
5. Submit a test form
6. Check that data is received by backend

## Verification Checklist

- [ ] Symlink created successfully (`ls -la` shows correct target)
- [ ] Plugin appears in WordPress Plugins list
- [ ] Plugin activates without errors
- [ ] Settings page accessible (Settings → Anmeldung Forms)
- [ ] Available forms are listed
- [ ] Shortcode renders form on page
- [ ] Form assets load (SurveyJS, fonts)
- [ ] Form submission works
- [ ] Backend receives data
- [ ] Email notifications sent (if configured)

## Updating the Plugin

The beauty of symlinks: updates are automatic!

```bash
# Navigate to repository
cd /path/to/your/projects/ondisos

# Pull latest changes
git pull origin main

# Changes are immediately available in WordPress (no restart needed)
# Optional: Clear WordPress cache if using caching plugin
```

## Troubleshooting

### Plugin Not Appearing in WordPress

**Issue:** Plugin doesn't show in WordPress plugins list

**Solutions:**

1. Verify symlink exists:
   ```bash
   ls -la /var/www/html/wp-content/plugins/anmeldung-forms
   ```

2. Check symlink target is correct:
   ```bash
   readlink -f /var/www/html/wp-content/plugins/anmeldung-forms
   ```

3. Verify main plugin file has header comment:
   ```bash
   head -20 /path/to/your/projects/ondisos/wordpress-plugin/anmeldung-forms.php
   ```

### Apache Forbidden Error

**Issue:** 403 Forbidden when accessing plugin files

**Solutions:**

1. Enable FollowSymLinks in Apache config (see Step 5)
2. Check permissions:
   ```bash
   ls -la /path/to/your/projects/ondisos/wordpress-plugin/
   # Directories: 755, Files: 644
   ```

3. Ensure Apache user can read files:
   ```bash
   sudo -u www-data ls /path/to/your/projects/ondisos/wordpress-plugin/
   ```

### Assets Not Loading (404)

**Issue:** SurveyJS files return 404 errors

**Solutions:**

1. Verify frontend directory exists:
   ```bash
   ls -la /path/to/your/projects/ondisos/frontend/
   ```

2. Check asset files exist:
   ```bash
   ls -la /path/to/your/projects/ondisos/frontend/public/assets/
   ```

3. Verify permissions (755 for dirs, 644 for files)

4. Check browser console for exact URL failing
5. Test URL directly in browser

### Form Not Rendering

**Issue:** Shortcode shows but form doesn't render

**Solutions:**

1. Check browser console for JavaScript errors
2. Verify form exists in `frontend/config/forms-config.php`:
   ```bash
   cat /path/to/your/projects/ondisos/frontend/config/forms-config.php
   ```

3. Check survey JSON files exist:
   ```bash
   ls -la /path/to/your/projects/ondisos/frontend/surveys/
   ```

4. Verify WordPress settings (Settings → Anmeldung Forms)

### Submission Fails

**Issue:** Form submits but shows error

**Solutions:**

1. Check browser Network tab for AJAX request/response
2. Verify backend API URL in settings
3. Check WordPress error log:
   ```bash
   tail -f /var/www/html/wp-content/debug.log
   ```

4. Test backend API directly:
   ```bash
   curl -X POST http://your-backend-url/api/submit.php
   ```

5. Verify nonce is being sent (check Network tab)

### Permission Denied Errors

**Issue:** WordPress can't read plugin files

**Solutions:**

1. Fix ownership:
   ```bash
   sudo chown -R www-data:www-data /path/to/your/projects/ondisos/wordpress-plugin/
   sudo chown -R www-data:www-data /path/to/your/projects/ondisos/frontend/
   ```

2. Or add web server user to your group:
   ```bash
   sudo usermod -a -G yourgroup www-data
   ```

3. Set group permissions:
   ```bash
   chmod -R g+r /path/to/your/projects/ondisos/
   ```

## Uninstalling

### Deactivate and Delete Plugin

1. Go to **Plugins** → **Installed Plugins**
2. **Deactivate** "Anmeldung Forms"
3. Click **Delete**
4. WordPress will run `uninstall.php` (cleans up options)

### Remove Symlink

```bash
cd /var/www/html/wp-content/plugins/
rm anmeldung-forms  # Removes symlink only, not source files
```

### Remove Source Files (Optional)

Only if you want to completely remove the git repository:

```bash
rm -rf /path/to/your/projects/ondisos/
```

## Advanced Configuration

### Using Environment Variables

The plugin loads configuration from `frontend/.env` file. You can override these values via WordPress settings.

**Priority:**

1. WordPress Options (Settings → Anmeldung Forms) - **Highest**
2. .env file
3. Hardcoded defaults - **Lowest**

### Multiple WordPress Installations

You can symlink the same plugin to multiple WordPress installations:

```bash
# WordPress Site 1
cd /var/www/site1/wp-content/plugins/
ln -s /path/to/ondisos/wordpress-plugin anmeldung-forms

# WordPress Site 2
cd /var/www/site2/wp-content/plugins/
ln -s /path/to/ondisos/wordpress-plugin anmeldung-forms

# Both sites use the same codebase!
```

### Git Hooks for Auto-Update

Create a post-receive hook to automatically pull updates:

```bash
# .git/hooks/post-receive
#!/bin/bash
cd /path/to/your/projects/ondisos
git pull origin main
```

## Security Notes

- ✅ CSRF protection via WordPress nonces
- ✅ XSS prevention via `esc_html()`, `esc_attr()`, `esc_url()`
- ✅ SQL injection prevented (no direct DB queries)
- ✅ File upload validation in backend
- ⚠️ Ensure backend API uses HTTPS in production
- ⚠️ Limit file upload sizes in backend .env

## Support

For issues and questions:

- **GitHub Issues:** https://github.com/yourusername/ondisos/issues
- **Documentation:** See CLAUDE.md in repository root

## License

GPL v2 or later

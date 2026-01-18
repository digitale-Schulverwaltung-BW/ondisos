#!/bin/bash
#
# Symlink Setup Script for WordPress Installation
#
# This script creates the necessary symlinks for the Anmeldung Forms plugin
# to work correctly with WordPress.
#
# Usage:
#   1. Edit the paths below to match your installation
#   2. Make executable: chmod +x SYMLINK-SETUP.sh
#   3. Run: sudo ./SYMLINK-SETUP.sh
#

# === CONFIGURATION ===
# Adjust these paths to match your installation

# Path to the git repository
REPO_PATH="/path/to/your/repo/ondisos"

# Path to WordPress plugins directory
WP_PLUGINS_DIR="/var/www/html/wp-content/plugins"

# === END CONFIGURATION ===

set -e  # Exit on error

echo "=== Anmeldung Forms - Symlink Setup ==="
echo ""

# Verify paths exist
if [ ! -d "$REPO_PATH" ]; then
    echo "ERROR: Repository path not found: $REPO_PATH"
    echo "Please edit this script and set REPO_PATH correctly."
    exit 1
fi

if [ ! -d "$WP_PLUGINS_DIR" ]; then
    echo "ERROR: WordPress plugins directory not found: $WP_PLUGINS_DIR"
    echo "Please edit this script and set WP_PLUGINS_DIR correctly."
    exit 1
fi

# Create symlink for wordpress-plugin directory
echo "Creating symlink: anmeldung-forms → wordpress-plugin/"
if [ -L "$WP_PLUGINS_DIR/anmeldung-forms" ]; then
    echo "  Symlink already exists, removing old one..."
    rm "$WP_PLUGINS_DIR/anmeldung-forms"
fi
ln -s "$REPO_PATH/wordpress-plugin" "$WP_PLUGINS_DIR/anmeldung-forms"
echo "  ✓ Created: $WP_PLUGINS_DIR/anmeldung-forms"

# Create symlink for frontend directory (needed for assets)
echo "Creating symlink: anmeldung-forms-frontend → frontend/"
if [ -L "$WP_PLUGINS_DIR/anmeldung-forms-frontend" ]; then
    echo "  Symlink already exists, removing old one..."
    rm "$WP_PLUGINS_DIR/anmeldung-forms-frontend"
fi
ln -s "$REPO_PATH/frontend" "$WP_PLUGINS_DIR/anmeldung-forms-frontend"
echo "  ✓ Created: $WP_PLUGINS_DIR/anmeldung-forms-frontend"

# Verify symlinks
echo ""
echo "=== Verifying symlinks ==="
ls -la "$WP_PLUGINS_DIR/anmeldung-forms"
ls -la "$WP_PLUGINS_DIR/anmeldung-forms-frontend"

# Set permissions
echo ""
echo "=== Setting permissions ==="
find "$REPO_PATH/wordpress-plugin" -type d -exec chmod 755 {} \;
find "$REPO_PATH/wordpress-plugin" -type f -exec chmod 644 {} \;
find "$REPO_PATH/frontend" -type d -exec chmod 755 {} \;
find "$REPO_PATH/frontend" -type f -exec chmod 644 {} \;
echo "  ✓ Permissions set (755 for directories, 644 for files)"

echo ""
echo "=== Setup Complete ==="
echo ""
echo "Next steps:"
echo "1. Go to WordPress Admin → Plugins"
echo "2. Activate 'Anmeldung Forms'"
echo "3. Configure Settings → Anmeldung Forms"
echo "4. Use shortcode: [anmeldung form=\"bs\"]"
echo ""

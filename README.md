# DD WP GitHub Updates

A WordPress plugin that enables automatic updates and installations of themes and plugins directly from GitHub repositories, including private repositories.

## Features

- 🔄 **Automatic Updates**: Keep your GitHub-hosted themes and plugins up-to-date automatically
- 🔒 **Private Repository Support**: Works with private GitHub repositories using personal access tokens
- 📦 **Direct Installation**: Install themes and plugins directly from GitHub without manual downloads
- 🛡️ **Secure Authentication**: Uses GitHub personal access tokens for secure API access
- 🎯 **Easy Configuration**: Simple admin interface for managing repositories
- 📊 **Connection Testing**: Built-in tools to verify GitHub API connectivity
- 🔍 **Repository Search**: Search and preview GitHub repositories before installation

## Requirements

- WordPress 5.2 or higher
- PHP 7.2 or higher
- GitHub personal access token (for private repositories)
- `wp_remote_get()` function enabled (WordPress HTTP API)

## Installation

### Method 1: Manual Installation

1. Download the plugin files
2. Upload the `dd-wp-github-updates` folder to your `/wp-content/plugins/` directory
3. Activate the plugin through the 'Plugins' menu in WordPress
4. Navigate to **GitHub Updates** in your admin menu to configure

### Method 2: Git Clone

```bash
cd /path/to/wordpress/wp-content/plugins/
git clone https://github.com/gabydevdev/dd-wp-github-updates.git
```

## Configuration

### 1. GitHub Personal Access Token

To use this plugin, you'll need a GitHub personal access token:

1. Go to [GitHub Settings > Developer settings > Personal access tokens](https://github.com/settings/tokens)
2. Click "Generate new token (classic)"
3. Give it a descriptive name (e.g., "WordPress GitHub Updates")
4. Select the following scopes:
   - **`repo`** - Full control of private repositories (required)
   - **`read:packages`** - Read packages (optional, only if using GitHub packages)
5. Click "Generate token"
6. Copy the token immediately (you won't be able to see it again)

### 2. Plugin Configuration

1. In WordPress admin, go to **GitHub Updates**
2. Paste your GitHub token in the "Personal Access Token" field
3. Click "Test Connection" to verify the token works
4. Save the settings

## Usage

### Adding Repositories for Updates

1. Go to **GitHub Updates** in your admin menu
2. Scroll down to "Add Repository"
3. Fill in the repository details:
   - **Type**: Theme or Plugin
   - **Owner**: GitHub username or organization
   - **Repository Name**: The repository name
   - **Theme Slug**: (for themes) The directory name of your theme
   - **Plugin File**: (for plugins) The main plugin file path (e.g., `my-plugin/my-plugin.php`)

### Installing from GitHub

1. Go to **GitHub Updates > Install from GitHub**
2. Enter the repository owner and name
3. Click "Search Repository" to preview the repository
4. Choose installation type (Theme or Plugin)
5. Configure installation options:
   - Custom slug/directory name
   - Activate after installation
   - Add to updater list for future updates
6. Click "Install Now"

## Repository Structure Requirements

### For Themes

Your theme repository should have the standard WordPress theme structure:
```
your-theme/
├── style.css (required - with theme header)
├── index.php (required)
├── functions.php
├── screenshot.png
└── other theme files...
```

### For Plugins

Your plugin repository should have the standard WordPress plugin structure:
```
your-plugin/
├── your-plugin.php (main plugin file with plugin header)
├── includes/
├── admin/
└── other plugin files...
```

## Release Management

### Creating Releases

The plugin works best with GitHub releases:

1. Create a new release in your GitHub repository
2. Use semantic versioning (e.g., `v1.0.0`, `v1.2.3`)
3. The plugin will automatically detect new releases
4. WordPress will show update notifications when new versions are available

### Version Detection

The plugin checks for updates by:
1. Comparing the version in your theme's `style.css` or plugin's main PHP file
2. Against the latest GitHub release tag
3. Triggering WordPress update notifications when newer versions are found

## API Reference

### Hooks and Filters

#### Actions

- `dd_github_updates_before_install` - Fired before installation begins
- `dd_github_updates_after_install` - Fired after successful installation

#### Filters

- `dd_github_updates_download_timeout` - Modify download timeout (default: 60 seconds)
- `dd_github_updates_api_timeout` - Modify API request timeout (default: 10 seconds)

### Programmatic Usage

```php
// Get the API instance
$api = new DD_GitHub_API();

// Test connection
$response = $api->test_connection();

// Get latest release
$release = $api->get_latest_release('owner', 'repo-name');

// Install a package programmatically
$installer = new DD_GitHub_Installer();
$result = $installer->install_github_package(
    'plugin', // type
    'owner',  // GitHub username
    'repo-name', // repository name
    '', // download URL (optional)
    'custom-slug', // custom slug (optional)
    true // activate after install
);
```

## Troubleshooting

### Common Issues

#### "Connection failed" Error
- Verify your GitHub token is correct
- Ensure the token has `repo` scope for private repositories
- Check if your server can make outbound HTTPS requests

#### "Repository not found" Error
- Verify the repository owner and name are correct
- Ensure your token has access to the repository (for private repos)
- Check if the repository exists and is accessible

#### "Invalid ZIP archive" Error
- Ensure your repository has releases or can be downloaded as ZIP
- Check if the repository contains valid theme/plugin files
- Verify the repository structure matches WordPress requirements

#### Installation Fails
- Check WordPress file permissions
- Ensure the destination directory is writable
- Verify the repository contains valid WordPress theme/plugin structure

### Debug Logging

Enable WordPress debug logging to see detailed error messages:

```php
// Add to wp-config.php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);
```

Check `/wp-content/debug.log` for detailed error messages.

### Server Requirements

Ensure your server supports:
- `wp_remote_get()` function
- Outbound HTTPS connections to `api.github.com`
- ZIP file extraction (`unzip_file()` function)
- Adequate memory limit for large repositories

## Security Considerations

- **Token Security**: Store GitHub tokens securely and never commit them to version control
- **Repository Access**: Only add repositories you trust to the updater
- **File Permissions**: Ensure proper WordPress file permissions are maintained
- **SSL Verification**: The plugin enforces SSL certificate verification for all GitHub API requests

## Contributing

1. Fork the repository
2. Create a feature branch: `git checkout -b feature/new-feature`
3. Make your changes and test thoroughly
4. Follow WordPress coding standards
5. Commit your changes: `git commit -am 'Add new feature'`
6. Push to the branch: `git push origin feature/new-feature`
7. Submit a pull request

### Development Setup

```bash
# Clone the repository
git clone https://github.com/gabydevdev/dd-wp-github-updates.git
cd dd-wp-github-updates

# Set up development environment
# Follow WordPress plugin development best practices
```

## Changelog

### Version 1.0.0
- Initial release
- GitHub API integration
- Automatic updates for themes and plugins
- Private repository support
- Direct installation from GitHub
- Admin interface for repository management

## License

This plugin is licensed under the GPL v2 or later.

```
This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.
```

## Support

- **Documentation**: Check this README and inline code comments
- **Issues**: Report bugs on the GitHub repository
- **WordPress Forums**: Post general questions in WordPress support forums

## Credits

Developed by [gabydevdev](https://github.com/gabydevdev)

---

## Quick Start Guide

1. **Install** the plugin and activate it
2. **Get a GitHub token** with `repo` scope
3. **Configure** the token in WordPress admin
4. **Add repositories** you want to manage
5. **Install new themes/plugins** directly from GitHub
6. **Enjoy automatic updates** from your GitHub repositories!

For detailed instructions, see the sections above.

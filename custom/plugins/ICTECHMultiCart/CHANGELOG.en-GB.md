# Changelog

## 1.0.0

- Improved plugin lifecycle handling for install, activate, deactivate, update, and uninstall.
- Added uninstall cleanup to remove plugin tables and stored plugin configuration when `keepUserData` is disabled.
- Removed inline Twig script usage in favor of storefront JavaScript plugins.
- Cleaned source JavaScript to avoid console output in administration and storefront sources.
- Fixed package metadata in `composer.json` and improved plugin labels/descriptions.
- Simplified code with stricter comparisons and focused readability improvements.

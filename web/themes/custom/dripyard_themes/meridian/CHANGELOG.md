# Change Log
All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](http://keepachangelog.com/)
and this project adheres to [Semantic Versioning](http://semver.org/).

## [1.1.2] - 2026-03-16

### Added
- Hero now uses `--theme-surface` as a background color. This is useful when making background images transparent.
- RTL support for wide primary menu dropdowns.
- `dripyard/dripyard_base` as a Composer dependency.

### Changed
- Medium hero variant height changed from `max()` to `min()` for more predictable sizing (capped at 600px).
- Footer link styling simplified: removed `text-decoration-color` transition, adjusted `text-underline-offset`.
- Header third items are now center-aligned.
- Search form items are now center-aligned with `align-items: center`.
- Search submit button: removed border, fixed alignment, added `display: block` for Safari 18.6 compatibility, moved `overflow: clip` to button element.
- `<nolink>` menu items no longer show hover state.

### Fixed
- Search dropdown rendering issue in Safari.

### Removed
- `apply_recipes` schema and config from theme settings (recipe installation now handled by dripyard_base).
- `overflow: clip` from search dropdown container (moved to submit button).

## [1.1.0]
- Initial release.

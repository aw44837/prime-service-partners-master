# Change Log
All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](http://keepachangelog.com/)
and this project adheres to [Semantic Versioning](http://semver.org/).

## Next release

## [1.0.6] - 2025-12-11

### Added
- New `video-youtube` component for embedding YouTube videos into Canvas
- Canvas Edit Mode prop on Carousel and Tab Group components for easier nested component editing
- Styling options to `text` component including font-size, centered, and color
- Meta enums to all relevant components for improved UI consistency
- Support for dependencies in recipe installer
- h1 option within `heading` component

### Changed
- `card-full-image` component enhancements:
  - Added bottom-up gradient option
  - Component enters 16/9 aspect ratio landscape mode above 600px
  - `overlay_color` is now a required prop
- `logo-grid` component updates:
  - Logo background color and logo border radius now configurable
  - `layout`, `logo_size`, and `logo_background` props are now required
- Dynamic Section component requirements:
  - `margin_top`, `margin_bottom`, `padding_top`, `padding_bottom`, `cell_align_x`, `cell_align_y`, `row_gutter`, and `column_gutter` are now required props
  - Block and layout templates updated accordingly
- `heading` component prop requirements:
  - `margin_top` and `margin_bottom` props now required (default: `zero`)
  - `style` prop now required (default: `h2`)
- `accordion_group` now requires `variation` prop (default: `background-color`)
- Testimonial quote text color changed from primary to text-loud
- `text` component improved with inherited text size fixes
- `card-full-image` now renders at 100% width for canvas-dev-mode compatibility in carousels
- Icon component moved to Dripyard Basic group and hidden from UI
- Logo item uses height instead of max-height when outputting height
- Icon card icon margin adjusted based on icon size
- Accordion group styling improved for Canvas editor
- Event listing item padding fixed for last item
- Text component font size and label enhancements
- Primary button disabled state no longer shows hover styles

### Fixed
- Empty Dynamic Section header slots now hide properly in Canvas preview
- `image-or-media` utility now handles SVG images from DrupalCMS into Canvas
- Height calculation in `image-or-media` component
- Header logo props corrected
- Content block now properly checks for `<svg>` elements when deciding to render
- Region template checks for `<svg>` element existence before rendering
- Event listing item slots removed for better Canvas compatibility
- Schema updates for layouts

### Removed
- `|raw` filter from `text` component (resolved by https://www.drupal.org/project/canvas/issues/3550334)

## 1.0.5

### Added
- Canvas-image component with optional link wrapping and responsive image handling
- Flex-wrapper layout component for flexible multi-column layouts
- Template override for Canvas page components field to support Canvas builder
- Template override for main content block to add CSS class when in page editor
- Body element receives `is-canvas` class when Canvas mode is active
- CKEditor rich text support for text component body and promo component body fields

### Changed
- Card component enforces 100% width and adds Canvas variant with rich text body
- Testimonial component adds Canvas variant and removes example file
- Video player component revamped with Canvas variant and improved markup
- Logo-item component adds Canvas variant with CKEditor body support
- Teaser component adds Canvas variant and consolidated templates
- Title-cta component adds Canvas variant and consolidated from separate canvas component
- Promo component adds CKEditor body field and raw output filter
- Image-or-media utility component allows 'null' type for optional images
- Layout-dynamic component title renamed to "Dynamic section" with configurable spacing via CSS variables
- Layout-dynamic applies negative bottom margin to last instance and removes padding when nested
- Text, heading, and canvas-image components reorganized into "dripyard basic" component group
- Carousel component removes theme prop in favor of CSS theming
- Tab component generates unique IDs using random() function
- Promo block template refactored to use flex-wrapper layout
- Card landscape orientation spacing adjusted for better visual balance
- Title-cta component max-width constraint adjusted
- Textarea fields reconfigured across card, card-full-image, and testimonial components

### Deprecated
- Button-group component moved to _deprecated folder (replaced by Canvas-compatible button patterns)

### Fixed
- Promo body block wrapped in div with conditional display to hide when empty
- Promo body output uses |raw filter to prevent double-escaping of HTML entities
- Testimonial component schema validation errors
- Card-full-image enforces max-width constraint on images
- Card component spacing corrected in landscape orientation
- Select form template removes spaceless filter
- Fieldset component hides boolean option to prevent display in page editors
- LayoutBase class sets default values for cell_align_x and cell_align_y properties

### Removed
- Example template files from video-player, testimonial, card, title-cta, and tab-group components
- XB (Experience Builder) references from component documentation

## [1.0.4] - 2025-10-07

### Added
- Complete configuration schema definitions for theme settings validation
- Enhanced type annotations across all PHP classes for PHPstan level 6 compliance
- Improved documentation and parameter type declarations

### Changed
- Updated all PHP class return types from `:void` notation to proper `void` declarations
- Enhanced FormAlter classes with comprehensive type annotations and parameter validation
- Improved Layout classes with better type safety and error handling
- Updated Preprocess classes with proper type declarations and documentation
- Enhanced ThemeSettings classes with improved type safety
- Updated Utility classes with better parameter validation
- Modernized classloader and theme functions with proper type declarations

### Fixed
- Configuration schema validation errors resolved
- Icon card component maxLength property removed to prevent validation errors
- Icon card block template fixed to handle theme dev markup properly
- Event listing block template URL property corrected
- PHPstan level 6 compliance issues across 32 PHP files
- Return type declarations standardized across all classes

### Removed
- Deprecated `:void` return type syntax replaced with proper `void` declarations
- MaxLength constraint removed from icon-card body component

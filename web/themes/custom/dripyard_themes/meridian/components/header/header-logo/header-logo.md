# Header Logo Component

A site branding component that renders a logo image wrapped in a homepage link, designed for landscape-oriented logos with consistent alignment.

This component overrides the `header-logo` component within the Dripyard_base theme.

## Usage

```twig
{{ include('neonbyte:header-logo', {
  site_logo,
}, with_context = false) }}
```

### Props

| Property | Type | Description |
|----------|------|-------------|
| `site_logo` | string | Path to the logo image file |

## CSS Custom Properties

- `--header-logo-max-height` - Maximum height for logo image (default: 44px)

## Logo Configuration

Upload your site logo through the Dripyard Base theme settings:

1. Navigate to **Appearance** in Drupal admin
2. Click **Settings** next to the Dripyard Base theme
3. Upload logo using the **Logo image** field

SVG format is recommended for optimal clarity and scalability.

## Logo Adjustment Settings

The Meridian theme provides automatic logo brightness adjustments through the Header Settings:

### When Placed in Front of Hero
- **Location**: Header Settings → Logo adjustments → "When placed in front of hero"
- **Purpose**: If the logo is superimposed over a hero section, this setting uses CSS `filter` property to adjust the logo brightness to match the hero's text color
- **Options**:
  - **No change (default)**: Logo appears with original brightness
  - **Match theme of hero**: Logo brightness automatically adjusts to match the hero section's theme for optimal contrast

### When Stickied
- **Location**: Header Settings → Logo adjustments → "When stickied"
- **Purpose**: After the page is scrolled and the header becomes sticky, this setting uses CSS `filter` property to adjust logo brightness to match the header's text
- **Options**:
  - **No change (default)**: Logo appears with original brightness
  - **Match theme of header**: Logo brightness automatically adjusts to match the header theme for optimal readability

These settings ensure the logo maintains proper contrast and readability across different background colors and contexts without requiring multiple logo versions.

## Accessibility Features

- **Semantic markup**: Uses proper link structure with `rel="home"`
- **Alt text**: Provides "Home" alt text for screen readers
- **High priority loading**: Uses `fetchpriority="high"` for above-the-fold content
- **Keyboard navigation**: Standard link focus behavior

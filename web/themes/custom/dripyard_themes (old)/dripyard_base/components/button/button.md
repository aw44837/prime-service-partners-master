# Button Component

A flexible button component with multiple style variants, sizes, and icon support for creating interactive links and actions.

## Usage

```twig
{{ include('dripyard_base:button', {
  text: 'Learn More',
  href: '/learn-more',
  style: 'primary',
  size: 'medium',
  prefix_icon: 'arrow-right',
  target: true
}, with_context = false) }}
```

### Props

| Property | Type | Options | Required | Description |
|----------|------|---------|----------|-------------|
| `text` | string | - | Yes | Button text content |
| `href` | string | - | Yes | Link destination URL |
| `style` | string | `default`, `primary` | No | Button color variant |
| `size` | string | `xs`, `small`, `medium`, `large` | No | Button size variant |
| `target` | boolean | `true`, `false` | No | Open link in new window |
| `prefix_icon` | string | - | No | Icon displayed before text |
| `suffix_icon` | string | - | No | Icon displayed after text |

## Style Variants

- **Default**: Gray styling for secondary actions
- **Primary**: Primary color for main call-to-action buttons
- **Dark**: Dark styling for alternative visual emphasis

## Size Variants

- **XS**: Extra small button (25px height, 12px font)
- **Small**: Compact button (35px height, 12px font)
- **Medium**: Default size (48px height, 16px font)
- **Large**: Prominent button (56px height, 20px font)

## CSS Custom Properties

### Background Colors
- `--button-color-bg` - Default background color
- `--button-color-bg-hover` - Hover state background
- `--button-color-bg-active` - Active state background
- `--button-color-bg-icon` - Icon container background

### Text Colors
- `--button-text-color` - Default text color
- `--button-text-color-hover` - Hover state text color
- `--button-text-color-active` - Active state text color

### Border Properties
- `--button-border-color` - Border color
- `--button-border-radius` - Corner rounding
- `--button-border-width` - Border thickness

### Typography
- `--button-font-size` - Text size
- `--button-font-weight` - Text weight

### Layout
- `--button-height` - Button height
- `--button-padding-block` - Vertical padding
- `--button-padding-inline` - Horizontal padding

## Accessibility Features

- **Semantic links**: Uses `<a>` elements with proper href attributes
- **Focus indicators**: Clear focus states with customizable focus ring colors
- **Keyboard navigation**: Standard link keyboard interaction
- **Screen readers**: Proper text content and icon labeling

## Icon Integration

- **Flexible positioning**: Icons can be placed before (prefix) or after (suffix) text
- **Size adaptation**: Icon size automatically adjusts based on button size (16px for small/medium, 24px for large)
- **Visual effects**: Icons translate 2px on hover for interactive feedback
- **Icon containers**: Icons are wrapped in circular containers with background styling

## Interactive States

- **Hover**: Background color change and icon translation
- **Active**: Slight scale increase (1.05) and color changes
- **Disabled**: Reduced opacity and disabled cursor
- **Transitions**: Smooth 0.2s transitions for color changes and 0.1s for scale

## Global Library Integration

The button CSS is a dependency of the theme's global library, ensuring button styles are available site-wide.

## Related Components

- **Icon Component**: Provides icons for prefix and suffix display

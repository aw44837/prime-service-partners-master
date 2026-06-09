# Icon Component

A simple component that extends Drupal core's Icon API for rendering FontAwesome icons. Contains no styling and integrates with the theme's icon library.

## Usage

```twig
{{ include('dripyard_base:icon', {
  icon: 'home',
  size: 24
}, with_context = false) }}
```

### Props

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| `icon` | string | Yes | Icon name from FontAwesome set |
| `size` | integer | No | Icon size in pixels (example: 32) |

## Integration

- **Drupal Icon API**: Built on Drupal core's Icon API
- **No styling**: Component provides no CSS - styling handled by parent elements
- **Library integration**: Uses theme's predefined icon library configuration

## Accessibility Features

- **Icon semantics**: Uses Drupal's Icon API semantic structure
- **Size control**: Configurable size for appropriate scaling
- **Screen reader support**: Inherits Icon API accessibility features

## Recommendations

For enhanced icon management, install the [UI Icons](https://www.drupal.org/project/ui_icons) contrib module which provides an icon picker interface for easier icon selection.

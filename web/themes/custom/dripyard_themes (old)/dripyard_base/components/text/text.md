# Text Component

A very basic component to display some text with minimal styling.

## Usage

```twig
{{ include('dripyard_base:text', {
  text: 'Your text content here',
  style: 'body_m',
  color: 'medium',
  center: false,
  modifier_classes: 'custom-class'
}, with_context = false) }}
```

## Accessibility Considerations

- Uses a basic `<div>` wrapper with semantic class name
- Text content is rendered directly without additional markup modifications
- Inherits all accessibility features from the provided text content
- Relies on proper heading hierarchy and semantic markup from parent components

## Slots and Props

### Props
- `text` (required) - The text content to display (HTML content is supported)
- `style` (required) - Text size style. Options: `body_l` (Large), `body_m` (Medium), `body_s` (Small)
- `color` (required) - Text color variant. Options: `soft`, `medium`, `loud`, `primary`
- `center` (optional) - Boolean to center align the text
- `modifier_classes` (optional) - Additional CSS classes to apply to the component

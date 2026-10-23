<?php

namespace Drupal\psp_focal_point;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Image\ImageFactory;
use Drupal\Core\StreamWrapper\StreamWrapperInterface;
use Drupal\crop\Entity\Crop;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Twig function that turns an image's Focal Point crop into object-position.
 *
 * Canvas renders images through the canvas:image SDC with scale-only styles,
 * and components such as the hero crop them in CSS with object-fit: cover —
 * so the focal_point_scale_and_crop image styles never run and the browser
 * always crops around the centre. Feeding the focal point into
 * object-position makes the CSS crop honour it instead.
 */
class FocalPointTwigExtension extends AbstractExtension {

  /**
   * Per-request cache of results, keyed by source URL.
   */
  protected array $results = [];

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ImageFactory $imageFactory,
    protected StreamWrapperInterface $publicStream,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getFunctions(): array {
    return [
      new TwigFunction('focal_point_position', [$this, 'position']),
    ];
  }

  /**
   * Returns the focal point of an image as a CSS position.
   *
   * @param mixed $src
   *   An image URL (site-relative or absolute) or stream wrapper URI.
   *
   * @return array
   *   'position': a CSS object-position value such as "45% 30%", or NULL
   *   when the image is not a local file or has no focal point set.
   *   'cache': a render array carrying the crop cache tags; print it so the
   *   page is invalidated when an editor moves the focal point.
   */
  public function position(mixed $src): array {
    $src = is_string($src) ? $src : '';
    if (isset($this->results[$src])) {
      return $this->results[$src];
    }
    $result = ['position' => NULL, 'cache' => ['#cache' => ['tags' => ['crop_list']]]];

    $uri = $this->toUri($src);
    if ($uri && ($crop = Crop::findCrop($uri, 'focal_point'))) {
      $result['cache']['#cache']['tags'] = $crop->getCacheTags();
      $image = $this->imageFactory->get($uri);
      $width = $image->getWidth();
      $height = $image->getHeight();
      if ($width && $height) {
        $point = $crop->position();
        $x = max(0, min(100, round($point['x'] / $width * 100, 2)));
        $y = max(0, min(100, round($point['y'] / $height * 100, 2)));
        $result['position'] = "$x% $y%";
      }
    }

    return $this->results[$src] = $result;
  }

  /**
   * Maps an image URL to its public:// URI, or NULL if it is not a local file.
   */
  protected function toUri(string $src): ?string {
    $path = strtok($src, '?#');
    if (!$path) {
      return NULL;
    }
    if (str_contains($path, '://')) {
      if (str_starts_with($path, 'public://')) {
        return $path;
      }
      // Absolute URL: keep only the path, then treat it as site-relative.
      $path = parse_url($path, PHP_URL_PATH) ?: '';
    }
    $base = '/' . trim($this->publicStream->getDirectoryPath(), '/') . '/';
    $offset = strpos($path, $base);
    if ($offset === FALSE) {
      return NULL;
    }
    $relative = substr($path, $offset + strlen($base));
    // Image style derivative: styles/<style>/public/<path>[.avif|.webp].
    if (preg_match('#^styles/[^/]+/public/(.+)$#', $relative, $m)) {
      $relative = preg_replace('#\.(avif|webp)$#', '', $m[1]);
    }
    return 'public://' . rawurldecode($relative);
  }

}

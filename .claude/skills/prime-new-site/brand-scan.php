<?php
// Report leftover master-template branding in active config + current content.
// Usage: ddev exec 'SCAN_RE="/BRAND_NAME|BRAND_LOCATION|jef+co|santa rosa|616-2183/i" drush php:script .ddev/brand-scan.php'
$re = getenv('SCAN_RE') ?: '/jef+co|santa rosa/i';
$ctx = function (string $s, array $m) {
  $o = max(0, $m[1] - 60);
  return preg_replace('/\s+/', ' ', strip_tags(substr($s, $o, strlen($m[0]) + 120)));
};
$out = [];
// Config.
foreach (\Drupal::configFactory()->listAll() as $name) {
  $raw = \Drupal::config($name)->getRawData();
  $it = new RecursiveIteratorIterator(new RecursiveArrayIterator($raw));
  foreach ($it as $k => $v) {
    if (!is_string($v)) continue;
    $keys = [];
    foreach (range(0, $it->getDepth()) as $d) $keys[] = $it->getSubIterator($d)->key();
    if (preg_match_all($re, $v, $mm, PREG_OFFSET_CAPTURE)) {
      foreach ($mm[0] as $m) $out[] = ['config', $name, implode('.', $keys), $ctx($v, $m)];
    }
  }
}
// Content entities (default/current revision only; includes trashed via direct SQL-free load where possible).
$etm = \Drupal::entityTypeManager();
// Include items in the Trash (they still ship in DB seeds and can be restored).
if (\Drupal::hasService('trash.manager')) \Drupal::service('trash.manager')->setTrashContext('ignore');
foreach ($etm->getDefinitions() as $id => $def) {
  if (!$def->entityClassImplements(\Drupal\Core\Entity\FieldableEntityInterface::class)) continue;
  if (in_array($id, ['webform_submission', 'easy_email', 'ai_log', 'user', 'ai_agent_decision', 'path_alias'])) continue;
  try { $storage = $etm->getStorage($id); $ids = $storage->getQuery()->accessCheck(FALSE)->execute(); } catch (\Throwable $e) { continue; }
  foreach (array_chunk($ids, 50) as $chunk) {
    foreach ($storage->loadMultiple($chunk) as $e) {
      foreach ($e->getFields() as $fname => $items) {
        foreach ($items->getValue() as $delta => $item) {
          foreach ($item as $prop => $val) {
            if (!is_string($val)) { if (is_array($val)) $val = json_encode($val, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); else continue; }
            if (preg_match_all($re, $val, $mm, PREG_OFFSET_CAPTURE)) {
              $pub = method_exists($e, 'isPublished') ? ($e->isPublished() ? 'pub' : 'unpub') : '-';
              foreach ($mm[0] as $m) $out[] = ["$id:" . $e->id(), (string) $e->label() . " [$pub]", "$fname.$delta.$prop", $ctx($val, $m)];
            }
          }
        }
      }
    }
    $storage->resetCache($chunk);
  }
}
foreach ($out as $r) echo implode("\t", $r), "\n";
echo "TOTAL\t", count($out), "\n";

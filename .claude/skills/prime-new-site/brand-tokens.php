<?php
// Replace master-template placeholders in active config + current content (Trash included).
// Run inside the new site:
//   cp .claude/skills/prime-new-site/brand-tokens.php .ddev/
//   ddev exec 'BRAND_NAME="<Full name>" MARKET_AREA="<City, ST>" BRAND_PHONE="(919) 555-1234" BRAND_ADDRESS="<street<br>City, ST ZIP>" drush php:script .ddev/brand-tokens.php'
// Add APPLY=1 to save. MARKET_AREA may be empty for multi-market sites.
// BRAND_ADDRESS may be omitted (placeholder stays; the scan gate will flag it).
$name = getenv('BRAND_NAME');
$market = getenv('MARKET_AREA');
$phone = getenv('BRAND_PHONE');
if (!$name || $market === FALSE || !$phone) { echo "Set BRAND_NAME, MARKET_AREA (may be empty) and BRAND_PHONE\n"; return; }
$tel = preg_replace('/\D/', '', $phone);
$address = getenv('BRAND_ADDRESS');
$apply = getenv('APPLY') === '1';

$map = [
  'tel:555-555-5555' => "tel:$tel",
  '555-555-5555' => $phone,
  'BRAND_NAME' => $name,
];
if ($address !== FALSE && $address !== '') {
  $map['BRAND_ADDRESS'] = $address;
}
// Multi-market: drop the location phrasing instead of leaving "based in ".
$pre = $market === '' ? [
  'Location: based in MARKET_AREA and will be used' => 'The content will be used',
  ' to MARKET_AREA region.' => '.',
] : [];
$map['MARKET_AREAS'] = $market;
$map['MARKET_AREA'] = $market;
// Older master copy had an unfilled services list token; drop the clause.
$pre[' with SERVICES.'] = '.';
$walk = function ($v) use (&$walk, $map, $pre) {
  if (is_array($v)) { foreach ($v as $k => $x) $v[$k] = $walk($x); return $v; }
  return is_string($v) ? strtr(strtr($v, $pre), $map) : $v;
};
$n = 0;
foreach (\Drupal::configFactory()->listAll() as $cname) {
  $c = \Drupal::configFactory()->getEditable($cname);
  $raw = $c->getRawData();
  $new = $walk($raw);
  if ($new !== $raw) { $n++; echo "config  $cname\n"; if ($apply) $c->setData($new)->save(); }
}
$etm = \Drupal::entityTypeManager();
if (\Drupal::hasService('trash.manager')) \Drupal::service('trash.manager')->setTrashContext('ignore');
foreach ($etm->getDefinitions() as $id => $def) {
  if (!$def->entityClassImplements(\Drupal\Core\Entity\FieldableEntityInterface::class)) continue;
  if (in_array($id, ['webform_submission', 'easy_email', 'ai_log', 'ai_agent_decision', 'user'])) continue;
  try { $s = $etm->getStorage($id); $ids = $s->getQuery()->accessCheck(FALSE)->execute(); } catch (\Throwable $e) { continue; }
  foreach (array_chunk($ids, 50) as $chunk) {
    foreach ($s->loadMultiple($chunk) as $e) {
      $dirty = FALSE;
      foreach ($e->getFields() as $f => $items) {
        $val = $items->getValue();
        $new = $walk($val);
        if ($new !== $val) { $e->set($f, $new); $dirty = TRUE; }
      }
      if ($dirty) { $n++; echo "content $id:" . $e->id() . ' ' . $e->label() . "\n"; if ($apply) $e->save(); }
    }
    $s->resetCache($chunk);
  }
}
echo ($apply ? 'APPLIED' : 'DRY RUN') . ": $n objects\n";
echo "Still to do by hand: REVIEW_TITLE / REVIEWER_NAME / REVIEW_QUOTE cards (real reviews or remove the cards)";
echo ($address ? '' : ', BRAND_ADDRESS') . "\n";

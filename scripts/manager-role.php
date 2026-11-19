<?php

/**
 * @file
 * Creates or refreshes the fleet "Manager" role and writes it to config sync.
 *
 * Content-only editor: nodes, content blocks, menus, media, per-page meta
 * tags, aliases/redirects. No config, modules, themes, AI settings, users,
 * or update/security notices.
 *
 * Permissions are matched against what each site actually defines, so the
 * same script works across the fleet even where bundles differ.
 *
 * Usage: ddev drush php:script scripts/manager-role.php
 */

use Drupal\user\Entity\Role;

$allow = [
  // Admin UI shell.
  '/^access administration pages$/',
  '/^view the administration theme$/',
  '/^access navigation$/',
  '/^access contextual links$/',
  '/^view welcome dashboard$/',

  // Content: every node type, plus revisions and moderation.
  '/^access content overview$/',
  '/^(create|edit any|edit own|delete any|delete own) \S+ content$/',
  '/^(view|revert|delete) \S+ revisions$/',
  '/^(view|revert|delete) all revisions$/',
  '/^view own unpublished content$/',
  '/^view any unpublished content$/',
  '/^view latest version$/',
  '/^use \S+ transition \S+$/',
  '/^schedule publishing of nodes$/',
  '/^view scheduled content$/',

  // Canvas pages (content entities, not templates/patterns/components).
  '/^(create|edit|delete) canvas_page$/',
  '/^publish auto-saves$/',

  // Content blocks.
  '/^access block library$/',
  '/^(create \S+ block content|(edit|delete) any \S+ block content)$/',
  '/^(delete|revert) any \S+ block content revisions$/',
  '/^view any \S+ block content history$/',

  // Menus (links are content; menu entities themselves stay in config).
  '/^administer menu$/',
  '/^use menu link attributes$/',

  // Media.
  '/^access media overview$/',
  '/^(create|edit any|edit own|delete any|delete own) \S+ media$/',
  '/^(view|revert|delete) any \S+ media revisions$/',
  '/^view own unpublished media$/',
  '/^use media \S+ bulk upload form$/',
  '/^schedule publishing of media$/',
  '/^view scheduled media$/',

  // Tags vocabulary only (service_area drives coverage, left to admins).
  '/^access taxonomy overview$/',
  '/^view vocabulary labels$/',
  '/^(create terms|edit terms|delete terms) in tags$/',

  // URLs, redirects, per-entity SEO.
  '/^(administer|create) url aliases$/',
  '/^administer redirects$/',
  '/^notify of path changes$/',
  '/^edit entity sitemap settings$/',

  // Text formats used by content and block fields (no Full HTML).
  '/^use text format (content_format|basic_html|dripyard_superbasic|canvas_html_block|canvas_html_inline)$/',

  // Trash: view and restore, not purge.
  '/^access trash$/',
  '/^view deleted entities$/',
  '/^restore node (entities|translations)$/',

  // AI as a writing aid only (no AI configuration).
  '/^generate ai alt tags$/',
  '/^use ai ckeditor$/',
  '/^access ai content suggestion tools$/',
];

// Never grant, even if a pattern above would match.
$deny = [
  '/^administer /',
  '/^view update notifications$/',
  '/^access site reports$/',
  '/^access announcements$/',
  '/^bypass /',
  '/^link to any page$/',
];

$available = array_keys(\Drupal::service('user.permissions')->getPermissions());
$grant = [];
foreach ($available as $perm) {
  $allowed = FALSE;
  foreach ($allow as $re) {
    if (preg_match($re, $perm)) {
      $allowed = TRUE;
      break;
    }
  }
  if (!$allowed) {
    continue;
  }
  foreach ($deny as $re) {
    // 'administer menu', url aliases and redirects are the deliberate exceptions.
    if (preg_match($re, $perm) && !in_array($perm, ['administer menu', 'administer url aliases', 'administer redirects'], TRUE)) {
      continue 2;
    }
  }
  $grant[] = $perm;
}
sort($grant);

$role = Role::load('manager') ?? Role::create(['id' => 'manager', 'label' => 'Manager', 'weight' => 3]);
foreach ($role->getPermissions() as $perm) {
  $role->revokePermission($perm);
}
foreach ($grant as $perm) {
  $role->grantPermission($perm);
}
$role->save();

// Write only this one item to the sync directory; avoids a full export.
$data = \Drupal::service('config.storage')->read('user.role.manager');
\Drupal::service('config.storage.sync')->write('user.role.manager', $data);

echo count($grant) . " permissions granted to Manager; wrote user.role.manager.yml\n";
echo implode("\n", $grant) . "\n";

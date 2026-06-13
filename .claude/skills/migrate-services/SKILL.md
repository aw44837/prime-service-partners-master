---
name: migrate-services
description: >
  Migrate a client's "service pages" (and other structured, field-based content) from
  their existing website into a Drupal content type — e.g. the Services node type on a
  PSP site. Unlike the bespoke Canvas pages (see migrate-to-canvas), these are uniform:
  each page is a node with fields (body, intro, featured image) rendered through one
  shared content template, so a whole category migrates in a couple of batched commands.
  Use when the user wants to "migrate the service pages", "import the sub-service pages",
  "create the services nodes", "bring over AC/Heating/IAQ service pages", or migrate any
  repeated field-based content type (also a good base for blog articles / local service
  areas). Scrapes each origin page, extracts clean body HTML, creates/updates the nodes
  with proper aliases, and wires them into the menu.
---

# Migrate structured "service" pages into a Drupal content type

For repeated, field-based pages (Services, Local Service Areas, Blog) the page is a
**node with fields**, rendered by **one shared template** (often a Canvas content
template, e.g. `canvas.content_template.node.services.full`). So the migration is: scrape
→ clean body HTML → create nodes → wire menu. Proven on the 2cool Services type (17 pages
in ~2 batched commands). **Set expectations: a strong, editable starting point.**

## 0. Pre-flight

```bash
WS="/Users/andywaldrop/Sites/prime service partners"; SITE=2cool
cd "$WS/$SITE"
# the content type + its fields:
ddev drush ev '$d=\Drupal::service("entity_field.manager")->getFieldDefinitions("node","services");
 foreach($d as $n=>$f){ if(strpos($n,"field_")===0) echo "  $n (".$f->getType().")\n"; }'
# an existing example node (the model — gives the text format + field mapping):
ddev drush ev '$ids=\Drupal::entityQuery("node")->condition("type","services")->accessCheck(FALSE)->execute();
 foreach(\Drupal::entityTypeManager()->getStorage("node")->loadMultiple($ids) as $n){
   echo $n->id()." | ".$n->label()." | ".\Drupal::service("path_alias.manager")->getAliasByPath("/node/".$n->id())."\n"; }'
```
- Note the **content type** machine name, its **fields**, and a **model node** id. The
  whole page lives in the body field as formatted HTML (`<h1>` title + `<h2>` subhead +
  `<p>`/`<h2>`/`<h3>` sections); intro/featured-image are optional. Grab the model node's
  text **format** (e.g. `content_format`) to reuse.
- **The body field name varies by content type** — `services` uses **`field_body`**, but
  `local_service_area_page` uses the core **`body`** (text_with_summary) field. Check the
  model node (the field list won't show `field_body` if it's really `body`), and use the
  right field name in steps 4.

## 1. Recon the origin (list the pages)

```bash
UA='Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36'
curl -sL -A "$UA" '<ORIGIN>/site-map/' -o /tmp/sitemap.html
grep -oE '/(air-conditioning|heating|indoor-air-quality)/[a-z0-9-]+/"' /tmp/sitemap.html | tr -d '"' | sort -u
```
Build the list of sub-service URLs + decide each page's **title** and **category** (the
URL prefix → menu parent). Inspect one page to find the authored-content container —
Scorpion uses `<div id="ColumnContentExpandContent">`.

## 2. The body extractor (reusable)

Write `/tmp/extract_service.pl` — it isolates the authored content by **balanced `<div>`
matching** (not stop-markers, which bleed into nav/forms/reviews), then emits clean
semantic HTML (h1–h3, p, ul) with attributes stripped:

```perl
#!/usr/bin/perl
# NOTE: uses Perl *named captures* ($+{name}, \g{name}) on purpose — the skill harness
# rewrites bare $1/$2/\1 as positional argument placeholders, which corrupts the regex.
use strict; use warnings; local $/; my $html = <STDIN>;
my $i = index($html, 'ColumnContentExpandContent');          # the authored-content container
my $divstart = $i >= 0 ? rindex($html, '<div', $i) : 0;
my $sub = substr($html, $divstart); my ($depth,$region)=(0,$sub);
while ($sub =~ /(?<tok><div\b|<\/div\s*>)/gis){ $depth += ($+{tok}=~/^<div/i)?1:-1; if($depth==0){ $region=substr($sub,0,pos($sub)); last; } }
sub clean { my $s=shift;
  $s=~s/<(?:script|style)\b.*?<\/(?:script|style)>//gis;
  $s=~s/<a\b[^>]*?href="(?<u>[^"]*)"[^>]*>/<a href="$+{u}">/gis;     # keep href only
  $s=~s/<(?<c>\/?)(?<g>strong|em|b|i)\b[^>]*>/<$+{c}$+{g}>/gis;      # keep simple inline
  $s=~s/<br\s*\/?>/ /gis; $s=~s/<(?!\/?(?:a|strong|em|b|i)\b)[^>]*>//gis;  # drop other tags
  $s=~s/&nbsp;/ /g; $s=~s/\s+/ /g; $s=~s/^\s+|\s+$//g; return $s; }
my $out='';
while ($region =~ /<(?<tag>h[1-3]|p|ul|ol)\b[^>]*>(?<inn>.*?)<\/\g{tag}>/gis){ my($t,$in)=(lc $+{tag}, $+{inn});
  if($t eq 'ul' or $t eq 'ol'){ my $it=''; while($in=~/<li\b[^>]*>(?<li>.*?)<\/li>/gis){ my $li=clean($+{li}); $it.="<li>$li</li>" if $li=~/\S/; } $out.="<$t>$it</$t>\n" if $it; next; }
  my $x=clean($in); next unless $x=~/\S/;
  next if $x=~/window\.|Process\.Page|^Call Now\b|^Schedule Your|^Apply Now\b|^Learn More\b/i;
  $out.="<$t>$x</$t>\n"; }
print $out;
```
Spot-check one page: `perl /tmp/extract_service.pl < origin.html` — the **last** blocks
should be the final authored section, not a contact form / service nav / reviews.

**Variant — no `ColumnContentExpandContent` (e.g. blog posts).** Some content types render
the body in a single `cnt-stl` div with no `ColumnContentExpandContent` anchor; the balanced
approach then falls back to whole-doc and grabs the **nav menu**. For those, anchor on the
**first `<h1>`** instead: `region = substr(from first <h1>)`, cut at the footer / reviews /
contact (`Hear From Our Happy Customers`, `Get In Touch`, `Most Recent Posts`, `<footer`),
then run the same named-capture `clean()` loop — and **filter junk lists** (skip a `<ul>`
with >7 items or containing nav/form text like `Main Menu`, `Please enter`, `Emergency AC
Services`). Always eyeball the first block: if it's `Air Conditioning Emergency AC Services
AC Installation…` you grabbed the nav — re-anchor.

## 3. Scrape + extract every page (use a **bash** script)

zsh in this env breaks on `$(...)` inside a `for` loop ("command not found: curl"). Write
a `.sh` and run it with `bash`:

```bash
cat > /tmp/scrape.sh <<'EOF'
#!/bin/bash
UA='Mozilla/5.0 ...'; DEST="<WS>/<SITE>/svc-import"; mkdir -p "$DEST"
for p in air-conditioning/ac-installation heating/heating-repair ...; do
  slug="${p##*/}"
  curl -sL -A "$UA" "<ORIGIN>/$p/" -o "/tmp/o-$slug.html"
  perl /tmp/extract_service.pl < "/tmp/o-$slug.html" > "$DEST/$slug.html"
  printf "  %-26s %6s b  %3s blocks\n" "$slug" "$(wc -c < "$DEST/$slug.html")" "$(grep -oE '<(h[1-3]|p|ul)' "$DEST/$slug.html" | wc -l)"
done
EOF
bash /tmp/scrape.sh
```
**Before reading the files in a drush script, force the sync and verify the container.**
Host→container (mutagen) sync can lag, so a `drush php:script` may read stale/empty/identical
files (symptom: every node gets the *same* body). Do:
```bash
ddev mutagen sync
ddev exec "wc -c /var/www/html/<dir>/*.html"   # sizes must be present + DISTINCT
```
and add a sanity guard in the create script — skip a file if it has no `<h1>` or looks like
the nav (`stripos($html,'Emergency AC Services AC Installation')!==false`).
Files land in `<SITE>/svc-import/` (mounted → container `/var/www/html/svc-import/`).

## 4. Create / update the nodes (idempotent)

A `drush php:script` keyed by a `slug => [title, category]` map. For each: read the
extracted HTML, **match an existing node by alias** (update) or create, set
`field_body` with the model node's **format**, and set the alias with **`pathauto`
skip** (pathauto otherwise overrides a manual alias with `/[title]`):

```php
$node->set('field_body', ['value'=>$html, 'format'=>$fmt]);
$node->set('path', ['alias'=>"/$category/$slug", 'pathauto'=>0]);   // 0 = skip auto
```
Find existing: `$sys = \Drupal::service('path_alias.manager')->getPathByAlias($alias);`
→ if it matches `^/node/(\d+)$`, load + update that node; else create. (Idempotent =
safe to re-run.) Note `field_body` is rendered HTML, so `&amp;`/`&mdash;` entities are
**correct** there — the Canvas plain-string heading double-escape rule does NOT apply.

## 5. Wire the main menu (idempotent)

Attach each node under its **category parent** (the top-level `Air Conditioning` /
`Heating` / `Indoor Air Quality` menu links). Parent ref is
`menu_link_content:<parent-uuid>`. Index existing main-menu links by their resolved path
(`$m->getUrlObject()->toString()`) so a re-run **updates** instead of duplicating; set a
`weight` per item for order:

```php
$parents['ac'] = 'menu_link_content:'.$storage->load(<AC_parent_id>)->uuid();
MenuLinkContent::create(['title'=>$title, 'link'=>['uri'=>'internal:/node/'.$nid],
  'menu_name'=>'main', 'parent'=>$parents[$cat], 'weight'=>$w])->save();
```

## 6. Verify

```bash
# all pages 200 + no double-escape (run via bash, not a zsh loop):
for p in <paths>; do curl -sk -o /tmp/s.html -w "$p %{http_code}\n" "https://$SITE.ddev.site/$p"; \
  grep -cF '&amp;amp;' /tmp/s.html; done
# render one through the template:
"<chrome>" --headless=new --ignore-certificate-errors --window-size=1440,4500 \
  --screenshot=/tmp/svc.png "https://$SITE.ddev.site/<category>/<slug>"   # then Read it
```
The content template supplies the hero banner, sidebar (Financing / Request Appointment /
Our Services), and layout automatically — you only provide `field_body`.

## Gotchas

- **Pathauto overrides manual aliases** → set `path.pathauto = 0` (skip) when you set the
  alias, or it becomes `/[title]`.
- **`field_body` is HTML** — use entities (`&amp;`, `&mdash;`); do NOT decode them (that's
  only for Canvas plain-string heading props).
- **zsh `$()`-in-`for` breaks** here — put batch loops in a `.sh` and run with `bash`.
- **Extraction**: balanced-`<div>` of the authored container beats stop-markers (which
  catch the trailing nav/form/reviews). Strip all attributes except `<a href>`. When a
  page's authored content is spread across several `cnt-stl` blocks (richer layouts like
  local-area pages), the single-container approach undershoots — extract from the first
  `<h1>` instead, filtering junk lists/CTAs.
- **Mid-page reviews block**: some pages embed a "Hear From Our Happy Customers" / reviews
  section *between* authored sections, so a single stop-marker truncates everything after
  it. Extract **two regions** (before the reviews block + from the next real `<h2>` up to
  the contact/footer) and concatenate. (Hit on the Tampa local-area page.)
- **Perl in this file uses named captures** (`$+{name}`, `\g{name}`) not `$1`/`$2` — the
  skill harness rewrites bare `$N` as argument placeholders and corrupts the script.
- **Thin origin pages** migrate faithfully (just less content) — fine.

## What's the user's final touch

`field_featured_image` (template shows a default), `field_additional_body_content` (left
empty), and copy polish. The nodes + menu are **DB content** (not git/config) — they
travel with a DB migration, not `config:export`.

# Re-gated NeonByte header CSS (1000px → 1180px)

The NeonByte header collapses into its mobile flyout at `1000px`. This site (and
the estate's Meridian header, which uses `1180px`) wants the collapse at
**1180px**, so the main menu becomes the burger menu below that width.

Every NeonByte breakpoint that drives the collapse lives in six CSS files, each
gated by a top-level media query. Rather than trying to out-specify those rules
from a later stylesheet — which fails, because the desktop rules (`display:
contents` on the flyout wrapper, etc.) would still apply in the 1001–1180px band
— we serve **verbatim copies with `1000px` replaced by `1180px`** and swap them
in with `libraries-override` in `meridian_subtheme.info.yml`.

## Files

Copied from `neonbyte/components/header/`:

| This directory                 | Source                                     |
| ------------------------------ | ------------------------------------------ |
| `header.css`                   | `header/header.css`                        |
| `primary-menu-wide.css`        | `primary-menu/primary-menu-wide.css`       |
| `primary-menu-narrow.css`      | `primary-menu/primary-menu-narrow.css`     |
| `primary-menu-wide.theme.css`  | `primary-menu/primary-menu-wide.theme.css` |
| `primary-menu-narrow.theme.css`| `primary-menu/primary-menu-narrow.theme.css` |
| `mobile-nav-button.css`        | `mobile-nav-button/mobile-nav-button.css`  |

`header.theme.css` has no breakpoints, so it is not copied and still loads from
NeonByte directly.

## Refreshing after a Dripyard theme update

These are copies, so a NeonByte update will NOT reach them. After updating the
themes, re-run:

```bash
cd web/themes/custom/dripyard_themes
N=neonbyte/components/header
for f in "$N/header/header.css:header.css" \
         "$N/primary-menu/primary-menu-wide.css:primary-menu-wide.css" \
         "$N/primary-menu/primary-menu-narrow.css:primary-menu-narrow.css" \
         "$N/primary-menu/primary-menu-wide.theme.css:primary-menu-wide.theme.css" \
         "$N/primary-menu/primary-menu-narrow.theme.css:primary-menu-narrow.theme.css" \
         "$N/mobile-nav-button/mobile-nav-button.css:mobile-nav-button.css"; do
  sed -e 's/1000px/1180px/g' -e 's/(width < 1180px)/(width <= 1180px)/g' "${f%%:*}" > "meridian_subtheme/css/neonbyte-1180/${f##*:}"
done
```

Then confirm the swap is still wired up (paths in `libraries-override` must match
the SDC library's declared paths, which are relative to core — `../themes/...`):

```bash
ddev drush cache:rebuild
ddev drush ev '$d=\Drupal::service("library.discovery");
foreach(["components.neonbyte--header","components.neonbyte--primary-menu","components.neonbyte--mobile-nav-button"] as $n){
  foreach(($d->getLibraryByName("core",$n)["css"]??[]) as $c){
    echo basename($c["data"])." <- ".(strpos($c["data"],"neonbyte-1180")!==false?"SUBTHEME":"original")."\n"; } }'
```

Related: `css/neonbyte-header-support.css` gates the flyout CTA placement at the
same 1180px, and `css/base.css` gates the Meridian-header equivalent at
`@container (width <= 1180px)`.

Note: stock `primary-menu-narrow.theme.css` is gated `(width < 1000px)` while
the other narrow files use `<=`, so at exactly the breakpoint the flyout got its
layout but not its theme (no "+" toggles, no link styling). The refresh script
above normalises that to `<= 1180px`.

The copies keep NeonByte's relative `url('images/…')` references, so the
primary-menu `images/` folder (arrow-right.svg) must live next to them:

```bash
cp themes/custom/dripyard_themes/neonbyte/components/header/primary-menu/images/* \
   themes/custom/dripyard_themes/meridian_subtheme/css/neonbyte-1180/images/
```

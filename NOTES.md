# Project notes - MMSEO WebP Converter

## Status (2026-10-01)
- Plugin version **1.1.0**, folder `mmseo-webp-converter/`.
- Passes the official WordPress Plugin Check review ruleset (0 problems) and WordPress-Extra + WordPress-Docs + PHPCompatibilityWP for PHP 7.4+ (0 errors, 0 warnings).
- Own QA harness: all stages pass with 0 failures (setup, unit, cycle1, cycle2, cycle3, security, stress, extras).
- NOT yet submitted to WordPress.org.

## Still to do before submitting to WordPress.org
1. In `mmseo-webp-converter/readme.txt` replace `Contributors: mmseo` with the real WordPress.org username.
2. Change `Author:` in the main plugin file if a different name is wanted.
3. Upload the ZIP at https://wordpress.org/plugins/developers/add/

## How it works (short)
Phases: init -> backup_files -> backup_db -> convert -> replace (forward) -> finalize (delete old files) -> summary.
Restore phases: restore_files -> restore_attachments -> restore_refs (replace reverse) -> restore_cleanup.
Backups live in `wp-content/uploads/mmseo-webp-backup-<12 random chars>/run-YYYYMMDD-HHMMSS/` with `run.json`, `map.json`, `reverse.json`, `created.json`, `snap-*.json`, `db-*.sql` and an `uploads/` copy of originals.
`created.json` lists every file the plugin created so restore removes exactly those.
Settings option: `mmseo_webp_settings`. Backup folder key option: `mmseo_webp_dirkey`. AJAX action: `mmseo_webp` (nonce `mmseo_webp`, capability `manage_options`).

## Decisions
- Backups are kept on uninstall unless the user ticks "Delete backups when the plugin is deleted".
- Auto-convert of new uploads is OFF by default; 301 redirect of old URLs is ON by default.
- Animated GIFs are skipped (real GIF frame counter), as are images where WebP would not be smaller.
- Restore compares file contents (md5), not just size.
- References are rewritten in posts (content, excerpt), postmeta, termmeta and options only (serialized/JSON/URL-encoded safe). Custom tables are not touched.

## Known limits
GD output has no EXIF. TIFF needs Imagick. Custom-table references are not rewritten (redirect covers them).

## How the checks were run (Windows, Laragon)
- Checker: Plugin Check download's bundled phpcs: `php vendor\bin\phpcs --standard=phpcs-rulesets\plugin-review.xml --runtime-set testVersion 7.4- --extensions=php <plugin folder>`.
- WPCS: `phpcs --standard=WordPress-Extra,WordPress-Docs,PHPCompatibilityWP --runtime-set testVersion 7.4- --warning-severity=1 <plugin folder>`.
- QA: `qa/qa2.php` needs a local WordPress at `F:/laragon/www/wptest` (paths are hard-coded at the top of the file). Run stages: `php qa2.php reset|setup|unit|cycle1|cycle2|cycle3|security|stress|extras`. `qa/run-all.bat` runs them in order and logs to `F:\laragon\qa2.log`.

## Public page
`docs/` is served by GitHub Pages (Settings -> Pages -> Deploy from branch -> `main` / `/docs`).
Sitemap: https://mmrahmanbappi.github.io/mmseo-webp-converter/sitemap.xml . Re-edit `docs/sitemap.xml` if the address changes.
Screenshots in `docs/assets/` were taken with headless Chrome from the local test site.

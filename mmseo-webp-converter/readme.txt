=== MMSEO WebP Converter ===
Contributors: mmseo
Tags: webp, images, optimization, media library, compress
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Back up first, then convert your whole Media Library to compressed WebP and update every reference. One-click restore included.

== Description ==

MMSEO WebP Converter turns every JPEG, PNG, GIF and BMP in your Media Library into a compressed WebP image, updates the places that use those images, and removes the old files, while keeping a complete backup you can restore at any time.

**How it works**

1. **Backup.** Every original image file, every generated size and the related database tables are copied into a protected backup folder before anything is changed.
2. **Convert.** Each image and all of its thumbnail sizes are re-created as WebP at the quality you choose. The attachment ID never changes, so featured images and galleries keep working.
3. **Update references.** Image paths are replaced in post content, post excerpts, post meta (including page-builder data such as Elementor), term meta and options. Serialized and JSON data is handled safely so it is not corrupted.
4. **Clean up.** Old files are deleted only after their WebP replacement has been created and verified. If any single image fails, its original is kept.

**Restore**

Every run is listed under "Backups and restore". One click copies the original files back, restores the attachment records and metadata, reverses the reference changes and removes the WebP files created by that run.

**Other features**

* Progress bar with batch processing, so large libraries do not time out.
* Optional automatic WebP conversion of new uploads.
* Optional 301 redirect from old image URLs to the WebP file, so external links and search-engine results keep working.
* Skips animated GIFs, images that are already WebP and images where WebP would not be smaller.
* Verifies every new file (format and dimensions) before using it.
* Works with GD or Imagick. A built-in GD fallback handles palette GIF/PNG and BMP files.

**Good to know**

* WebP files created with GD do not carry EXIF data. The image metadata stored in WordPress (caption, credit, camera details) is preserved in the database.
* TIFF files need the Imagick extension.
* References stored in places other than posts, post meta, term meta and options (for example custom tables) are not changed. Keep "Redirect old image URLs" enabled to cover these.
* Always keep your own full-site backup as well.

== Installation ==

1. Upload the `mmseo-webp-converter` folder to `/wp-content/plugins/`, or install the plugin from the Plugins screen.
2. Activate the plugin.
3. Open **MMSEO WebP** in the admin menu, choose your options and click **Backup, then convert everything**.

== Frequently Asked Questions ==

= Can I undo the conversion? =

Yes. Open **MMSEO WebP**, find the run under "Backups and restore" and click **Restore**. Your original files, image records and links are put back.

= Will my posts lose their images? =

No. Attachment IDs are unchanged and every reference is updated to the new file. If you keep the redirect option enabled, old image URLs also redirect to the WebP file.

= Where are backups stored? =

In a protected folder with an unguessable name inside your uploads folder. You can delete a backup from the plugin screen once you are happy with the result.

= Are backups removed when I delete the plugin? =

Only if you tick "Delete backups when the plugin is deleted". By default they are kept.

= Does it send data anywhere? =

No. Everything runs on your own server. The plugin makes no external requests.

= What happens to animated GIFs? =

They are skipped, because WebP conversion would remove the animation.

== Screenshots ==

1. The converter screen with options, progress bar and the backups list.

== Changelog ==

= 1.1.0 =
* New: one-click restore from any backup.
* New: top-level admin menu with icon and a list of backups.
* New: verification of every converted file before it is used.
* New: GD fallback for palette GIF/PNG and BMP files.
* Improved: safer handling of serialized and JSON data, including URL-encoded paths.
* Improved: code now follows the WordPress coding standards.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.1.0 =
Adds restore, a clearer menu and stronger safety checks.

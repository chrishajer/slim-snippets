=== Slim Snippets ===
Contributors: chrishajer
Tags: snippets, code, php, css, javascript
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.7
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Run PHP, CSS and JavaScript snippets without editing functions.php. Small and fast, with no cloud, upsells or tracking.

== Description ==

Slim Snippets keeps the parts of a snippets plugin that matter day to day and leaves the rest out.

* **PHP snippets** that run everywhere, only in the admin, only on the front end, or once.
* **CSS and JavaScript snippets** printed in the front-end head or footer, or in the admin head.
* **Syntax-highlighting editor** (the CodeMirror editor built into WordPress). The mode follows the snippet's scope.
* **No activation with errors.** PHP is syntax-checked and test-run before it is activated. If it throws an error, dies with a fatal error, or declares a function or class that already exists, it stays inactive and the error is shown. If an active snippet throws an error on a later page load, it deactivates itself and records the error.
* **Fast.** Active snippets are stored in one autoloaded option, so running them adds no database queries.
* **Priority.** Lower numbers run first.
* **Title, code, description and tags** for each snippet.
* **List views:** All, Active, Inactive, Recently Active (deactivated in the last 7 days) and Trash.
* **Sorting** by name, created date, modified date, ID or priority, ascending or descending.
* **Search** across the name, code, description and tags.
* **Revisions** of the code, title and description.
* **Import and export as JSON** in the same format as Code Snippets, so you can move snippets between the two plugins in either direction. Import several files at once, and choose whether the snippets arrive inactive (the default), keep the status from the file, or are all activated.
* **Safe mode.** Stop all snippets with `define( 'SLIM_SNIPPETS_SAFE_MODE', true );` in wp-config.php, or add `?slim-snippets-safe-mode=1` to an admin URL while logged in.

Snippets are stored as a private post type, so the list, trash, search, sorting and revisions are WordPress's own screens.

Only administrators can manage snippets. On multisite, only super admins can, because snippets run PHP.

== Installation ==

1. Download `slim-snippets.zip` from the latest release on GitHub.
2. In WordPress, go to Plugins > Add New > Upload Plugin, choose the zip, and activate the plugin.
3. Go to Snippets > Add Snippet.

== Frequently Asked Questions ==

= How do I move my snippets from Code Snippets? =

1. In Code Snippets, select the snippets you want to keep and choose the Export bulk action.
2. Deactivate Code Snippets. While it runs the same snippets, their functions already exist, so activating the copies fails.
3. In Slim Snippets, go to Snippets > Import / Export, upload the file, and choose the status after import.
4. If you imported them inactive, activate the snippets you want, one at a time or with the Activate bulk action.

By default every snippet is imported inactive, so nothing runs until you activate it. You can instead keep the status from the file (trashed snippets go to the Trash) or activate every snippet. Activating runs the usual checks: a snippet that fails them stays inactive and is named in the notice. Run-once snippets are always imported inactive, so an import never runs them. Snippets with scopes that Slim Snippets does not support (shortcodes and HTML content) are skipped and named in the notice.

Code Snippets stores descriptions as HTML. On import they become plain text: paragraphs and line breaks are kept, list items become "- " lines, and links keep their URL in parentheses. The conversion is one way: if you export snippets back to Code Snippets, their descriptions stay plain text.

= A snippet broke my site. What now? =

Add `define( 'SLIM_SNIPPETS_SAFE_MODE', true );` to wp-config.php, fix or deactivate the snippet, then remove the line. On a single site, WordPress's recovery mode email also lets you log in with Slim Snippets paused.

= Why don't I get updates from WordPress.org? =

Slim Snippets is distributed on GitHub. The plugin's `Update URI` header stops WordPress.org from offering an unrelated plugin with the same name as an update.

= Can I add snippets with WP-CLI? =

Yes. Snippets are posts of type `slim_snippet`:

`wp post create code.php --post_type=slim_snippet --post_title="My snippet" --post_excerpt="What it does" --meta_input='{"_slim_snippets_scope":"global"}'`

That creates an inactive snippet. Activate it on the Snippets screen so it is checked first. Publishing from WP-CLI (`--post_status=publish`) only runs the syntax check.

= What happens to my snippets if I delete the plugin? =

They stay in the database. Reinstall the plugin and they come back.

== Credits ==

Slim Snippets is an independent plugin, but it owes several ideas to [Code Snippets](https://github.com/codesnippetspro/code-snippets) by Shea Bunge and Code Snippets Pro (GPL-2.0-or-later). No code was copied. Slim Snippets borrows these from Code Snippets:

* the JSON export format, used so files work in both plugins;
* the scope names (`global`, `admin`, `front-end`, `single-use`, `site-css`, `admin-css`, `site-head-js`, `site-footer-js`);
* running snippets with `eval()` and output buffering on `plugins_loaded` at priority 1;
* skipping the snippet that is being saved, so testing it again cannot declare its functions twice;
* the single-use scope;
* a safe-mode constant and URL parameter, and keeping that parameter on admin links.

Thank you to the Code Snippets authors.

== Changelog ==

= 1.0.7 =
* A "Snippets" link on the Plugins screen, before Deactivate.
* The Import / Export page has a larger drop zone for import files, and its browse button looks like the other WordPress buttons.
* The import notes on the Import / Export page are a bulleted list, with clearer wording about trashed snippets and migrating from Code Snippets.
* In the Extended view of the snippets list, a snippet without a description shows "No description" in grey italics instead of the start of its code.

= 1.0.6 =
* The Description box on the snippet screen is taller (7 rows).

= 1.0.5 =
* Saving a snippet shows "Snippet is active." or "Snippet is inactive." only when the status changed. Saving without a status change shows only "Snippet saved."
* The priority hint says "Lower runs sooner."

= 1.0.4 =
* Import options: import every snippet inactive (the default), keep the status from the file (including the Trash), or activate every snippet. Run-once snippets are never run by an import.
* Activation stops a snippet that declares a function or class that already exists, also in an active snippet that does not run on the current screen, instead of ending in a fatal error.
* Import notices name each skipped snippet and each snippet that could not be activated.
* The Snippets menu lists Import / Export before Tags.

= 1.0.3 =
* The Snippet box (scope, priority and the Save buttons) can no longer be hidden in Screen Options.
* Removed the Slug box from the snippet screen. Snippets have no URL.
* The Snippet box has the same padding as the other boxes.
* The activate button says "Save & Run Once" as soon as you choose the run-once scope.

= 1.0.2 =
* Code Snippets descriptions are converted from HTML to plain text on import. Paragraphs, lists and preformatted text keep their layout, and link URLs are kept in parentheses.
* Fixed the edit screen escaping the code and description twice. The description showed entities such as &lt;, and with the visual editor turned off, saving could write entities into the code.
* Descriptions are saved as typed. Where WordPress filters HTML (DISALLOW_UNFILTERED_HTML, or WP-CLI without --user), text such as "option_<key>" was deleted.
* Revisions keep the code and description exactly as saved, so restoring a revision no longer brings back filtered text.

= 1.0.1 =
* Imported snippets are always inactive, even if they were active in the export.

= 1.0.0 =
* First release.

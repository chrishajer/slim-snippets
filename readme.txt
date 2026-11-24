=== Slim Snippets ===
Contributors: chrishajer
Tags: snippets, code, php, css, javascript
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Run PHP, CSS and JavaScript snippets without editing functions.php. Small and fast, with no cloud, upsells or tracking.

== Description ==

Slim Snippets keeps the parts of a snippets plugin that matter day to day and leaves the rest out.

* **PHP snippets** that run everywhere, only in the admin, only on the front end, or once.
* **CSS and JavaScript snippets** printed in the front-end head or footer, or in the admin head.
* **Syntax-highlighting editor** (the CodeMirror editor built into WordPress). The mode follows the snippet's scope.
* **No activation with errors.** PHP is syntax-checked and test-run before it is activated. If it throws an error, or dies with a fatal error, it stays inactive and the error is shown. If an active snippet throws an error on a later page load, it deactivates itself and records the error.
* **Fast.** Active snippets are stored in one autoloaded option, so running them adds no database queries.
* **Priority.** Lower numbers run first.
* **Title, code, description and tags** for each snippet.
* **List views:** All, Active, Inactive, Recently Active (deactivated in the last 7 days) and Trash.
* **Sorting** by name, created date, modified date, ID or priority, ascending or descending.
* **Search** across the name, code, description and tags.
* **Revisions** of the code, title and description.
* **Import and export as JSON** in the same format as Code Snippets, so you can move snippets between the two plugins in either direction.
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
2. Deactivate Code Snippets. If it keeps running the same snippets, any function they declare would be declared twice and stop the import with a fatal error.
3. In Slim Snippets, go to Snippets > Import / Export and upload the file.

Snippets that were active in the export are activated, but only if they pass the same checks as activating by hand. Single-use snippets are imported inactive and never run during the import. Snippets with scopes that Slim Snippets does not support (shortcodes and HTML content) are skipped and counted in the notice.

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

= 1.0.0 =
* First release.

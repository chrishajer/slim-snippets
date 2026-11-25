=== Slim Snippets ===
Contributors: chrishajer
Tags: snippets, code, php, css, javascript
Requires at least: 6.0
Tested up to: 7.2
Requires PHP: 7.4
Stable tag: 1.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Run PHP, CSS and JavaScript snippets without editing functions.php. Small and fast, with no cloud, upsells or tracking.

== Description ==

Slim Snippets keeps the parts of a snippets plugin that matter day to day and leaves the rest out.

* **PHP snippets** that run everywhere, only in the admin, only on the front end, or once.
* **CSS and JavaScript snippets.** CSS is printed in the front-end head or the admin head. JavaScript is printed in the front-end head or footer.
* **Syntax-highlighting editor** (the CodeMirror editor built into WordPress). The mode follows the snippet's scope.
* **No activation with errors.** PHP is syntax-checked and test-run before it is activated. If it throws an error, dies with a fatal error, or declares a function or class that already exists, it stays inactive and the error is shown. If an active snippet throws an error on a later page load, the error is recorded and shown on the snippet, and the snippet stays active.
* **Fast.** Active snippets are stored in one autoloaded option, so running them adds no database queries.
* **Priority.** Lower numbers run first.
* **Title, code, description and tags** for each snippet.
* **List views:** All, Active, Inactive, Recently Active (deactivated in the last 7 days) and Trash.
* **Sorting** by name, created date, modified date, ID or priority, ascending or descending.
* **Search** across the name, code, description and tags.
* **Revisions** of the code, title and description.
* **Import and export as JSON** in the same format as Code Snippets, so you can move snippets between the two plugins in either direction. Import several files at once, and choose whether the snippets arrive inactive (the default), keep the status from the file, or are all activated.
* **Migrate from Code Snippets** on the same site with one click: review what will be copied, then Slim Snippets copies the snippets, deactivates the Code Snippets plugin, and activates the copies of its active snippets.
* **Updates from GitHub.** New releases show on the Plugins and Updates screens like other plugin updates, and auto-updates can be turned on.
* **Safe mode.** Stop all snippets with `define( 'SLIM_SNIPPETS_SAFE_MODE', true );` in wp-config.php, or stop them for your own requests only by adding `?slim-snippets-safe-mode=1` to any URL while logged in.

No custom database tables. Snippets are stored as a private post type, so the list, trash, search, sorting and revisions are WordPress's own screens.

Only administrators can manage snippets. On multisite, only super admins can, because snippets run PHP. If wp-config.php sets `DISALLOW_UNFILTERED_HTML` to true, nobody can manage snippets: that setting stops even administrators from adding their own JavaScript, and snippets add JavaScript and PHP. Snippets that are already active keep running.

== Installation ==

1. Download `slim-snippets.zip` from the latest release on GitHub.
2. In WordPress, go to Plugins > Add New > Upload Plugin, choose the zip, and activate the plugin.
3. Go to Snippets > Add Snippet.

== Frequently Asked Questions ==

= How do I move my snippets from Code Snippets? =

If the Code Snippets plugin is active on the same site, Slim Snippets can migrate them for you:

1. Go to Snippets > Import / Export. While the Code Snippets plugin is active, Migrate from Code Snippets is the first section.
2. Review the list. It shows each Code Snippets snippet, its status in Code Snippets, and whether it will be migrated.
3. Click the Migrate button. Slim Snippets copies the snippets, deactivates the Code Snippets plugin, and activates the copies of the snippets that were active in Code Snippets.

Trashed snippets, snippets with scopes that Slim Snippets does not support (shortcodes and HTML content), and snippets that use a Code Snippets condition are not migrated. A snippet that is already in Slim Snippets (same name and code) is not copied again; if that copy is inactive and the snippet is active in Code Snippets, the copy is activated. Run-once snippets are copied inactive. A copy with a syntax error, or one that declares a function or class that already exists, stays inactive and is named in the notice. The Code Snippets plugin and its snippets are not changed or deleted, so you can switch back by reactivating it. If the Code Snippets plugin is network-activated on multisite, it is not deactivated and every snippet is copied inactive.

To move snippets from Code Snippets on another site, export them there and import the file:

1. On the other site, in Code Snippets, select the snippets you want to keep and choose the Export bulk action.
2. On this site, go to Snippets > Import / Export, upload the file, and choose the status after import.
3. If you imported them inactive, activate the snippets you want, one at a time or with the Activate bulk action.

If the Code Snippets plugin is already active on this site, use the migration above instead. Code Snippets runs its active snippets on every page load. An imported copy that declares a function or class cannot be activated, because PHP does not allow one to be declared twice. A copy that declares neither can be activated, but it then runs twice: once from Code Snippets and once from Slim Snippets.

Run-once snippets are always imported inactive, and snippets with unsupported scopes (shortcodes and HTML content) are skipped.

Code Snippets stores descriptions as HTML. On import they become plain text: paragraphs and line breaks are kept, list items become "- " lines, and links keep their URL in parentheses. The conversion is one way: if you export snippets back to Code Snippets, their descriptions stay plain text.

= A snippet broke my site. What now? =

Add `define( 'SLIM_SNIPPETS_SAFE_MODE', true );` to wp-config.php, fix or deactivate the snippet, then remove the line. On a single site, WordPress's recovery mode email also lets you log in with Slim Snippets paused.

= How do I get updates? =

Slim Snippets updates from its releases on GitHub, not from WordPress.org. A new release shows on the Plugins and Updates screens like other plugin updates, and you can turn on auto-updates for it. WordPress checks every 12 hours; to check now, click Check again on Dashboard > Updates. Update checks go to api.github.com and raw.githubusercontent.com (for the release's readme.txt, which says which WordPress and PHP versions it works with) and do not include your site's address. The plugin's `Update URI` header stops WordPress.org from offering an unrelated plugin with the same name as an update.

Versions before 1.2.0 cannot update themselves. Install 1.2.0 from GitHub once, and later releases arrive as updates.

= Can I add snippets with WP-CLI? =

Yes. Snippets are posts of type `slim_snippet`:

`wp post create code.php --post_type=slim_snippet --post_title="My snippet" --post_excerpt="What it does" --meta_input='{"_slim_snippets_scope":"global"}'`

That creates an inactive snippet. Activate it on the Snippets screen so it is checked first. Publishing from WP-CLI (`--post_status=publish`) only runs the syntax check.

= What happens to my snippets if I delete the plugin? =

They stay in the database, so reinstalling the plugin brings them back. Deleting the plugin removes only its own options. To remove the snippets too, move them to the Trash and empty it before you delete the plugin.

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

= 1.2.0 =
* Updates from GitHub. New releases show on the Plugins and Updates screens like other plugin updates, View details shows the release notes, and auto-updates can be turned on.

Older versions are listed in changelog.txt.

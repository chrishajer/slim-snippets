# Slim Snippets

Run PHP, CSS and JavaScript snippets without editing `functions.php`. Lightweight and fast, with no cloud services, upsells or tracking.

Slim Snippets keeps the parts of a snippets plugin that matter day to day and leaves the rest out.

- **PHP snippets** that run everywhere, only in the admin, only on the front end, or once.
- **CSS and JavaScript snippets.** CSS is added to the front-end or admin head. JavaScript is added to the front-end head or footer.
- **Priority.** Lower numbers run first.
- **Syntax-highlighting editor** (the CodeMirror editor built into WordPress). The mode follows the snippet's scope.
- **Activation checks.** PHP is syntax-checked and test-run before it is activated. If it throws an error, dies with a fatal error, or declares a function or class that already exists, it stays inactive and the error is shown.
- **Errors after activation.** If an active snippet throws an error on a later page load, the error is recorded and shown on the snippet. The snippet stays active, so a visitor cannot switch it off by making it fail.
- **Safe mode.** Stop all snippets with `define( 'SLIM_SNIPPETS_SAFE_MODE', true );` in `wp-config.php`, or stop them for your own requests only by adding `?slim-snippets-safe-mode=1` to any URL while logged in as a user who can manage snippets.
- **Fast.** Active snippets are stored in one autoloaded option, so running them adds no database queries.
- **Title, code, description and tags** for each snippet.
- **List views:** All, Active, Inactive, Recently Active (snippets deactivated in the last 7 days) and Trash.
- **Sorting** by name, created date, modified date, ID or priority, ascending or descending.
- **Search** across the name, code, description and tags.
- **Revisions** of the code, title and description.
- **Import and export as JSON** in the same format as Code Snippets, so you can move snippets between the two plugins in either direction. Import several files at once, and choose whether the snippets arrive inactive (the default), keep the status from the file, or are all activated.
- **Migrate from Code Snippets** on the same site with one click: review and choose what will be copied, then Slim Snippets copies the selected snippets, deactivates the Code Snippets plugin, and activates the copies of its active snippets.
- **Updates from GitHub.** New releases show on the Plugins and Updates screens like other plugin updates, and auto-updates can be turned on.

No custom database tables. Snippets are stored as a private post type, so the list, trash, search, sorting and revisions are WordPress's own screens.

Only administrators can manage snippets. On multisite, only super admins can, because snippets run PHP.

If `wp-config.php` sets `DISALLOW_UNFILTERED_HTML` to true, nobody can manage snippets. That setting stops even administrators from adding their own JavaScript, and snippets add JavaScript and PHP. Snippets that are already active keep running.

## Install

1. Download `slim-snippets.zip` from the [latest release](https://github.com/chrishajer/slim-snippets/releases/latest) on GitHub.
2. In WordPress, go to Plugins > Add New > Upload Plugin, choose the zip, and activate the plugin.
3. Go to Snippets > Add Snippet.

## Updates

Slim Snippets updates from its releases on GitHub, not from WordPress.org. A new release shows on the Plugins and Updates screens like other plugin updates, and you can turn on auto-updates for it. WordPress checks every 12 hours; to check now, click Check again on Dashboard > Updates. Update checks go to api.github.com and raw.githubusercontent.com (for version requirements) and do not include your site's address. The plugin's `Update URI` header stops WordPress.org from offering an unrelated plugin with the same name as an update.

## Safe mode

Safe mode stops Slim Snippets from running any snippets: no PHP runs, and no CSS or JavaScript is added. Snippets are not changed or deactivated, so turning safe mode off brings everything back as it was. Use it when a snippet breaks the site, so you can get in and fix or deactivate it.

There are two ways to turn it on:

- **For the whole site:** add `define( 'SLIM_SNIPPETS_SAFE_MODE', true );` to `wp-config.php`. Snippets stop for every visitor and every request until you remove the line. Use this when a snippet takes the site down or stops you from logging in.
- **For yourself only:** while logged in as a user who can manage snippets, add `?slim-snippets-safe-mode=1` to a URL, for example `/wp-admin/edit.php?post_type=slim_snippet&slim-snippets-safe-mode=1`. Snippets stop only for your requests; visitors and other users still get them. Admin links keep the parameter, so you stay in safe mode as you click around, and each admin screen shows a notice that safe mode is on. Use this when a snippet breaks an admin screen, or to view a page without snippets. It does not work on the login screen, because you are not logged in there yet; use the `wp-config.php` line instead.

On a single site, if a snippet causes a fatal error, WordPress may also email the site admin a recovery mode link, which logs you in with the whole Slim Snippets plugin paused.

## FAQ and changelog

The FAQ in [`readme.txt`](readme.txt) covers moving snippets from Code Snippets (what is and is not migrated), adding snippets with WP-CLI, and what happens to your snippets if you delete the plugin. Every release is listed in [`changelog.txt`](changelog.txt).

## Self-check

On a test site with the plugin active:

```sh
wp eval-file tests/check.php --user=<admin>
```

It creates temporary snippets, checks activation, the run order, the runner's error handling and import/export, then deletes them.

## Credits

Slim Snippets is an independent plugin, but it owes several ideas to [Code Snippets](https://github.com/codesnippetspro/code-snippets) by Shea Bunge and Code Snippets Pro (GPL-2.0-or-later). No code was copied. See the Credits section in [`readme.txt`](readme.txt) for what Slim Snippets borrows from it.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).

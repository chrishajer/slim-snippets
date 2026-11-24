# Slim Snippets

A small WordPress plugin for running PHP, CSS and JavaScript snippets without editing `functions.php`. It has no cloud features, upsells or tracking.

- PHP snippets: run everywhere, admin only, front-end only, or once
- CSS and JS snippets: front-end head or footer, or the admin head
- WordPress's built-in CodeMirror editor
- PHP is syntax-checked, checked for functions and classes that already exist, and test-run before activation, so a snippet with errors stays inactive
- Active snippets live in one autoloaded option, so running them adds no queries
- Priority, tags, description, revisions
- All / Active / Inactive / Recently Active / Trash views; sort by name, created, modified, ID or priority; search across code, description and tags
- JSON import and export, compatible with Code Snippets in both directions; import inactive (the default), keep the file's status, or activate everything
- One-click migration from Code Snippets on the same site: while Code Snippets is active, Snippets > Import / Export lists each of its snippets and what will happen to it, and one click copies them, deactivates Code Snippets and activates the copies of its active snippets. See the FAQ in `readme.txt` for what is not migrated.
- Safe mode stops all snippets while you fix one that broke the site (see [Safe mode](#safe-mode))

## Install

Download `slim-snippets.zip` from the [latest release](https://github.com/chrishajer/slim-snippets/releases/latest) and upload it under Plugins > Add New > Upload Plugin.

## Safe mode

Safe mode stops Slim Snippets from running any snippets: no PHP runs, and no CSS or JavaScript is printed. Snippets are not changed or deactivated, so turning safe mode off brings everything back as it was. Use it when a snippet breaks the site, so you can get in and fix or deactivate it.

There are two ways to turn it on:

- **For the whole site:** add `define( 'SLIM_SNIPPETS_SAFE_MODE', true );` to `wp-config.php`. Snippets stop for every visitor and every request until you remove the line. Use this when a snippet takes the site down or stops you from logging in.
- **For yourself only:** while logged in as a user who can manage snippets, add `?slim-snippets-safe-mode=1` to a URL, for example `/wp-admin/edit.php?post_type=slim_snippet&slim-snippets-safe-mode=1`. Snippets stop only for your requests; visitors and other users still get them. Admin links keep the parameter, so you stay in safe mode as you click around, and each admin screen shows a notice that safe mode is on. Use this when a snippet breaks an admin screen, or to view a page without snippets. It does not work on the login screen, because you are not logged in there yet; use the `wp-config.php` line instead.

On a single site, if a snippet causes a fatal error, WordPress may also email the site admin a recovery mode link, which logs you in with the whole Slim Snippets plugin paused.

## Self-check

On a test site with the plugin active:

```sh
wp eval-file tests/check.php --user=<admin>
```

It creates temporary snippets, checks activation, the run order, the runner's error handling and import/export, then deletes them.

## Credits

Several ideas, the scope names and the export format come from [Code Snippets](https://github.com/codesnippetspro/code-snippets) by Shea Bunge and Code Snippets Pro (GPL-2.0-or-later). See the Credits section in `readme.txt`.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).

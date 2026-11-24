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
- One-step migration from Code Snippets on the same site: review the list, then copy the snippets, deactivate Code Snippets and activate the copies of its active snippets
- Safe mode: `define( 'SLIM_SNIPPETS_SAFE_MODE', true );` or `?slim-snippets-safe-mode=1`

## Install

Download `slim-snippets.zip` from the [latest release](https://github.com/chrishajer/slim-snippets/releases/latest) and upload it under Plugins > Add New > Upload Plugin.

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

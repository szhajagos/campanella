# Modules

Each folder here is a module: a `module.json` and its code (since 0.4.6). Put a
module's folder here by hand (FTP, git, Composer); it is never uploaded through
the browser. It is checked, tried, then turned on and off by an administrator under
*System → Modules* (on the command line: `module:list`, `module:enable <name>`,
`module:disable <name>`).

`config/local.php` can turn one off whatever the admin says:
`'modules' => ['disabled' => ['newsletter']]`.

A module is program code with every right: only add one whose source you trust.
How to write one: [docs/php-api/32-modules.md](../docs/php-api/32-modules.md).

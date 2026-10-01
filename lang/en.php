<?php

declare(strict_types=1);

/*
 * User-facing texts, English (the base language). Every key must also exist
 * in the other language files; `composer lang:check` reports differences.
 * Parameters are written as {name}.
 */
return [
    // Site frame (templates/base.html.twig)
    'site.nav.label' => 'Main menu',
    'site.nav.home' => 'Home',
    'site.nav.news' => 'News',
    'site.nav.categories' => 'Categories',
    'site.nav.about' => 'About us',
    'site.nav.toggle' => 'Toggle navigation',

    // Content lists
    'content.empty' => 'Nothing to show.',
    'content.more' => 'Read more',
    'content.pager' => 'Pages',

    // Login
    'auth.login' => 'Log in',
    'auth.logout' => 'Log out',
    'auth.email' => 'E-mail address',
    'auth.password' => 'Password',
    'auth.invalid_credentials' => 'Invalid e-mail address or password.',
    'auth.account_blocked' => 'The account is blocked.',
    'auth.too_many_attempts' => 'Too many failed attempts. Try again in {minutes} minutes.',
    'auth.form_expired' => 'The form has expired. Please try again.',

    // Error pages
    'error.title' => 'An error occurred',
    'error.not_found_title' => 'Page not found',
    'error.back_home' => 'Back to the home page',
    'error.not_found' => 'The page was not found.',
    'error.not_installed' => 'Campanella is not installed yet. Run: php bin/campanella install',
    'error.needs_upgrade' => 'The database needs an upgrade (new version). Run: php bin/campanella install',
    'error.internal' => 'An internal error occurred.',

    // Validation messages (ValidationException)
    'validation.required' => 'required field',
    'validation.too_many_values' => 'at most {max} values can be given',
    'validation.value_too_long' => 'a value can be at most {max} characters',
    'validation.taken' => 'this value is already taken ({value})',
    'validation.unique_conflict' => 'unique key conflict',
    'validation.relation_required' => 'required relation',
    'validation.too_many_relations' => 'at most {max} relations can be given',
    'validation.self_reference' => 'the object cannot point to itself',
    'validation.target_missing' => 'target (#{id}) does not exist',
    'validation.target_blueprint' => 'target (#{id}) is of type {blueprint}, but it can only be: {allowed}',
    'validation.target_capability' => 'target (#{id}) lacks this capability: {missing}',
    'validation.invalid_email' => 'invalid e-mail address',
    'validation.password_too_short' => 'must be at least {min} characters long',
    'validation.password_too_long' => 'can be at most {max} bytes long',
    'validation.invalid_role' => 'invalid role name: {role}',

    // Command line (bin/campanella)
    'cli.usage' => 'Usage: php bin/campanella <command>',
    'cli.install.description' => 'Creates the database tables (--sql: only prints the SQL)',
    'cli.install.server' => 'Database server: {version}',
    'cli.install.done' => 'Done. For sample content: php bin/campanella seed',
    'cli.seed.description' => 'Creates sample content (can be run repeatedly)',
    'cli.seed.categories' => '{path} → categories: {categories}',
    'cli.seed.draft' => 'draft',
    'cli.seed.done' => 'Done: {total} objects, {public} of them publicly visible.',
    'cli.status.description' => 'Version, capabilities, Blueprints and object count',
    'cli.status.capabilities' => 'Capabilities:',
    'cli.status.blueprints' => 'Blueprints:',
    'cli.status.fields' => 'fields',
    'cli.status.table' => 'table',
    'cli.status.not_installed' => 'The database is not installed yet (php bin/campanella install).',
    'cli.status.needs_upgrade' => 'The database schema ({database}) is older than the code ({code}): run php bin/campanella install.',
    'cli.status.objects' => 'Objects: {total} in total, {public} of them publicly visible.',
    'cli.user.not_found' => 'No such user: {email}',
    'cli.user.active' => 'active',
    'cli.user.blocked' => 'blocked',
    'cli.user.roles' => 'roles: {roles}',
    'cli.user_create.description' => 'Creates a user: <e-mail> [--name="Name"] [--role=administrator,editor]',
    'cli.user_create.missing_email' => 'Give the e-mail address: php bin/campanella user:create <e-mail> [--name="Name"] [--role=administrator]',
    'cli.user_create.exists' => 'There is already a user with this e-mail address: {email}',
    'cli.user_create.created' => 'Created: #{id} {name} <{email}>',
    'cli.user_password.description' => 'Sets a password: <e-mail> (or --block / --activate)',
    'cli.user_password.done' => 'New password set: {email}',
    'cli.user_list.description' => 'Lists the users',
    'cli.user_list.empty' => 'There are no users yet. To create one: php bin/campanella user:create <e-mail> --role=administrator',
    'cli.password.prompt' => 'Password (at least {min} characters):',
    'cli.password.again' => 'Password again:',
    'cli.password.mismatch' => 'The two passwords do not match.',
    'cli.password.too_short' => 'The password must be at least {min} characters long.',

    // Labels of capabilities, fields, relations, Blueprints and lists
    'capability.titled' => 'With a title',
    'capability.textual' => 'Textual',
    'capability.routable' => 'With a path',
    'capability.publishable' => 'Publishable',
    'capability.identifiable' => 'Identifiable',
    'capability.authenticatable' => 'Can log in',
    'capability.authorable' => 'With an author',
    'field.title' => 'Title',
    'field.body' => 'Body',
    'field.path' => 'Path',
    'field.status' => 'Status',
    'field.published_at' => 'Publication time',
    'field.email' => 'E-mail address',
    'field.password_hash' => 'Password (hash)',
    'field.account_status' => 'Account status',
    'field.roles' => 'Roles',
    'field.lead' => 'Lead',
    'relation.author' => 'Author',
    'relation.categories' => 'Categories',
    'blueprint.article' => 'Article',
    'blueprint.page' => 'Page',
    'blueprint.user' => 'User',
    'blueprint.category' => 'Category',
    'list.category.articles' => 'Articles in this category',

    // Administration (admin UI)
    'admin.title' => 'Administration',
    'admin.link' => 'Admin',
    'admin.dashboard' => 'Dashboard',
    'admin.back_to_site' => 'Back to the site',
    'admin.nav.label' => 'Administration menu',
    'admin.content' => 'Content',
    'admin.count' => 'Items',
    'admin.recent' => 'Recently modified',
    'admin.recent_empty' => 'No content yet.',
    'admin.column.title' => 'Title',
    'admin.column.type' => 'Type',
    'admin.column.updated' => 'Modified',
    'admin.untitled' => '(untitled)',

    // Errors of the admin
    'error.forbidden' => 'You do not have permission to view this page.',
    'error.forbidden_title' => 'Access denied',
    'admin.status.draft' => 'Draft',
    'admin.status.published' => 'Published',
    'admin.status.scheduled' => 'Scheduled',
];

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
];

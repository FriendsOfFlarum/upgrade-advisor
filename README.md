# FoF Upgrade Advisor

![License](https://img.shields.io/badge/license-MIT-blue.svg) [![Latest Stable Version](https://img.shields.io/packagist/v/fof/upgrade-advisor.svg)](https://packagist.org/packages/fof/upgrade-advisor) [![Total Downloads](https://img.shields.io/packagist/dt/fof/upgrade-advisor.svg)](https://packagist.org/packages/fof/upgrade-advisor)

A [Flarum](https://flarum.org) extension by [FriendsOfFlarum](https://github.com/FriendsOfFlarum). Check your forum's readiness for the next Flarum major version.

Adds an admin page that runs a series of readiness checks for **Flarum 2.0** and shows an overall verdict, so you know what to address before upgrading.

## Checks

- **PHP version** — fails below PHP 8.3.
- **Database version** — detects MySQL vs MariaDB and applies two tiers:
  - **Fails** below the version required for JSON column support (MySQL 5.7.8, MariaDB 10.2.7), which Flarum 2.0 relies on.
  - **Warns** below the recommended version (MySQL 8.4, MariaDB 11.8) to encourage staying on a modern, supported release.
- **Extension compatibility** — for every **installed** extension (enabled or disabled — either will block the upgrade), determines whether a Flarum 2.0-compatible release exists. Each extension resolves to one of:
  - **Compatible** — a published release supports `flarum/core ^2.0` (pre-releases count).
  - **Incompatible** — no compatible release yet.
  - **Abandoned** — flagged by Flarum core or its support thread; if a replacement is suggested, its own 2.0 readiness is checked and shown.
  - **Superseded** — functionality is now built into core, or the extension has moved to a different package (curated list).
  - **Could not check** — a premium or private-repository extension that isn't on public Packagist (see below).

Compatibility is determined from **Packagist** and cross-referenced against the extension's official support discussion tags on discuss.flarum.org. For extensions the advisor can't resolve, it surfaces the support thread and author contact links so you can ask the author about their 2.0 plans.

## Private &amp; premium repositories

Extensions installed from private sources aren't on public Packagist, so the advisor can't check them by default. Under the **Repositories** tab you can add:

- **Floxum** — just paste your access token.
- **Private Packagist / custom Composer repositories** — provide the repository URL, username, and token.

Each repository has a **Test connection** button to verify the credentials before saving. Configured repositories are only queried for extensions that public Packagist can't resolve.

## Adding your own checks

Other extensions can register additional checks via the `Checks` extender in their `extend.php`:

```php
use FoF\UpgradeAdvisor\Extend\Checks;

return [
    (new Checks())
        ->add(\Acme\MyExtension\Check\MyCustomCheck::class),
];
```

Each registered class must implement `FoF\UpgradeAdvisor\Check\Check` and is resolved from the container, so it may type-hint any services it needs.

## Installation

Install with composer:

```sh
composer require fof/upgrade-advisor:"*"
```

## Updating

```sh
composer update fof/upgrade-advisor:"*"
php flarum cache:clear
```

Once your forum reports as ready and you've upgraded to Flarum 2.0, this extension has done its job and can be removed.

## Links

- [Packagist](https://packagist.org/packages/fof/upgrade-advisor)
- [GitHub](https://github.com/FriendsOfFlarum/upgrade-advisor)
- [Discuss](https://discuss.flarum.org/d/39500)
- [Report an issue](https://github.com/FriendsOfFlarum/upgrade-advisor/issues)

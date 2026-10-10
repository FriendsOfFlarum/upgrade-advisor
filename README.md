# FoF Upgrade Advisor

![License](https://img.shields.io/badge/license-MIT-blue.svg) [![Latest Stable Version](https://img.shields.io/packagist/v/fof/upgrade-advisor.svg)](https://packagist.org/packages/fof/upgrade-advisor) [![Total Downloads](https://img.shields.io/packagist/dt/fof/upgrade-advisor.svg)](https://packagist.org/packages/fof/upgrade-advisor)

A [Flarum](https://flarum.org) extension by [FriendsOfFlarum](https://github.com/FriendsOfFlarum). Check your forum's readiness for the next Flarum major version.

Adds an admin page that runs a series of readiness checks for an upcoming Flarum release and shows an overall verdict, so you know what to address before upgrading.

## Current status: nothing to check

Your forum meets the requirements of every Flarum release this advisor knows about, so the admin page simply says there is nothing to check. When a release with new requirements is announced, an update to Upgrade Advisor will check your forum's readiness for it.

Unlike the 1.x version, this release doesn't need removing before you upgrade Flarum: it supports Flarum 2.x and goes quiet by itself once your forum reaches the version it checks for.

## Checks

When a release is being targeted, the advisor checks:

- **PHP version**: fails below the target's minimum.
- **Database version**: detects MySQL, MariaDB, PostgreSQL or SQLite. If the target sets requirements for that database, it **fails** below the minimum and **warns** below the recommended version.
- **Extension compatibility**: for every **installed** extension (enabled or disabled; either will block the upgrade), determines whether a release supporting the target exists. Each extension resolves to one of:
  - **Compatible**: a published release supports the target (pre-releases count).
  - **Incompatible**: no compatible release yet, including extensions pinned to an older version (e.g. `~2.0.0` when the target is 2.1).
  - **Abandoned**: flagged by Flarum core or its support thread; if a replacement is suggested, its own readiness is checked and shown.
  - **Superseded**: functionality is now built into core, or the extension has moved to a different package (curated list, [extendable](#adding-your-own-superseded-extensions)).
  - **Could not check**: a premium or private-repository extension that isn't on public Packagist (see below).

Compatibility is determined from **Packagist** and, for a new major version, cross-referenced against the version tags on the extension's support discussion on discuss.flarum.org. For extensions the advisor can't resolve, it surfaces the support thread and author contact links so you can ask the author about their plans.

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

## Adding your own superseded extensions

The **Superseded** status uses a curated list of extensions that should be removed before upgrading, either because their functionality moved into core or because they were replaced by a different package. That list only covers packages we know about, so forums running private or first-party extensions can declare their own migration paths with the `Superseded` extender:

```php
use FoF\UpgradeAdvisor\Extend\Superseded;

return [
    (new Superseded())
        ->replaced('acme/translate', 'acme/translate-next')
        ->intoCore('acme/nightmode'),
];
```

- `replaced($package, $replacement)`: the extension moved to a different package. The report tells the admin to remove it before upgrading and install the replacement afterwards, and checks whether that replacement supports the target.
- `intoCore($package)`: the functionality is now part of core, so the extension is simply removed with nothing installed in its place.

To declare several at once, `add()` takes a map of package name to replacement, using `null` where nothing replaces it:

```php
(new Superseded())
    ->add([
        'acme/translate' => 'acme/translate-next',
        'acme/nightmode' => null,
    ]),
```

Entries you register take precedence over the bundled list, so this can also correct a mapping the advisor ships with. Because these extensions are reported as **Superseded**, they're flagged as needing action even if they do publish a compatible release.

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

## Targeting a new release (maintainers)

See [MAINTAINING.md](MAINTAINING.md) for how to wake the advisor up when the requirements of the next Flarum release are known.

## Links

- [Packagist](https://packagist.org/packages/fof/upgrade-advisor)
- [GitHub](https://github.com/FriendsOfFlarum/upgrade-advisor)
- [Discuss](https://discuss.flarum.org/d/39500)
- [Report an issue](https://github.com/FriendsOfFlarum/upgrade-advisor/issues)

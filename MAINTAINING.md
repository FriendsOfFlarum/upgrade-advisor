# Targeting a new Flarum release

How to wake Upgrade Advisor up when the requirements of an upcoming Flarum release are known, whether that release is a minor (2.1, 2.4) or a new major (3.0).

Between targets the advisor is dormant: `Targets::next()` returns `null`, and the admin page only says there is nothing to check. Waking it up is a code change and a release; there is no admin setting for it.

## Before you start

Collect the target's requirements from Flarum's own sources:

- **PHP**: the `php` constraint in `framework/core/composer.json` on the release branch.
- **Databases** (MySQL, MariaDB, PostgreSQL, SQLite): the server requirements in the Flarum docs for that release.
- **Extensions built into core or replaced**: the release notes and upgrade guide.

## 1. Set the target

In [`src/Targets.php`](src/Targets.php), return the target from `next()`:

```php
public static function next(): ?Target
{
    return new Target('2.2.0', phpMinimum: '8.4.0');
}
```

`Target` takes:

| Argument | Required | Meaning |
|---|---|---|
| `version` | yes | The first release of the target, as a full version: `'2.2.0'`, `'3.0.0'`. Extension constraints are tested against it. |
| `phpMinimum` | yes | Below this, the PHP check fails. |
| `databases` | no | Floors per engine, keyed `mysql`, `mariadb`, `pgsql`, `sqlite`, each `[minimum, recommended]` (recommended optional). Below the minimum the database check fails; below the recommended version it warns. Any other key throws, so a typo can't silently drop a requirement. |

A target with database floors:

```php
return new Target('3.0.0', '8.4.0', [
    'mysql' => ['8.0.0', '8.4.0'],
    'mariadb' => ['10.11.0', '11.8.0'],
    'pgsql' => ['13.0', '16.0'],
    'sqlite' => ['3.35.0'],
]);
```

The check works out the engine from the connection's driver, and recognises a MariaDB server connected through the `mysql` driver from its version string.

**Requirements are absolute, not a delta.** Forums skip versions, so a forum on 2.0 may jump straight to 2.4. Give the full requirements of the target release, including anything an earlier release raised: if 2.2 raised PHP to 8.4, a 2.4 target still says `phpMinimum: '8.4.0'`.

Leave an engine out only when no release between the current major's `.0` and the target has raised its floors. For an engine with no floors, the database check reports the server version and passes.

## 2. Add superseded extensions

If the target builds an extension into core, or the extension has moved to a different package, add it to `MAP` in [`src/SupersededExtensions.php`](src/SupersededExtensions.php):

```php
protected const array MAP = [
    'acme/dark-mode' => [
        'reason' => self::INTO_CORE,
    ],
    'acme/realtime' => [
        'reason' => self::REPLACED,
        'replacement' => 'flarum/realtime',
    ],
];
```

- Superseded entries win over Packagist: a listed extension is reported as needing removal even if it has a compatible release.
- Entries accumulate across targets, again because forums skip versions. Remove one only when the package can no longer be installed on any core version the advisor supports.
- Abandoned extensions don't belong here. They come from [flarum/abandoned-extensions](https://github.com/flarum/abandoned-extensions), which the advisor reads through core.

## 3. Update the tripwire tests

Two tests deliberately fail when the target or curated list changes, so the change is never accidental:

- `tests/unit/TargetsTest.php` → `this_release_has_no_target_so_the_advisor_is_dormant`: assert the new target instead, e.g. `$this->assertSame('2.2.0', Targets::next()->version);`, and rename the test to match.
- `tests/unit/SupersededExtensionsTest.php` → `this_release_ships_no_curated_entries`: assert the new entries.

Then run, from the package directory:

```sh
composer test:unit
composer test:integration
composer analyse:phpstan
```

The integration tests bind their own target, so they don't depend on `Targets::next()`.

## 4. For a new major only

- **Make sure the advisor can't block the upgrade it advises on.** Before the new core major ships, publish an advisor release that installs on it (a dormant one is enough). The 1.x advisor blocked `composer update` to 2.0 until a 2.x release existed.
- **Check Discuss tags.** Discuss version tags (`version-3x`) settle extensions that no repository can answer for, so check the tag exists on discuss.flarum.org. For minors the tags are ignored, because every 2.x extension carries `version-2x`.

## 5. Release

Release a new minor version of `fof/upgrade-advisor`, and announce the target in the [support discussion](https://discuss.flarum.org/d/39500).

## What happens next

You don't need to change anything once the target ships:

- A forum below the target sees the readiness report.
- A forum already at or past the target sees the "nothing to check" notice again. A forum on a pre-release of the target (`2.2.0-beta.1`) still sees the report.

When the next target is announced, start again from step 1, replacing the version and keeping the requirements absolute.

## Adding a new kind of requirement

When a target needs something beyond PHP and database versions, such as a required PHP extension:

1. Add the requirement to `Target` as an optional constructor argument, so earlier targets stay valid.
2. Write a check implementing `FoF\UpgradeAdvisor\Check\Check`, type-hinting `Target` in its constructor. Pass when the target leaves the requirement `null`, as `DatabaseVersionCheck` does.
3. Register it in the `fof-upgrade-advisor.checks` list in `UpgradeAdvisorServiceProvider`.
4. Add `fof-upgrade-advisor.admin.checks.<id>` translations: `title` and one string per status (`pass`, `warning`, `fail`, or `warning_<type>` when the result's meta has a `warningType`). Add a `chip` for a custom header label. The admin page and the CSV export both read these keys, with `{current}`, `{required}`, `{recommended}` and `{target}` available.

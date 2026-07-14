=== Subscriptions Migration Suite for WooCommerce ===
Contributors: shameemreza
Tags: woocommerce, subscriptions, migration, import, export
Requires at least: 6.4
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Export, import, and migrate subscriptions into WooCommerce Subscriptions from third-party subscription plugins.

== Description ==

Subscriptions Migration Suite for WooCommerce helps you move subscription data safely into WooCommerce Subscriptions.

* Scan your site for subscription data from other subscription plugins, even inactive ones.
* Payment continuity reporting: know before you migrate which subscriptions keep automatic renewals.
* Migrate subscriptions from supported source plugins into WooCommerce Subscriptions, in the background with resume.
* Convert source subscription products into WooCommerce Subscriptions products.
* Double-billing protection: migrated subscriptions are held from renewals until you confirm cutover.
* Export and import WooCommerce Subscriptions data between environments, including schedules, payment meta, and taxes.
* Roll back everything a migration or import created, without touching source data.
* Verify a finished migration: counts, schedules, renewal actions, and gateway health.
* WP-CLI commands for every step.

= WP-CLI commands =

Every step works from the command line. Migration and import commands run as a dry run by default; pass --live to write.

* `wp wcsms scan` - find migratable subscription data and its payment continuity outlook.
* `wp wcsms migrate <source>` - migrate a source into WooCommerce Subscriptions, with --background for large stores.
* `wp wcsms convert-products <source>` - convert source subscription products, including variable products.
* `wp wcsms cutover <source>` - remove the source plugin's renewal jobs and release held subscriptions.
* `wp wcsms verify <source>` - reconcile a finished migration and check that every subscription can bill.
* `wp wcsms rollback --source=<source>` - remove everything a migration created; also accepts --run=<run_id>.
* `wp wcsms import <file>` - import a JSON Lines file, with --background for large files.
* `wp wcsms export <file>` - export subscriptions, with filters and opt-in payment meta.
* `wp wcsms runs` - list background runs with progress and tallies.
* `wp wcsms resume <run_id>` - resume an interrupted background run from its checkpoint.

Supported migration sources:

* Flexible Subscriptions (WP Desk)
* Subscriptions For WooCommerce (WP Swings)
* YITH WooCommerce Subscription
* WPSubscription (ConversWP)
* Sublium (FunnelKit)

Requires WooCommerce. Migration requires WooCommerce Subscriptions.

== Frequently Asked Questions ==

= Does the source plugin need to be active? =

No. The scanner and migration read source data directly from the database, so migration works with the source plugin deactivated. Deactivating the source before migrating is recommended, since it prevents the source plugin from creating renewals during the move.

= Will my customers be charged twice? =

No. Imported subscriptions are held from scheduling renewals until you confirm cutover, and the source plugin's renewal jobs are removed at cutover.

= Does it modify or delete source data? =

No. Source data is only read, never changed. Rollback removes only what a migration run created.

== Changelog ==

= 0.1.0 =
* Initial release.
* Scanner with payment continuity report for five source plugins: Flexible Subscriptions, Subscriptions For WooCommerce (WP Swings), YITH WooCommerce Subscription, WPSubscription, and Sublium.
* Migration adapters for all five sources, with background processing, resume, and idempotent re-runs.
* Streaming JSON Lines export and import with round-trip fidelity, including tax rate mapping.
* Product conversion, double-billing cutover guard, and rollback.
* Verify, rollback, and renewal order history linking.
* Admin screen under WooCommerce plus WP-CLI commands for every step.

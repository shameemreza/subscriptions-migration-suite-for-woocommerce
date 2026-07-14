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
* Migrate subscriptions from supported source plugins into WooCommerce Subscriptions.
* Export and import WooCommerce Subscriptions data between environments.
* Payment continuity reporting: know before you migrate which subscriptions keep automatic renewals.
* WP-CLI support for scripted migrations.

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
* Initial release: source scanner (admin page and WP-CLI), WooCommerce settings section.

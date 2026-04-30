=== FluentCRM Company Rollups ===
Contributors: wemakegood
Tags: fluentcrm, crm, companies, rollup, aggregation
Requires at least: 5.8
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 0.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Roll up contact-level custom field data into the FluentCRM company profile view.

== Description ==

FluentCRM Company Rollups adds a configurable "rollup" section to the FluentCRM company profile page. For each company, the section displays aggregated values across all contacts whose primary company is that company — sums of donation totals, the most recent contact activity date, the count of contacts with a particular field set, and so on.

Aggregations are computed live each time a company profile is viewed. There is no stored cache to keep in sync, no cron job, no schema modification.

= Configurable per-field =

In the plugin's settings page, choose which FluentCRM contact custom fields should appear in the company profile section. For each selected field, choose an aggregation appropriate to its type:

* Numeric fields — sum, average, maximum, minimum, count of contacts with a value
* Date / date-time fields — most recent, earliest, count of contacts with a value
* Single-choice fields (radio, single-select) — count of contacts with any value, most common value
* Multi-choice fields (checkbox, multi-select) — count of contacts with any value
* Text and textarea fields — excluded (no useful aggregation)

The display label for each field can be overridden, and the section title shown on the company profile is configurable.

= How aggregation is scoped =

Rollups aggregate across contacts whose primary `company_id` matches the company being viewed. The many-to-many `fc_subscriber_pivot` company associations are intentionally not included to avoid double-counting.

== Installation ==

1. Upload the plugin folder to `wp-content/plugins/` or install via the WordPress plugin admin.
2. Ensure FluentCRM is installed and active.
3. Activate FluentCRM Company Rollups.
4. Visit Settings → Company Rollups to choose fields and aggregations.

== Frequently Asked Questions ==

= Does this work without FluentCRM? =

No. FluentCRM is a hard dependency.

= Does this modify the FluentCRM database? =

No. Rollups are computed at view time. The plugin only writes to two WordPress options storing its own configuration.

= Can I see rollups in the company list, or filter on them? =

Not in this version. FluentCRM does not expose extension points for company list columns or segment filters that would allow virtual fields to appear there.

== Changelog ==

= 0.2.0 =
* New filter: `fcr_excluded_field_slugs` lets other plugins remove specific contact custom field slugs from rollup configuration and computation. Useful for fields whose values are mirrored from the company record (e.g. enrichment fields), where aggregating across contacts would always return the mirrored value and be meaningless.

= 0.1.0 =
* Initial release.
* Configurable section title and per-field aggregation.
* Numeric, date, and choice-field aggregations.

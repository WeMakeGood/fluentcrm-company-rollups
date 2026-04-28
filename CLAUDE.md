# CLAUDE.md — FluentCRM Company Rollups

This file briefs future Claude sessions working on this plugin. It is not user documentation — it is engineering context.

## What this plugin does

Adds a "Company Rollups" section to the FluentCRM company profile view that aggregates contact-level custom field values across all contacts whose **primary** `company_id` matches the company being viewed. Per-field aggregation choice (sum, avg, max, min, count, latest date, earliest date, most-common value) configurable in the admin.

## Why these architectural decisions

### Real-time computation, not stored aggregates

Rollups are computed live on each company profile view. Two reasons:

1. No schema additions, no coherence problem to manage when the upstream data changes.
2. The query is one indexed join (`fc_subscribers.company_id` → `fc_subscriber_meta` filtered by `key`), and only fires when an admin opens that specific company's profile section.

If scale eventually demands stored aggregates, the trigger to recompute would be `fluent_crm/contact_custom_data_updated` (see `docs/fluentcrm-hooks-research.md`). Don't add caching speculatively — wait for evidence.

### Primary `company_id`, not the pivot table

FluentCRM has two contact-to-company relationships:

- `fc_subscribers.company_id` — single primary company per contact
- `fc_subscriber_pivot` with `object_type = 'FluentCrm\App\Models\Company'` — many-to-many

The original spec required primary-only. This avoids double-counting a contact whose pivot links them to multiple companies.

### Extender API, not direct hook registration

The plugin uses `FluentCrmApi('extender')->addCompanyProfileSection()` rather than registering `fluent_crm/company_profile_section_{key}` and `fluent_crm/company_profile_sections` directly. The Extender wrapper is the public, documented API; using it insulates against internal FluentCRM hook renaming.

**Note on the API key:** `FluentCrm\App\Api\Api::__get()` *throws an exception* on unknown keys rather than returning null. Always wrap `FluentCrmApi('...')` calls in try/catch when the key might not be registered. The registered key is `extender` (not `extend`, which appears in some FluentCRM docs).

**Note on the proxy:** `FluentCrmApi('extender')` returns a `FluentCrm\App\Api\FCApi` instance, **not** the `Extender` class itself. `FCApi` proxies all method calls via `__call`. This means `method_exists($extender, 'addCompanyProfileSection')` returns **false** even when the proxied method works correctly — do not use `method_exists()` as a guard. The proxy also silently swallows exceptions (returns `null`), so a missing method is impossible to distinguish from a method that genuinely returns null.

### Per-field-type aggregation menus

`fcr_aggregations_for_type()` returns a different set of aggregations depending on the FluentCRM field type. Numeric fields get sum/avg/max/min/count; date fields get max (most recent) / min (earliest) / count; choice fields get count / mode; text fields get nothing (excluded from settings UI).

### No currency formatting

Eden's giving fields are stored as integers. FluentCRM has no currency support. The plugin formats numeric values with thousands separators only — no symbol, no fixed decimals. If the value rounds cleanly to an integer it displays without decimals; otherwise two-decimal precision.

## Files

- `fluentcrm-company-rollups.php` — entire plugin (single file by design; ~400 lines)
- `docs/` — engineering notes, research findings, architectural decisions
- `readme.txt` — WordPress.org-format readme (currently scaffold-default; update before publishing)

## Coding conventions

- Procedural, not OOP — function prefix `fcr_`, constants `FCR_*`
- WordPress core style, not PSR — snake_case functions, `wp_*` for utilities
- Sanitize on save (`sanitize_text_field`, capability check, nonce) — escape on render (`esc_html`, `esc_attr`, `esc_url`)
- All `$wpdb` queries go through `prepare()`. SQL identifiers (table names, aggregation expressions) are interpolated only after a hardcoded whitelist check; values are always parameterized.

## Database table reference

Verified live in development:

- `wp_fc_subscribers` — `id`, `company_id`, ...
- `wp_fc_subscriber_meta` — `subscriber_id`, `object_type`, `key`, `value`. Custom fields use `object_type = 'custom_field'`.
- Custom field *definitions* live in the `_fluentcrm_contact_custom_fields` WP option (read via `fluentcrm_get_custom_contact_fields()`), not in a database table. Each definition has `slug`, `type`, `label`, `value_type`, optionally `group`.

## What this plugin does NOT do

- Does not write to FluentCRM company custom fields. The rollup is read-only and computed at view time.
- Does not surface aggregates outside the profile section (no list-view column, no segment filter, no shortcode, no REST endpoint). FluentCRM has no extension points for those surfaces; that would require forking the core plugin.
- Does not support the many-to-many `fc_subscriber_pivot` relationship for aggregation. Primary `company_id` only, by design.

## Repository

- GitHub: https://github.com/WeMakeGood/fluentcrm-company-rollups
- License: GPL-2.0-or-later
- Owner: WeMakeGood organization

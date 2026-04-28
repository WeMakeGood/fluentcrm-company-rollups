# FluentCRM hook research

Investigation conducted against FluentCRM (free, latest as of 2026-04) installed at `wp-content/plugins/fluent-crm/`. The goal was to determine whether real-time computed company aggregates were feasible without forking the plugin.

## Extension points discovered

### `FluentCrmApi('extender')->addCompanyProfileSection()` — used

Public API at `app/Api/Classes/Extender.php`. Several non-obvious quirks worth recording:

- The API key is `extender`, registered in `app/Api/config.php` (some external FluentCRM docs say `extend` — wrong).
- `FluentCrmApi()` returns the singleton via a magic `__get` that **throws an exception on unknown keys** rather than returning null. Wrap calls in try/catch.
- The returned object is **not** the `Extender` class itself — it's an `FCApi` wrapper that proxies all calls via `__call`. This means `method_exists()` will return false on real Extender methods. The wrapper also catches exceptions in `__call` and returns null, so failed method calls are silent. Registers:

- `fluent_crm/company_profile_sections` — to add the section to the navigation
- `fluent_crm/company_profile_section_{key}` — to render its content
- `fluent_crm/company_profile_section_save_{key}` — to handle save POSTs (optional)

Callback receives `($content, $company)` where `$company` is a hydrated `FluentCrm\App\Models\Company` model. Return shape: `['heading' => string, 'content_html' => string]`.

### `fluent_crm/contact_custom_data_updated` — not used (yet)

Fires from `app/Models/Subscriber.php` after a contact's custom fields are saved. Signature: `($newValues, $subscriber, $updateValues)`. Reserved for the stored-aggregate path if real-time computation ever proves too slow.

## Extension points investigated and rejected

### `fluent_crm/modify_custom_field_value`

Filters a single custom field value as it's read for a contact. Signature: `($value)` only — no field key, no subscriber. Useless for targeted injection because the filter has no way to identify which field is being modified.

### Eloquent `$appends` on the Company model

The `Company` model uses `getMetaAttribute` accessors but has no `$appends` array, and FluentCRM instantiates `\FluentCrm\App\Models\Company` directly (not through a service container). A plugin cannot inject a virtual attribute that surfaces in `toArray()` / API responses without forking the model.

### `fluent_crm/global_field_types` filter

Allows registering new custom field *types* with metadata (label, value_type). Type definitions only carry display/casting hints — no behavior callbacks. Cannot be used to register a "computed" or "calculated" field type.

### Company custom fields

The `CustomCompanyField` model exists but is empty in this installation. Company custom values are stored in the serialized `meta.custom_values` blob on the company row, not in a dedicated meta table. Writing aggregates there would work for a stored-aggregate approach but isn't needed for the live-computation path this plugin took.

## Surfaces with no extension points

- Company list view columns
- Company list filters / search
- Segment / automation conditions referencing computed fields
- REST API responses for companies (no way to inject computed top-level keys)

If aggregates need to appear in any of these surfaces, the plugin would need to switch to a stored-aggregate model and write into `Company.meta.custom_values` so FluentCRM treats the value as a native company custom field.

## Database structure (verified live)

| Table | Purpose |
|---|---|
| `wp_fc_subscribers` | Contacts. `company_id` is the primary company FK. |
| `wp_fc_subscriber_meta` | Custom field values. `object_type = 'custom_field'`, `key` is the field slug, `value` is `longtext`. |
| `wp_fc_subscriber_pivot` | Many-to-many for tags, lists, and additional company associations (`object_type = 'FluentCrm\\App\\Models\\Company'`). |
| `wp_fc_companies` | Companies. `meta` column is a serialized blob containing `custom_values`. |

Custom field *definitions* are not in a database table — they live in the `_fluentcrm_contact_custom_fields` WordPress option, accessed via `fluentcrm_get_custom_contact_fields()`.

## Aggregation query shape

```sql
SELECT <agg_expr>
  FROM wp_fc_subscribers s
  INNER JOIN wp_fc_subscriber_meta m
    ON m.subscriber_id = s.id
   AND m.object_type = 'custom_field'
   AND m.`key` = <field_slug>
   AND m.value <> ''
   AND m.value IS NOT NULL
 WHERE s.company_id = <company_id>
```

`<agg_expr>` is one of:

- Numeric: `SUM(CAST(m.value AS DECIMAL(20,4)))`, `AVG(...)`, `MAX(...)`, `MIN(...)`
- Date / date_time: `MAX(m.value)` or `MIN(m.value)` (lexicographic ordering is correct for `Y-m-d` and `Y-m-d H:i:s`)
- Count: `COUNT(DISTINCT s.id)`
- Mode: separate `GROUP BY m.value ORDER BY COUNT(*) DESC LIMIT 1` query

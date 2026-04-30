<?php
/**
 * Plugin Name:     FluentCRM Company Rollups
 * Plugin URI:      https://github.com/WeMakeGood/fluentcrm-company-rollups
 * Description:     Roll up contact-level custom field data into the FluentCRM company profile view. Configurable per-field aggregations (sum, avg, max, min, count, latest date, earliest date) with admin-controlled section title and field labels.
 * Author:          Make Good
 * Author URI:      https://wemakegood.org
 * Text Domain:     fluentcrm-company-rollups
 * Domain Path:     /languages
 * Version:         0.2.0
 * License:         GPL-2.0-or-later
 * License URI:     https://www.gnu.org/licenses/gpl-2.0.html
 * Requires Plugins: fluent-crm
 *
 * @package         Fluentcrm_Company_Rollups
 */

defined( 'ABSPATH' ) || exit;

// Single namespace constant for option keys, hook IDs, capability checks.
const FCR_OPT_FIELDS  = 'fcr_rollup_fields';
const FCR_OPT_TITLE   = 'fcr_section_title';
const FCR_SECTION_KEY = 'fcr_company_rollups';
const FCR_MENU_SLUG   = 'fluentcrm-company-rollups';
const FCR_NONCE       = 'fcr_save_settings';

/**
 * Returns the FluentCRM contact custom field definitions, keyed by slug.
 * Empty array if FluentCRM is unavailable.
 *
 * The list passes through the `fcr_excluded_field_slugs` filter, which
 * other plugins can hook to remove slugs that don't make sense to
 * aggregate (e.g. enrichment fields whose values are mirrored from
 * the company record and would always be the same across linked
 * contacts).
 *
 * @return array<string, array<string, mixed>>
 */
function fcr_get_custom_fields() {
	if ( ! function_exists( 'fluentcrm_get_custom_contact_fields' ) ) {
		return array();
	}

	$fields  = fluentcrm_get_custom_contact_fields();
	$indexed = array();
	if ( is_array( $fields ) ) {
		foreach ( $fields as $field ) {
			if ( ! empty( $field['slug'] ) ) {
				$indexed[ $field['slug'] ] = $field;
			}
		}
	}

	/**
	 * Filter the list of contact custom field slugs that should be
	 * excluded from rollup configuration and computation.
	 *
	 * @param array<int, string> $excluded_slugs  Slugs to exclude.
	 */
	$excluded = apply_filters( 'fcr_excluded_field_slugs', array() );
	if ( ! empty( $excluded ) && is_array( $excluded ) ) {
		foreach ( $excluded as $slug ) {
			unset( $indexed[ $slug ] );
		}
	}

	return $indexed;
}

/**
 * Aggregations available for a given FluentCRM field type.
 * Returned as [ value => label ] for use in <select> menus.
 *
 * @param string $type FluentCRM field type (number, date, date_time, etc.).
 * @return array<string, string>
 */
function fcr_aggregations_for_type( $type ) {
	$numeric = array(
		'sum'   => __( 'Sum', 'fluentcrm-company-rollups' ),
		'avg'   => __( 'Average', 'fluentcrm-company-rollups' ),
		'max'   => __( 'Maximum', 'fluentcrm-company-rollups' ),
		'min'   => __( 'Minimum', 'fluentcrm-company-rollups' ),
		'count' => __( 'Count of contacts with a value', 'fluentcrm-company-rollups' ),
	);

	$date = array(
		'max'   => __( 'Most recent', 'fluentcrm-company-rollups' ),
		'min'   => __( 'Earliest', 'fluentcrm-company-rollups' ),
		'count' => __( 'Count of contacts with a value', 'fluentcrm-company-rollups' ),
	);

	$choice = array(
		'count' => __( 'Count of contacts with any value', 'fluentcrm-company-rollups' ),
		'mode'  => __( 'Most common value', 'fluentcrm-company-rollups' ),
	);

	$multi_choice = array(
		'count' => __( 'Count of contacts with any value', 'fluentcrm-company-rollups' ),
	);

	switch ( $type ) {
		case 'number':
			return $numeric;
		case 'date':
		case 'date_time':
			return $date;
		case 'select-one':
		case 'radio':
			return $choice;
		case 'select-multi':
		case 'checkbox':
			return $multi_choice;
		default:
			// text, textarea — no aggregation makes sense.
			return array();
	}
}

/**
 * Returns the saved field configuration, normalized.
 * Format: [ slug => [ 'label' => string, 'agg' => string ], ... ]
 *
 * @return array<string, array{label: string, agg: string}>
 */
function fcr_get_config() {
	$raw = get_option( FCR_OPT_FIELDS, array() );
	if ( ! is_array( $raw ) ) {
		return array();
	}

	$out = array();
	foreach ( $raw as $slug => $entry ) {
		if ( ! is_array( $entry ) ) {
			continue;
		}
		$label = isset( $entry['label'] ) ? (string) $entry['label'] : '';
		$agg   = isset( $entry['agg'] ) ? (string) $entry['agg'] : '';
		if ( '' === $agg ) {
			continue;
		}
		$out[ $slug ] = array(
			'label' => $label,
			'agg'   => $agg,
		);
	}
	return $out;
}

/**
 * Section title configured by the admin, with a sensible default.
 *
 * @return string
 */
function fcr_get_section_title() {
	$title = get_option( FCR_OPT_TITLE, '' );
	if ( ! is_string( $title ) || '' === trim( $title ) ) {
		return __( 'Contact Rollups', 'fluentcrm-company-rollups' );
	}
	return $title;
}

/**
 * Compute one aggregate across all contacts whose primary company_id matches.
 * Returns null if no rows match (so the UI can render a dash instead of "0").
 *
 * @param int    $company_id
 * @param string $field_slug
 * @param string $agg
 * @param string $field_type
 * @return string|null Formatted-ready string (raw aggregate) or null if no data.
 */
function fcr_compute_rollup( $company_id, $field_slug, $agg, $field_type ) {
	global $wpdb;

	$subscribers = $wpdb->prefix . 'fc_subscribers';
	$meta        = $wpdb->prefix . 'fc_subscriber_meta';

	// Count is type-agnostic and identical across types.
	if ( 'count' === $agg ) {
		$sql = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT COUNT(DISTINCT s.id)
			   FROM {$subscribers} s
			   INNER JOIN {$meta} m
			     ON m.subscriber_id = s.id
			    AND m.object_type = %s
			    AND m.`key` = %s
			    AND m.value <> ''
			    AND m.value IS NOT NULL
			  WHERE s.company_id = %d",
			'custom_field',
			$field_slug,
			$company_id
		);
		$value = $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return ( null === $value ) ? '0' : (string) $value;
	}

	// Most-common ("mode") for single-choice fields.
	if ( 'mode' === $agg ) {
		$sql = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT m.value, COUNT(*) AS cnt
			   FROM {$subscribers} s
			   INNER JOIN {$meta} m
			     ON m.subscriber_id = s.id
			    AND m.object_type = %s
			    AND m.`key` = %s
			    AND m.value <> ''
			    AND m.value IS NOT NULL
			  WHERE s.company_id = %d
			  GROUP BY m.value
			  ORDER BY cnt DESC, m.value ASC
			  LIMIT 1",
			'custom_field',
			$field_slug,
			$company_id
		);
		$row = $wpdb->get_row( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $row ? (string) $row->value : null;
	}

	// Numeric aggregations: cast value to DECIMAL.
	if ( 'number' === $field_type ) {
		$agg_sql_map = array(
			'sum' => 'SUM(CAST(m.value AS DECIMAL(20,4)))',
			'avg' => 'AVG(CAST(m.value AS DECIMAL(20,4)))',
			'max' => 'MAX(CAST(m.value AS DECIMAL(20,4)))',
			'min' => 'MIN(CAST(m.value AS DECIMAL(20,4)))',
		);
		if ( ! isset( $agg_sql_map[ $agg ] ) ) {
			return null;
		}
		$expr = $agg_sql_map[ $agg ];
		// $expr is from a hardcoded whitelist above, safe to interpolate.
		$sql = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT {$expr}
			   FROM {$subscribers} s
			   INNER JOIN {$meta} m
			     ON m.subscriber_id = s.id
			    AND m.object_type = %s
			    AND m.`key` = %s
			    AND m.value <> ''
			    AND m.value IS NOT NULL
			  WHERE s.company_id = %d",
			'custom_field',
			$field_slug,
			$company_id
		);
		$value = $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return ( null === $value ) ? null : (string) $value;
	}

	// Date aggregations: lexicographic MAX/MIN works for both date and date_time strings.
	if ( 'date' === $field_type || 'date_time' === $field_type ) {
		if ( 'max' !== $agg && 'min' !== $agg ) {
			return null;
		}
		$expr = ( 'max' === $agg ) ? 'MAX(m.value)' : 'MIN(m.value)';
		$sql = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT {$expr}
			   FROM {$subscribers} s
			   INNER JOIN {$meta} m
			     ON m.subscriber_id = s.id
			    AND m.object_type = %s
			    AND m.`key` = %s
			    AND m.value <> ''
			    AND m.value IS NOT NULL
			  WHERE s.company_id = %d",
			'custom_field',
			$field_slug,
			$company_id
		);
		$value = $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return ( null === $value ) ? null : (string) $value;
	}

	return null;
}

/**
 * Format a raw aggregate value for display.
 *
 * @param string|null $value
 * @param string      $agg
 * @param string      $field_type
 * @return string
 */
function fcr_format_value( $value, $agg, $field_type ) {
	if ( null === $value || '' === $value ) {
		return '—';
	}

	if ( 'count' === $agg ) {
		return number_format_i18n( (int) $value );
	}

	if ( 'mode' === $agg ) {
		return (string) $value;
	}

	if ( 'number' === $field_type ) {
		// Numerics: thousands separators, drop trailing decimals if value is integer.
		$float = (float) $value;
		$rounded = round( $float, 2 );
		if ( (float) (int) $rounded === $rounded ) {
			return number_format_i18n( (int) $rounded );
		}
		return number_format_i18n( $rounded, 2 );
	}

	if ( 'date_time' === $field_type ) {
		$ts = strtotime( $value );
		if ( false === $ts ) {
			return (string) $value;
		}
		return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts );
	}

	if ( 'date' === $field_type ) {
		$ts = strtotime( $value );
		if ( false === $ts ) {
			return (string) $value;
		}
		return wp_date( get_option( 'date_format' ), $ts );
	}

	return (string) $value;
}

/**
 * Render the rollup section content for a given company.
 *
 * @param \FluentCrm\App\Models\Company $company
 * @return string HTML
 */
function fcr_render_section_html( $company ) {
	$config = fcr_get_config();

	if ( empty( $config ) ) {
		$settings_url = admin_url( 'options-general.php?page=' . FCR_MENU_SLUG );
		return '<p>' . sprintf(
			/* translators: %s: settings page URL */
			wp_kses(
				__( 'No rollup fields are configured yet. <a href="%s">Configure fields in plugin settings.</a>', 'fluentcrm-company-rollups' ),
				array( 'a' => array( 'href' => array() ) )
			),
			esc_url( $settings_url )
		) . '</p>';
	}

	$fields = fcr_get_custom_fields();

	$rows = '';
	foreach ( $config as $slug => $entry ) {
		if ( ! isset( $fields[ $slug ] ) ) {
			// Field was deleted in FluentCRM; skip silently.
			continue;
		}
		$field_def  = $fields[ $slug ];
		$field_type = isset( $field_def['type'] ) ? (string) $field_def['type'] : '';
		$default_label = isset( $field_def['label'] ) ? (string) $field_def['label'] : $slug;
		$label      = ( '' !== trim( $entry['label'] ) ) ? $entry['label'] : $default_label;
		$agg        = $entry['agg'];

		$raw     = fcr_compute_rollup( (int) $company->id, $slug, $agg, $field_type );
		$display = fcr_format_value( $raw, $agg, $field_type );

		$agg_options = fcr_aggregations_for_type( $field_type );
		$agg_label   = isset( $agg_options[ $agg ] ) ? $agg_options[ $agg ] : $agg;

		$rows .= sprintf(
			'<tr><th scope="row" style="text-align:left;padding:8px 12px 8px 0;font-weight:600;">%s</th><td style="padding:8px 0;color:#646970;font-size:12px;">%s</td><td style="padding:8px 0;text-align:right;font-variant-numeric:tabular-nums;">%s</td></tr>',
			esc_html( $label ),
			esc_html( $agg_label ),
			esc_html( $display )
		);
	}

	if ( '' === $rows ) {
		return '<p>' . esc_html__( 'No configured fields are available — the fields may have been removed from FluentCRM.', 'fluentcrm-company-rollups' ) . '</p>';
	}

	return '<table style="width:100%;border-collapse:collapse;"><tbody>' . $rows . '</tbody></table>';
}

/**
 * Register the company profile section via FluentCRM's Extender API.
 */
function fcr_register_company_section() {
	if ( ! function_exists( 'FluentCrmApi' ) ) {
		return;
	}

	// FluentCRM registers this as 'extender'. Wrap in try/catch — Api->__get throws on unknown keys.
	// Note: the returned object is FCApi (a __call proxy), so method_exists() returns false even
	// though the proxied call works. We rely on the proxy to swallow exceptions if the method
	// genuinely isn't there in a future FluentCRM version.
	try {
		$extender = FluentCrmApi( 'extender' );
	} catch ( \Throwable $e ) {
		return;
	}

	if ( ! $extender ) {
		return;
	}

	$extender->addCompanyProfileSection(
		FCR_SECTION_KEY,
		fcr_get_section_title(),
		function ( $content, $company ) {
			return array(
				'heading'      => fcr_get_section_title(),
				'content_html' => fcr_render_section_html( $company ),
			);
		}
	);
}
add_action( 'init', 'fcr_register_company_section', 20 );

// ---------------------------------------------------------------------------
// Admin settings page
// ---------------------------------------------------------------------------

/**
 * Register the admin menu under Settings.
 */
function fcr_register_admin_menu() {
	add_options_page(
		__( 'FluentCRM Company Rollups', 'fluentcrm-company-rollups' ),
		__( 'Company Rollups', 'fluentcrm-company-rollups' ),
		'manage_options',
		FCR_MENU_SLUG,
		'fcr_render_settings_page'
	);
}
add_action( 'admin_menu', 'fcr_register_admin_menu' );

/**
 * Save handler — invoked when the settings form is submitted.
 */
function fcr_handle_save() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to do this.', 'fluentcrm-company-rollups' ) );
	}
	check_admin_referer( FCR_NONCE );

	$title = isset( $_POST['fcr_section_title'] ) ? sanitize_text_field( wp_unslash( $_POST['fcr_section_title'] ) ) : '';
	update_option( FCR_OPT_TITLE, $title );

	$selected = isset( $_POST['fcr_selected'] ) && is_array( $_POST['fcr_selected'] )
		? array_map( 'sanitize_text_field', wp_unslash( $_POST['fcr_selected'] ) )
		: array();

	$labels = isset( $_POST['fcr_label'] ) && is_array( $_POST['fcr_label'] )
		? wp_unslash( $_POST['fcr_label'] )
		: array();

	$aggs = isset( $_POST['fcr_agg'] ) && is_array( $_POST['fcr_agg'] )
		? wp_unslash( $_POST['fcr_agg'] )
		: array();

	$fields = fcr_get_custom_fields();
	$config = array();
	foreach ( $selected as $slug ) {
		if ( ! isset( $fields[ $slug ] ) ) {
			continue;
		}
		$type        = isset( $fields[ $slug ]['type'] ) ? (string) $fields[ $slug ]['type'] : '';
		$valid_aggs  = fcr_aggregations_for_type( $type );
		$chosen_agg  = isset( $aggs[ $slug ] ) ? sanitize_text_field( $aggs[ $slug ] ) : '';
		if ( ! isset( $valid_aggs[ $chosen_agg ] ) ) {
			continue;
		}
		$config[ $slug ] = array(
			'label' => isset( $labels[ $slug ] ) ? sanitize_text_field( $labels[ $slug ] ) : '',
			'agg'   => $chosen_agg,
		);
	}

	update_option( FCR_OPT_FIELDS, $config );

	wp_safe_redirect( add_query_arg( array( 'page' => FCR_MENU_SLUG, 'updated' => '1' ), admin_url( 'options-general.php' ) ) );
	exit;
}
add_action( 'admin_post_fcr_save_settings', 'fcr_handle_save' );

/**
 * Render the settings page.
 */
function fcr_render_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$config        = fcr_get_config();
	$fields        = fcr_get_custom_fields();
	$section_title = fcr_get_section_title();

	$fluentcrm_active = function_exists( 'fluentcrm_get_custom_contact_fields' );

	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'FluentCRM Company Rollups', 'fluentcrm-company-rollups' ); ?></h1>

		<?php if ( ! $fluentcrm_active ) : ?>
			<div class="notice notice-error"><p>
				<?php esc_html_e( 'FluentCRM is not active. This plugin requires FluentCRM to function.', 'fluentcrm-company-rollups' ); ?>
			</p></div>
			<?php return; ?>
		<?php endif; ?>

		<?php if ( isset( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
			<div class="notice notice-success is-dismissible"><p>
				<?php esc_html_e( 'Settings saved.', 'fluentcrm-company-rollups' ); ?>
			</p></div>
		<?php endif; ?>

		<p><?php esc_html_e( 'Select FluentCRM contact custom fields to roll up onto the company profile. For each selected field, choose an aggregation and (optionally) override the display label.', 'fluentcrm-company-rollups' ); ?></p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="fcr_save_settings" />
			<?php wp_nonce_field( FCR_NONCE ); ?>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="fcr_section_title"><?php esc_html_e( 'Section title', 'fluentcrm-company-rollups' ); ?></label></th>
					<td>
						<input name="fcr_section_title" id="fcr_section_title" type="text" class="regular-text"
						       value="<?php echo esc_attr( $section_title ); ?>"
						       placeholder="<?php esc_attr_e( 'Contact Rollups', 'fluentcrm-company-rollups' ); ?>" />
						<p class="description"><?php esc_html_e( 'Shown as the section name on the company profile page.', 'fluentcrm-company-rollups' ); ?></p>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Fields', 'fluentcrm-company-rollups' ); ?></h2>

			<?php if ( empty( $fields ) ) : ?>
				<p><?php esc_html_e( 'No custom contact fields are defined in FluentCRM yet.', 'fluentcrm-company-rollups' ); ?></p>
			<?php else : ?>
				<table class="widefat striped" style="max-width:1100px;">
					<thead>
						<tr>
							<th style="width:40px;"><?php esc_html_e( 'Use', 'fluentcrm-company-rollups' ); ?></th>
							<th><?php esc_html_e( 'Field', 'fluentcrm-company-rollups' ); ?></th>
							<th><?php esc_html_e( 'Type', 'fluentcrm-company-rollups' ); ?></th>
							<th><?php esc_html_e( 'Aggregation', 'fluentcrm-company-rollups' ); ?></th>
							<th><?php esc_html_e( 'Display label (optional)', 'fluentcrm-company-rollups' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $fields as $slug => $field ) :
						$type        = isset( $field['type'] ) ? (string) $field['type'] : '';
						$default_lbl = isset( $field['label'] ) ? (string) $field['label'] : $slug;
						$aggs        = fcr_aggregations_for_type( $type );
						$current     = isset( $config[ $slug ] ) ? $config[ $slug ] : null;
						$is_selected = (bool) $current;
						$current_agg = $current['agg'] ?? '';
						$current_lbl = $current['label'] ?? '';
						$disabled    = empty( $aggs ) ? 'disabled' : '';
						?>
						<tr>
							<td>
								<input type="checkbox"
								       name="fcr_selected[]"
								       value="<?php echo esc_attr( $slug ); ?>"
								       <?php checked( $is_selected ); ?>
								       <?php echo esc_attr( $disabled ); ?> />
							</td>
							<td>
								<strong><?php echo esc_html( $default_lbl ); ?></strong><br />
								<code style="font-size:11px;"><?php echo esc_html( $slug ); ?></code>
							</td>
							<td><code style="font-size:11px;"><?php echo esc_html( $type ); ?></code></td>
							<td>
								<?php if ( empty( $aggs ) ) : ?>
									<em style="color:#646970;"><?php esc_html_e( 'No aggregations available for this field type.', 'fluentcrm-company-rollups' ); ?></em>
								<?php else : ?>
									<select name="fcr_agg[<?php echo esc_attr( $slug ); ?>]">
										<?php foreach ( $aggs as $val => $lbl ) : ?>
											<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $current_agg, $val ); ?>>
												<?php echo esc_html( $lbl ); ?>
											</option>
										<?php endforeach; ?>
									</select>
								<?php endif; ?>
							</td>
							<td>
								<input type="text"
								       name="fcr_label[<?php echo esc_attr( $slug ); ?>]"
								       value="<?php echo esc_attr( $current_lbl ); ?>"
								       placeholder="<?php echo esc_attr( $default_lbl ); ?>"
								       class="regular-text" />
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<?php submit_button( __( 'Save changes', 'fluentcrm-company-rollups' ) ); ?>
		</form>
	</div>
	<?php
}

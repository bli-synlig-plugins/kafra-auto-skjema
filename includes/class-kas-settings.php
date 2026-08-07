<?php

if (!defined('ABSPATH')) exit;

class KAS_Settings {

	public static function init() {
		add_action('admin_menu', array(__CLASS__, 'register_menu'));
		add_action('admin_init', array(__CLASS__, 'register_settings'));
		add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue_assets'));
		add_action('wp_ajax_kas_load_form_fields', array(__CLASS__, 'ajax_load_form_fields'));
	}

	public static function register_menu() {
		add_submenu_page(
			'woocommerce',
			'Kafra Auto Skjema',
			'Kafra Auto Skjema',
			'manage_woocommerce',
			'kafra-auto-skjema',
			array(__CLASS__, 'render_page')
		);
	}

	public static function register_settings() {
		register_setting('kas_settings_group', KAS_OPTION_KEY, array(
			'type'              => 'array',
			'sanitize_callback' => array(__CLASS__, 'sanitize_settings'),
			'default'           => array(),
		));
	}

	public static function enqueue_assets($hook) {
		if ($hook !== 'woocommerce_page_kafra-auto-skjema') return;

		$settings = KAS_Plugin::get_settings();

		wp_enqueue_style('kas-admin', KAS_PLUGIN_URL . 'assets/admin.css', array(), KAS_VERSION);
		wp_enqueue_script('kas-admin', KAS_PLUGIN_URL . 'assets/admin.js', array(), KAS_VERSION, true);
		wp_localize_script('kas-admin', 'kasAdmin', array(
			'ajaxUrl'    => admin_url('admin-ajax.php'),
			'nonce'      => wp_create_nonce('kas_load_form'),
			'attributes' => self::get_attribute_options(),
			'fields'     => self::get_form_fields((int) $settings['form_id']),
		));
	}

	public static function ajax_load_form_fields() {
		check_ajax_referer('kas_load_form', 'nonce');

		if (!current_user_can('manage_woocommerce')) {
			wp_send_json_error(array('message' => 'Ingen tilgang.'), 403);
		}

		$form_id = isset($_POST['form_id']) ? absint($_POST['form_id']) : 0;
		if (!$form_id) {
			wp_send_json_error(array('message' => 'Velg et skjema først.'));
		}

		$fields = self::get_form_fields($form_id);
		if (empty($fields)) {
			wp_send_json_error(array('message' => 'Fant ingen felter i dette skjemaet.'));
		}

		wp_send_json_success(array(
			'form_id' => $form_id,
			'fields'  => $fields,
		));
	}

	public static function sanitize_settings($input) {
		$input = is_array($input) ? $input : array();

		$allowed_hooks = array(
			'woocommerce_before_single_product_summary',
			'woocommerce_after_single_product_summary',
			'woocommerce_after_single_product',
			'woocommerce_product_after_tabs',
		);

		$allowed_sources = array_keys(KAS_Plugin::get_source_options());
		$mappings = array();

		if (!empty($input['mappings']) && is_array($input['mappings'])) {
			foreach ($input['mappings'] as $row) {
				if (!is_array($row)) continue;

				$source   = isset($row['source']) ? sanitize_key($row['source']) : '';
				$field_id = isset($row['field_id']) ? preg_replace('/[^0-9.]/', '', (string) $row['field_id']) : '';

				if (!in_array($source, $allowed_sources, true) || $field_id === '') {
					continue;
				}

				$mappings[] = array(
					'source'     => $source,
					'source_key' => isset($row['source_key']) ? sanitize_text_field($row['source_key']) : '',
					'field_id'   => $field_id,
					'label'      => isset($row['label']) ? sanitize_text_field($row['label']) : '',
					'locked'     => !empty($row['locked']) ? 1 : 0,
				);
			}
		}

		$hook = isset($input['display_hook']) ? sanitize_key($input['display_hook']) : 'woocommerce_after_single_product_summary';
		if (!in_array($hook, $allowed_hooks, true)) {
			$hook = 'woocommerce_after_single_product_summary';
		}

		return array(
			'form_id'          => isset($input['form_id']) ? absint($input['form_id']) : 0,
			'heading'          => isset($input['heading']) ? sanitize_text_field($input['heading']) : '',
			'intro'            => isset($input['intro']) ? sanitize_textarea_field($input['intro']) : '',
			'auto_display'     => !empty($input['auto_display']) ? 1 : 0,
			'display_hook'     => $hook,
			'display_priority' => isset($input['display_priority']) ? absint($input['display_priority']) : 25,
			'mappings'         => $mappings,
		);
	}

	public static function get_attribute_options() {
		$options = array();
		if (!function_exists('wc_get_attribute_taxonomies')) {
			return $options;
		}

		foreach (wc_get_attribute_taxonomies() as $attribute) {
			$taxonomy = wc_attribute_taxonomy_name($attribute->attribute_name);
			$options[$taxonomy] = $attribute->attribute_label . ' (' . $taxonomy . ')';
		}

		return $options;
	}

	public static function get_forms() {
		if (!class_exists('GFAPI')) return array();

		$forms = GFAPI::get_forms(true);
		if (is_wp_error($forms) || !is_array($forms)) return array();

		$list = array();
		foreach ($forms as $form) {
			$list[(int) $form['id']] = '#' . $form['id'] . ' – ' . $form['title'];
		}

		return $list;
	}

	public static function get_form_fields($form_id) {
		if (!$form_id || !class_exists('GFAPI')) return array();

		$form = GFAPI::get_form($form_id);
		if (empty($form['fields']) || !is_array($form['fields'])) return array();

		$fields = array();
		foreach ($form['fields'] as $field) {
			if (!is_object($field) || empty($field->id)) continue;
			if (in_array($field->type, array('section', 'page', 'html', 'captcha'), true)) continue;

			$label = !empty($field->label) ? $field->label : '(uten etikett)';
			$fields[(string) $field->id] = 'ID ' . $field->id . ' – ' . $label . ' (' . $field->type . ')';

			if (!empty($field->inputs) && is_array($field->inputs)) {
				foreach ($field->inputs as $input) {
					if (empty($input['id']) || !empty($input['isHidden'])) continue;
					$input_label = !empty($input['label']) ? $input['label'] : $label;
					$fields[(string) $input['id']] = 'ID ' . $input['id'] . ' – ' . $label . ' › ' . $input_label;
				}
			}
		}

		return $fields;
	}

	public static function render_page() {
		if (!current_user_can('manage_woocommerce')) return;

		$settings    = KAS_Plugin::get_settings();
		$forms       = self::get_forms();
		$form_fields = self::get_form_fields((int) $settings['form_id']);
		$attributes  = self::get_attribute_options();
		$sources     = KAS_Plugin::get_source_options();
		$opt         = KAS_OPTION_KEY;
		$has_form    = !empty($form_fields);
		?>
		<div class="wrap kas-wrap">
			<h1>Kafra Auto Skjema</h1>

			<form method="post" action="options.php">
				<?php settings_fields('kas_settings_group'); ?>

				<div class="kas-step">
					<h2>1. Velg skjema</h2>
					<div class="kas-form-picker">
						<select name="<?php echo esc_attr($opt); ?>[form_id]" id="kas_form_id">
							<option value="0">Velg skjema…</option>
							<?php foreach ($forms as $id => $title) : ?>
								<option value="<?php echo esc_attr($id); ?>" <?php selected((int) $settings['form_id'], (int) $id); ?>>
									<?php echo esc_html($title); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<button type="button" class="button button-secondary kas-btn" id="kas-load-form">
							<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 11V4a2 2 0 0 1 2-2h8a2.4 2.4 0 0 1 1.706.706l3.588 3.588A2.4 2.4 0 0 1 20 8v12a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-1"/><path d="M14 2v5a1 1 0 0 0 1 1h5"/><path d="M2 15h10"/><path d="m9 18 3-3-3-3"/></svg>
							Bruk dette skjema
						</button>
						<span class="kas-form-status" id="kas-form-status" aria-live="polite"></span>
					</div>
				</div>

				<div class="kas-step">
					<h2>2. Visning</h2>
					<table class="form-table">
						<tr>
							<th scope="row"><label for="kas_heading">Overskrift</label></th>
							<td>
								<input type="text" class="regular-text" id="kas_heading" name="<?php echo esc_attr($opt); ?>[heading]" value="<?php echo esc_attr($settings['heading']); ?>">
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="kas_intro">Beskrivelse</label></th>
							<td>
								<textarea class="large-text" rows="3" id="kas_intro" name="<?php echo esc_attr($opt); ?>[intro]"><?php echo esc_textarea($settings['intro']); ?></textarea>
							</td>
						</tr>
						<tr>
							<th scope="row">Automatisk visning</th>
							<td>
								<label>
									<input type="checkbox" name="<?php echo esc_attr($opt); ?>[auto_display]" value="1" <?php checked(!empty($settings['auto_display'])); ?>>
									Vis skjema automatisk på produktsider
								</label>
								<p class="description">Eller shortcode: <code>[kafra_produkt_skjema]</code></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="kas_display_hook">Plassering</label></th>
							<td>
								<select name="<?php echo esc_attr($opt); ?>[display_hook]" id="kas_display_hook">
									<option value="woocommerce_after_single_product_summary" <?php selected($settings['display_hook'], 'woocommerce_after_single_product_summary'); ?>>Etter produktsammendrag</option>
									<option value="woocommerce_product_after_tabs" <?php selected($settings['display_hook'], 'woocommerce_product_after_tabs'); ?>>Etter produktfaner</option>
									<option value="woocommerce_after_single_product" <?php selected($settings['display_hook'], 'woocommerce_after_single_product'); ?>>Nederst på produktsiden</option>
									<option value="woocommerce_before_single_product_summary" <?php selected($settings['display_hook'], 'woocommerce_before_single_product_summary'); ?>>Før produktsammendrag</option>
								</select>
								<label class="kas-priority" for="kas_display_priority">
									Prioritet:
									<input type="number" min="1" max="999" id="kas_display_priority" name="<?php echo esc_attr($opt); ?>[display_priority]" value="<?php echo esc_attr($settings['display_priority']); ?>">
								</label>
							</td>
						</tr>
					</table>
				</div>

				<div class="kas-step<?php echo $has_form ? ' is-ready' : ''; ?>" id="kas-step-mapping">
					<h2>3. Feltmapping</h2>
					<p class="kas-mapping-hint" id="kas-mapping-hint">
						<?php echo $has_form
							? 'Koble produktdata til feltene i skjemaet. Bruk hengelåsen for å gjøre et felt skrivebeskyttet.'
							: 'Velg et skjema og trykk «Bruk dette skjema» for å laste feltene.'; ?>
					</p>

					<details class="kas-field-list" id="kas-field-list"<?php echo $has_form ? '' : ' hidden'; ?>>
						<summary>Felt-ID-er i valgt skjema</summary>
						<ul id="kas-field-list-ul">
							<?php foreach ($form_fields as $fid => $flabel) : ?>
								<li><code><?php echo esc_html($fid); ?></code> – <?php echo esc_html($flabel); ?></li>
							<?php endforeach; ?>
						</ul>
					</details>

					<div class="kas-mapping-body" id="kas-mapping-body"<?php echo $has_form ? '' : ' hidden'; ?>>
						<table class="widefat kas-mappings" id="kas-mappings">
							<thead>
								<tr>
									<th>Kilde</th>
									<th>Nøkkel / attributt</th>
									<th>GF felt-ID</th>
									<th>Notat</th>
									<th class="kas-col-lock">Skrivebeskyttelse</th>
									<th></th>
								</tr>
							</thead>
							<tbody>
								<?php
								if (empty($settings['mappings'])) {
									$settings['mappings'][] = array(
										'source'     => 'product_title',
										'source_key' => '',
										'field_id'   => '',
										'label'      => '',
										'locked'     => 0,
									);
								}

								foreach ($settings['mappings'] as $index => $row) {
									self::render_mapping_row($index, $row, $sources, $attributes, $form_fields);
								}
								?>
							</tbody>
						</table>

						<p>
							<button type="button" class="button button-secondary kas-btn" id="kas-add-mapping">
								<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M8 12h8"/><path d="M12 8v8"/></svg>
								Legg til mapping
							</button>
						</p>
					</div>
				</div>

				<template id="kas-mapping-template">
					<?php
					self::render_mapping_row('__INDEX__', array(
						'source'     => 'product_title',
						'source_key' => '',
						'field_id'   => '',
						'label'      => '',
						'locked'     => 0,
					), $sources, $attributes, $form_fields);
					?>
				</template>

				<?php submit_button('Lagre innstillinger'); ?>
			</form>
		</div>
		<?php
	}

	private static function render_mapping_row($index, $row, $sources, $attributes, $form_fields) {
		$source     = isset($row['source']) ? $row['source'] : 'product_title';
		$source_key = isset($row['source_key']) ? $row['source_key'] : '';
		$field_id   = isset($row['field_id']) ? $row['field_id'] : '';
		$label      = isset($row['label']) ? $row['label'] : '';
		$locked     = !empty($row['locked']);
		$needs_key  = in_array($source, array('attribute', 'acf', 'meta'), true);
		$name_base  = KAS_OPTION_KEY . '[mappings][' . $index . ']';
		?>
		<tr class="kas-mapping-row">
			<td>
				<select name="<?php echo esc_attr($name_base); ?>[source]" class="kas-source">
					<?php foreach ($sources as $key => $text) : ?>
						<option value="<?php echo esc_attr($key); ?>" <?php selected($source, $key); ?>><?php echo esc_html($text); ?></option>
					<?php endforeach; ?>
				</select>
			</td>
			<td>
				<?php if ($source === 'attribute' && !empty($attributes)) : ?>
					<select name="<?php echo esc_attr($name_base); ?>[source_key]" class="kas-source-key">
						<option value="">Velg attributt…</option>
						<?php foreach ($attributes as $tax => $atext) : ?>
							<option value="<?php echo esc_attr($tax); ?>" <?php selected($source_key, $tax); ?>><?php echo esc_html($atext); ?></option>
						<?php endforeach; ?>
					</select>
				<?php else : ?>
					<input type="text" name="<?php echo esc_attr($name_base); ?>[source_key]" class="kas-source-key regular-text" value="<?php echo esc_attr($source_key); ?>" placeholder="<?php echo esc_attr($needs_key ? 'pa_materiale / felt_navn' : '—'); ?>" <?php disabled(!$needs_key); ?>>
				<?php endif; ?>
			</td>
			<td>
				<?php if (!empty($form_fields)) : ?>
					<select name="<?php echo esc_attr($name_base); ?>[field_id]" class="kas-field-id">
						<option value="">Velg felt…</option>
						<?php foreach ($form_fields as $fid => $flabel) : ?>
							<option value="<?php echo esc_attr($fid); ?>" <?php selected((string) $field_id, (string) $fid); ?>><?php echo esc_html($flabel); ?></option>
						<?php endforeach; ?>
					</select>
				<?php else : ?>
					<input type="text" name="<?php echo esc_attr($name_base); ?>[field_id]" class="kas-field-id small-text" value="<?php echo esc_attr($field_id); ?>" placeholder="1">
				<?php endif; ?>
			</td>
			<td>
				<input type="text" name="<?php echo esc_attr($name_base); ?>[label]" class="regular-text" value="<?php echo esc_attr($label); ?>">
			</td>
			<td class="kas-col-lock">
				<input type="hidden" name="<?php echo esc_attr($name_base); ?>[locked]" class="kas-locked-input" value="<?php echo $locked ? '1' : '0'; ?>">
				<button
					type="button"
					class="kas-lock-toggle<?php echo $locked ? ' is-locked' : ''; ?>"
					aria-pressed="<?php echo $locked ? 'true' : 'false'; ?>"
					title="<?php echo $locked ? 'Feltet er låst' : 'Feltet er åpent'; ?>"
				>
					<span class="kas-lock-icon kas-lock-icon--closed" aria-hidden="true">
						<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
					</span>
					<span class="kas-lock-icon kas-lock-icon--open" aria-hidden="true">
						<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 9.9-1"/></svg>
					</span>
				</button>
			</td>
			<td>
				<button type="button" class="button button-secondary kas-btn kas-remove-mapping">Fjern</button>
			</td>
		</tr>
		<?php
	}
}

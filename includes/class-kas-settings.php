<?php

if (!defined('ABSPATH')) exit;

class KAS_Settings {

	public static function init() {
		add_action('admin_menu', array(__CLASS__, 'register_menu'));
		add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue_assets'));
		add_action('admin_post_kas_add_form', array(__CLASS__, 'handle_add_form'));
		add_action('admin_post_kas_save_form', array(__CLASS__, 'handle_save_form'));
		add_action('admin_post_kas_delete_form', array(__CLASS__, 'handle_delete_form'));
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

	public static function enqueue_assets($hook) {
		if ($hook !== 'woocommerce_page_kafra-auto-skjema') return;

		$screen = self::get_screen();
		$form_id = !empty($screen['form_id']) ? (int) $screen['form_id'] : 0;

		wp_enqueue_style('kas-admin', KAS_PLUGIN_URL . 'assets/admin.css', array(), KAS_VERSION);

		if ($screen['view'] === 'edit') {
			wp_enqueue_script('kas-admin', KAS_PLUGIN_URL . 'assets/admin.js', array(), KAS_VERSION, true);
			wp_localize_script('kas-admin', 'kasAdmin', array(
				'ajaxUrl'    => admin_url('admin-ajax.php'),
				'nonce'      => wp_create_nonce('kas_load_form'),
				'attributes' => self::get_attribute_options(),
				'fields'     => self::get_form_fields($form_id),
			));
		}
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

	public static function get_screen() {
		$view = 'list';
		$form_id = 0;

		if (!empty($_GET['kas_edit'])) {
			$view = 'edit';
			$form_id = absint($_GET['kas_edit']);
		} elseif (!empty($_GET['kas_new'])) {
			$view = 'new';
		}

		return array(
			'view'    => $view,
			'form_id' => $form_id,
		);
	}

	public static function admin_url($args = array()) {
		return add_query_arg(array_merge(array('page' => 'kafra-auto-skjema'), $args), admin_url('admin.php'));
	}

	public static function handle_add_form() {
		if (!current_user_can('manage_woocommerce')) {
			wp_die('Ingen tilgang.');
		}

		check_admin_referer('kas_add_form');

		$form_id = isset($_POST['form_id']) ? absint($_POST['form_id']) : 0;
		if (!$form_id) {
			wp_safe_redirect(self::admin_url(array('kas_new' => 1, 'kas_error' => 'missing')));
			exit;
		}

		if (KAS_Plugin::get_form_config($form_id)) {
			wp_safe_redirect(self::admin_url(array('kas_edit' => $form_id)));
			exit;
		}

		KAS_Plugin::save_form_config(KAS_Plugin::default_form_config($form_id));
		wp_safe_redirect(self::admin_url(array('kas_edit' => $form_id, 'kas_added' => 1)));
		exit;
	}

	public static function handle_save_form() {
		if (!current_user_can('manage_woocommerce')) {
			wp_die('Ingen tilgang.');
		}

		check_admin_referer('kas_save_form');

		$form_id = isset($_POST['form_id']) ? absint($_POST['form_id']) : 0;
		if (!$form_id) {
			wp_safe_redirect(self::admin_url(array('kas_error' => 'missing')));
			exit;
		}

		$config = self::sanitize_form_config($_POST, $form_id);
		KAS_Plugin::save_form_config($config);

		wp_safe_redirect(self::admin_url(array('kas_edit' => $form_id, 'kas_saved' => 1)));
		exit;
	}

	public static function handle_delete_form() {
		if (!current_user_can('manage_woocommerce')) {
			wp_die('Ingen tilgang.');
		}

		check_admin_referer('kas_delete_form');

		$form_id = isset($_POST['form_id']) ? absint($_POST['form_id']) : 0;
		if ($form_id) {
			KAS_Plugin::delete_form_config($form_id);
		}

		wp_safe_redirect(self::admin_url(array('kas_deleted' => 1)));
		exit;
	}

	public static function sanitize_form_config($input, $form_id) {
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
			'form_id'          => (int) $form_id,
			'title_mode'       => isset($input['title_mode']) ? KAS_Plugin::sanitize_display_mode($input['title_mode']) : 'custom',
			'description_mode' => isset($input['description_mode']) ? KAS_Plugin::sanitize_display_mode($input['description_mode']) : 'custom',
			'heading'          => isset($input['heading']) ? sanitize_text_field(wp_unslash($input['heading'])) : '',
			'intro'            => isset($input['intro']) ? sanitize_textarea_field(wp_unslash($input['intro'])) : '',
			'auto_display'     => !empty($input['auto_display']) ? 1 : 0,
			'display_hook'     => $hook,
			'display_priority' => isset($input['display_priority']) ? absint($input['display_priority']) : 25,
			'gf_atts'          => isset($input['gf_atts']) ? KAS_Plugin::sanitize_gf_atts($input['gf_atts']) : KAS_Plugin::default_gf_atts(),
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
			$list[(int) $form['id']] = array(
				'id'    => (int) $form['id'],
				'title' => $form['title'],
			);
		}

		return $list;
	}

	public static function get_form_title($form_id) {
		$forms = self::get_forms();
		if (!empty($forms[$form_id]['title'])) {
			return $forms[$form_id]['title'];
		}
		return 'Skjema #' . (int) $form_id;
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

		$screen = self::get_screen();

		echo '<div class="wrap kas-wrap">';

		if ($screen['view'] === 'new') {
			self::render_new_page();
		} elseif ($screen['view'] === 'edit') {
			self::render_edit_page($screen['form_id']);
		} else {
			self::render_list_page();
		}

		echo '</div>';
	}

	private static function render_notices() {
		if (!empty($_GET['kas_saved'])) {
			echo '<div class="notice notice-success is-dismissible"><p>Skjema lagret.</p></div>';
		}
		if (!empty($_GET['kas_added'])) {
			echo '<div class="notice notice-success is-dismissible"><p>Skjema lagt til. Konfigurer visning og mapping under.</p></div>';
		}
		if (!empty($_GET['kas_deleted'])) {
			echo '<div class="notice notice-success is-dismissible"><p>Skjema fjernet.</p></div>';
		}
		if (!empty($_GET['kas_error'])) {
			echo '<div class="notice notice-error is-dismissible"><p>Noe gikk galt. Prøv igjen.</p></div>';
		}
	}

	private static function render_list_page() {
		$configured = KAS_Plugin::get_configured_forms();
		$gf_forms   = self::get_forms();
		$add_url    = self::admin_url(array('kas_new' => 1));
		?>
		<h1 class="wp-heading-inline">Kafra Auto Skjema</h1>
		<hr class="wp-header-end">
		<?php self::render_notices(); ?>

		<div class="kas-form-cards">
			<?php foreach ($configured as $form_id => $config) :
				$title = !empty($gf_forms[$form_id]['title']) ? $gf_forms[$form_id]['title'] : self::get_form_title($form_id);
				$map_count = 0;
				foreach ((array) $config['mappings'] as $row) {
					if (!empty($row['field_id'])) $map_count++;
				}
				$edit_url = self::admin_url(array('kas_edit' => $form_id));
				?>
				<a class="kas-form-card" href="<?php echo esc_url($edit_url); ?>">
					<div class="kas-form-card__main">
						<strong class="kas-form-card__title"><?php echo esc_html($title); ?></strong>
						<span class="kas-form-card__meta">#<?php echo esc_html($form_id); ?></span>
					</div>
					<div class="kas-form-card__meta-row">
						<span><?php echo esc_html($map_count); ?> mapping<?php echo $map_count === 1 ? '' : 'er'; ?></span>
						<span><?php echo !empty($config['auto_display']) ? 'Auto på produktside' : 'Kun shortcode'; ?></span>
					</div>
					<code class="kas-form-card__shortcode">[kafra_produkt_skjema form_id="<?php echo esc_attr($form_id); ?>"]</code>
				</a>
			<?php endforeach; ?>

			<a class="kas-form-card kas-form-card--add" href="<?php echo esc_url($add_url); ?>">
				<svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M8 12h8"/><path d="M12 8v8"/></svg>
				<span>Legg til skjema</span>
			</a>
		</div>
		<?php
	}

	private static function render_new_page() {
		$gf_forms   = self::get_forms();
		$configured = KAS_Plugin::get_configured_forms();
		$available  = array();

		foreach ($gf_forms as $id => $form) {
			if (empty($configured[$id])) {
				$available[$id] = $form;
			}
		}
		?>
		<a href="<?php echo esc_url(self::admin_url()); ?>" class="kas-back">← Tilbake</a>
		<h1 class="wp-heading-inline">Legg til skjema</h1>
		<hr class="wp-header-end">
		<?php self::render_notices(); ?>

		<div class="kas-panel">
			<?php if (empty($available)) : ?>
				<p>Alle Gravity Forms er allerede lagt til, eller det finnes ingen skjemaer.</p>
			<?php else : ?>
				<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="kas-add-form">
					<input type="hidden" name="action" value="kas_add_form">
					<?php wp_nonce_field('kas_add_form'); ?>

					<label for="kas_new_form_id"><strong>Velg Gravity Form</strong></label>
					<div class="kas-form-picker">
						<select name="form_id" id="kas_new_form_id" required>
							<option value="">Velg skjema…</option>
							<?php foreach ($available as $id => $form) : ?>
								<option value="<?php echo esc_attr($id); ?>">#<?php echo esc_html($id); ?> – <?php echo esc_html($form['title']); ?></option>
							<?php endforeach; ?>
						</select>
						<button type="submit" class="button button-secondary kas-btn">
							<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 11V4a2 2 0 0 1 2-2h8a2.4 2.4 0 0 1 1.706.706l3.588 3.588A2.4 2.4 0 0 1 20 8v12a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-1"/><path d="M14 2v5a1 1 0 0 0 1 1h5"/><path d="M2 15h10"/><path d="m9 18 3-3-3-3"/></svg>
							Legg til
						</button>
					</div>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function render_edit_page($form_id) {
		$config = KAS_Plugin::get_form_config($form_id);
		if (!$config) {
			echo '<div class="notice notice-error"><p>Fant ikke dette skjemaet.</p></div>';
			echo '<p><a href="' . esc_url(self::admin_url()) . '">← Tilbake</a></p>';
			return;
		}

		$title       = self::get_form_title($form_id);
		$form_fields = self::get_form_fields($form_id);
		$attributes  = self::get_attribute_options();
		$sources     = KAS_Plugin::get_source_options();
		$has_form    = !empty($form_fields);
		?>
		<div class="kas-edit-top">
			<a href="<?php echo esc_url(self::admin_url()); ?>" class="kas-back">← Alle skjemaer</a>
		</div>
		<h1 class="wp-heading-inline"><?php echo esc_html($title); ?></h1>
		<hr class="wp-header-end">
		<?php self::render_notices(); ?>
		<p class="kas-subtitle">Gravity Form #<?php echo esc_html($form_id); ?> · <code>[kafra_produkt_skjema form_id="<?php echo esc_attr($form_id); ?>"]</code></p>

		<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
			<input type="hidden" name="action" value="kas_save_form">
			<input type="hidden" name="form_id" id="kas_form_id" value="<?php echo esc_attr($form_id); ?>">
			<?php wp_nonce_field('kas_save_form'); ?>

			<div class="kas-step">
				<h2>1. Visning</h2>
				<table class="form-table">
					<tr>
						<th scope="row"><label for="kas_title_mode">Overskrift</label></th>
						<td>
							<?php $title_mode = !empty($config['title_mode']) ? $config['title_mode'] : 'custom'; ?>
							<select name="title_mode" id="kas_title_mode" class="kas-mode-select" data-kas-target="kas-title-custom">
								<option value="custom" <?php selected($title_mode, 'custom'); ?>>Egendefinert</option>
								<option value="default" <?php selected($title_mode, 'default'); ?>>Gravity Forms-standard</option>
								<option value="none" <?php selected($title_mode, 'none'); ?>>Ingen</option>
							</select>
							<div class="kas-mode-custom" id="kas-title-custom" <?php echo $title_mode === 'custom' ? '' : 'hidden'; ?>>
								<input type="text" class="regular-text" id="kas_heading" name="heading" value="<?php echo esc_attr($config['heading']); ?>" placeholder="Interessert i en lignende løsning?">
							</div>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="kas_description_mode">Beskrivelse</label></th>
						<td>
							<?php $desc_mode = !empty($config['description_mode']) ? $config['description_mode'] : 'custom'; ?>
							<select name="description_mode" id="kas_description_mode" class="kas-mode-select" data-kas-target="kas-desc-custom">
								<option value="custom" <?php selected($desc_mode, 'custom'); ?>>Egendefinert</option>
								<option value="default" <?php selected($desc_mode, 'default'); ?>>Gravity Forms-standard</option>
								<option value="none" <?php selected($desc_mode, 'none'); ?>>Ingen</option>
							</select>
							<div class="kas-mode-custom" id="kas-desc-custom" <?php echo $desc_mode === 'custom' ? '' : 'hidden'; ?>>
								<textarea class="large-text" rows="3" id="kas_intro" name="intro" placeholder="Valgfri ingress…"><?php echo esc_textarea($config['intro']); ?></textarea>
							</div>
						</td>
					</tr>
					<tr>
						<th scope="row">Automatisk visning</th>
						<td>
							<label>
								<input type="checkbox" name="auto_display" value="1" <?php checked(!empty($config['auto_display'])); ?>>
								Vis skjema automatisk på produktsider
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="kas_display_hook">Plassering</label></th>
						<td>
							<select name="display_hook" id="kas_display_hook">
								<option value="woocommerce_after_single_product_summary" <?php selected($config['display_hook'], 'woocommerce_after_single_product_summary'); ?>>Etter produktsammendrag</option>
								<option value="woocommerce_product_after_tabs" <?php selected($config['display_hook'], 'woocommerce_product_after_tabs'); ?>>Etter produktfaner</option>
								<option value="woocommerce_after_single_product" <?php selected($config['display_hook'], 'woocommerce_after_single_product'); ?>>Nederst på produktsiden</option>
								<option value="woocommerce_before_single_product_summary" <?php selected($config['display_hook'], 'woocommerce_before_single_product_summary'); ?>>Før produktsammendrag</option>
							</select>
							<label class="kas-priority" for="kas_display_priority">
								Prioritet:
								<input type="number" min="1" max="999" id="kas_display_priority" name="display_priority" value="<?php echo esc_attr($config['display_priority']); ?>">
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="kas_gf_atts">Ekstra GF-attributter</label></th>
						<td>
							<?php
							$gf_atts = !empty($config['gf_atts']) ? $config['gf_atts'] : KAS_Plugin::default_gf_atts();
							$preview = KAS_Plugin::build_gf_shortcode($form_id, array_merge($config, array(
								'title_mode'       => $title_mode,
								'description_mode' => $desc_mode,
								'gf_atts'          => $gf_atts,
							)));
							?>
							<input
								type="text"
								class="regular-text"
								id="kas_gf_atts"
								name="gf_atts"
								value="<?php echo esc_attr($gf_atts); ?>"
								placeholder="<?php echo esc_attr(KAS_Plugin::default_gf_atts()); ?>"
							>
							<p class="description">
								F.eks. <code>ajax="true"</code> eller <code>tabindex="10"</code>.
								Title/description styres over.
							</p>
							<p class="description kas-gf-preview">
								Shortcode: <code id="kas-gf-preview"><?php echo esc_html($preview); ?></code>
							</p>
						</td>
					</tr>
				</table>
			</div>

			<div class="kas-step<?php echo $has_form ? ' is-ready' : ''; ?>" id="kas-step-mapping">
				<div class="kas-step-head">
					<h2>2. Feltmapping</h2>
					<button type="button" class="button button-secondary kas-btn" id="kas-load-form">
						<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 11V4a2 2 0 0 1 2-2h8a2.4 2.4 0 0 1 1.706.706l3.588 3.588A2.4 2.4 0 0 1 20 8v12a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-1"/><path d="M14 2v5a1 1 0 0 0 1 1h5"/><path d="M2 15h10"/><path d="m9 18 3-3-3-3"/></svg>
						Last felter på nytt
					</button>
				</div>
				<p class="kas-mapping-hint" id="kas-mapping-hint">
					<?php echo $has_form
						? 'Koble produktdata til feltene i skjemaet. Bruk hengelåsen for skrivebeskyttelse.'
						: 'Trykk «Last felter på nytt» for å hente feltene fra Gravity Forms.'; ?>
				</p>
				<span class="kas-form-status" id="kas-form-status" aria-live="polite"></span>

				<details class="kas-field-list" id="kas-field-list"<?php echo $has_form ? '' : ' hidden'; ?>>
					<summary>Felt-ID-er i skjemaet</summary>
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
							$mappings = $config['mappings'];
							if (empty($mappings)) {
								$mappings[] = array(
									'source'     => 'product_title',
									'source_key' => '',
									'field_id'   => '',
									'label'      => '',
									'locked'     => 0,
								);
							}

							foreach ($mappings as $index => $row) {
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

			<p class="submit kas-submit-row">
				<button type="submit" class="button button-primary">Lagre skjema</button>
			</p>
		</form>

		<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="kas-delete-form" onsubmit="return confirm('Fjerne dette skjemaet fra Kafra Auto Skjema?');">
			<input type="hidden" name="action" value="kas_delete_form">
			<input type="hidden" name="form_id" value="<?php echo esc_attr($form_id); ?>">
			<?php wp_nonce_field('kas_delete_form'); ?>
			<button type="submit" class="button button-secondary kas-btn kas-btn-danger">Fjern skjema</button>
		</form>
		<?php
	}

	private static function render_mapping_row($index, $row, $sources, $attributes, $form_fields) {
		$source     = isset($row['source']) ? $row['source'] : 'product_title';
		$source_key = isset($row['source_key']) ? $row['source_key'] : '';
		$field_id   = isset($row['field_id']) ? $row['field_id'] : '';
		$label      = isset($row['label']) ? $row['label'] : '';
		$locked     = !empty($row['locked']);
		$needs_key  = in_array($source, array('attribute', 'acf', 'meta'), true);
		$name_base  = 'mappings[' . $index . ']';
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

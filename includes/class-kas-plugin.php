<?php

if (!defined('ABSPATH')) exit;

class KAS_Plugin {

	public static function init() {
		if (!self::dependencies_met()) {
			add_action('admin_notices', array(__CLASS__, 'missing_dependencies_notice'));
			return;
		}

		KAS_Settings::init();
		KAS_Populate::init();
		KAS_Frontend::init();
	}

	public static function dependencies_met() {
		return class_exists('WooCommerce') && class_exists('GFForms');
	}

	public static function missing_dependencies_notice() {
		if (!current_user_can('activate_plugins')) return;

		echo '<div class="notice notice-error"><p>';
		echo esc_html('Kafra Auto Skjema krever at WooCommerce og Gravity Forms er installert og aktivert.');
		echo '</p></div>';
	}

	public static function get_settings() {
		$raw = get_option(KAS_OPTION_KEY, array());
		if (!is_array($raw)) {
			$raw = array();
		}

		return self::normalize_settings($raw);
	}

	public static function normalize_settings($raw) {
		// Migrate old single-form format.
		if (!isset($raw['forms']) && !empty($raw['form_id'])) {
			$form_id = (int) $raw['form_id'];
			$raw = array(
				'forms' => array(
					$form_id => array(
						'form_id'          => $form_id,
						'heading'          => isset($raw['heading']) ? $raw['heading'] : 'Interessert i en lignende løsning?',
						'intro'            => isset($raw['intro']) ? $raw['intro'] : '',
						'auto_display'     => !empty($raw['auto_display']) ? 1 : 0,
						'display_hook'     => isset($raw['display_hook']) ? $raw['display_hook'] : 'woocommerce_after_single_product_summary',
						'display_priority' => isset($raw['display_priority']) ? (int) $raw['display_priority'] : 25,
						'mappings'         => !empty($raw['mappings']) && is_array($raw['mappings']) ? $raw['mappings'] : self::default_mappings(),
					),
				),
			);
			update_option(KAS_OPTION_KEY, $raw, false);
		}

		if (empty($raw['forms']) || !is_array($raw['forms'])) {
			$raw['forms'] = array();
		}

		$forms = array();
		foreach ($raw['forms'] as $key => $config) {
			if (!is_array($config)) continue;
			$form_id = isset($config['form_id']) ? (int) $config['form_id'] : (int) $key;
			if (!$form_id) continue;

			$forms[$form_id] = self::normalize_form_config($config, $form_id);
		}

		return array('forms' => $forms);
	}

	public static function normalize_form_config($config, $form_id = 0) {
		$form_id = $form_id ? $form_id : (isset($config['form_id']) ? (int) $config['form_id'] : 0);

		$defaults = self::default_form_config($form_id);
		$config   = wp_parse_args(is_array($config) ? $config : array(), $defaults);
		$config['form_id'] = $form_id;

		if (empty($config['mappings']) || !is_array($config['mappings'])) {
			$config['mappings'] = self::default_mappings();
		}

		return $config;
	}

	public static function default_form_config($form_id = 0) {
		return array(
			'form_id'          => (int) $form_id,
			'heading'          => 'Interessert i en lignende løsning?',
			'intro'            => 'Feltene under er foreslått ut fra produktet du ser på. Juster dem gjerne før du sender inn.',
			'auto_display'     => 1,
			'display_hook'     => 'woocommerce_after_single_product_summary',
			'display_priority' => 25,
			'mappings'         => self::default_mappings(),
		);
	}

	public static function get_form_config($form_id) {
		$form_id  = (int) $form_id;
		$settings = self::get_settings();

		if (!$form_id || empty($settings['forms'][$form_id])) {
			return null;
		}

		return $settings['forms'][$form_id];
	}

	public static function get_configured_forms() {
		$settings = self::get_settings();
		return !empty($settings['forms']) ? $settings['forms'] : array();
	}

	public static function save_form_config($config) {
		$form_id = isset($config['form_id']) ? (int) $config['form_id'] : 0;
		if (!$form_id) {
			return false;
		}

		$settings = self::get_settings();
		$settings['forms'][$form_id] = self::normalize_form_config($config, $form_id);

		return update_option(KAS_OPTION_KEY, $settings, false);
	}

	public static function delete_form_config($form_id) {
		$form_id  = (int) $form_id;
		$settings = self::get_settings();

		if (empty($settings['forms'][$form_id])) {
			return false;
		}

		unset($settings['forms'][$form_id]);
		return update_option(KAS_OPTION_KEY, $settings, false);
	}

	public static function default_mappings() {
		return array(
			array(
				'source'     => 'product_title',
				'source_key' => '',
				'field_id'   => '',
				'label'      => 'Produktnavn',
				'locked'     => 0,
			),
			array(
				'source'     => 'product_url',
				'source_key' => '',
				'field_id'   => '',
				'label'      => 'Produktlenke',
				'locked'     => 0,
			),
			array(
				'source'     => 'attribute',
				'source_key' => 'pa_mal',
				'field_id'   => '',
				'label'      => 'Mål',
				'locked'     => 0,
			),
			array(
				'source'     => 'attribute',
				'source_key' => 'pa_materiale',
				'field_id'   => '',
				'label'      => 'Materiale',
				'locked'     => 0,
			),
			array(
				'source'     => 'attribute',
				'source_key' => 'pa_bruksomrade',
				'field_id'   => '',
				'label'      => 'Bruksområde',
				'locked'     => 0,
			),
		);
	}

	public static function get_source_options() {
		return array(
			'product_title'             => 'Produktnavn',
			'product_sku'               => 'SKU',
			'product_id'                => 'Produkt-ID',
			'product_url'               => 'Produktlenke (URL)',
			'product_short_description' => 'Kort beskrivelse',
			'product_categories'        => 'Produktkategorier',
			'product_tags'              => 'Produktetiketter',
			'attribute'                 => 'WooCommerce-attributt',
			'acf'                       => 'ACF-felt',
			'meta'                      => 'Custom meta-nøkkel',
		);
	}
}

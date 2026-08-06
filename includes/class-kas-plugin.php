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
		$defaults = array(
			'form_id'          => 0,
			'heading'          => 'Interessert i en lignende løsning?',
			'intro'            => 'Feltene under er foreslått ut fra produktet du ser på. Juster dem gjerne før du sender inn.',
			'auto_display'     => 1,
			'display_hook'     => 'woocommerce_after_single_product_summary',
			'display_priority' => 25,
			'mappings'         => array(),
			'readonly_fields'  => 0,
		);

		$settings = get_option(KAS_OPTION_KEY, array());
		if (!is_array($settings)) {
			$settings = array();
		}

		$settings = wp_parse_args($settings, $defaults);

		if (empty($settings['mappings']) || !is_array($settings['mappings'])) {
			$settings['mappings'] = self::default_mappings();
		}

		return $settings;
	}

	public static function default_mappings() {
		return array(
			array(
				'source'     => 'product_title',
				'source_key' => '',
				'field_id'   => '',
				'label'      => 'Produktnavn',
			),
			array(
				'source'     => 'product_url',
				'source_key' => '',
				'field_id'   => '',
				'label'      => 'Produktlenke',
			),
			array(
				'source'     => 'attribute',
				'source_key' => 'pa_mal',
				'field_id'   => '',
				'label'      => 'Mål',
			),
			array(
				'source'     => 'attribute',
				'source_key' => 'pa_materiale',
				'field_id'   => '',
				'label'      => 'Materiale',
			),
			array(
				'source'     => 'attribute',
				'source_key' => 'pa_bruksomrade',
				'field_id'   => '',
				'label'      => 'Bruksområde',
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

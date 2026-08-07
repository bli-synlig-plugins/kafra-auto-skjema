<?php

if (!defined('ABSPATH')) exit;

class KAS_Populate {

	public static function init() {
		add_filter('gform_pre_render', array(__CLASS__, 'pre_render'));
		add_filter('gform_pre_validation', array(__CLASS__, 'pre_render'));
		add_filter('gform_pre_submission_filter', array(__CLASS__, 'pre_render'));
		add_filter('gform_admin_pre_render', array(__CLASS__, 'pre_render'));
		add_filter('gform_field_content', array(__CLASS__, 'maybe_readonly_field'), 10, 5);
	}

	public static function pre_render($form) {
		if (empty($form['id'])) {
			return $form;
		}

		$config = KAS_Plugin::get_form_config((int) $form['id']);
		if (!$config) {
			return $form;
		}

		$form_id = (int) $config['form_id'];

		if (self::is_form_submitted($form_id)) {
			return $form;
		}

		$product = self::get_current_product();
		if (!$product) {
			return $form;
		}

		$values = self::get_mapped_values($product, $config['mappings']);
		if (empty($values)) {
			return $form;
		}

		foreach ($form['fields'] as &$field) {
			$field_id = (string) $field->id;

			if (!empty($field->inputs) && is_array($field->inputs)) {
				foreach ($field->inputs as $input) {
					$input_id = (string) $input['id'];
					if (isset($values[$input_id]) && $values[$input_id] !== '') {
						self::set_input_default($field, $input_id, $values[$input_id]);
					}
				}
			}

			if (isset($values[$field_id]) && $values[$field_id] !== '') {
				$field->defaultValue = $values[$field_id];

				if (in_array($field->type, array('select', 'radio'), true)) {
					self::select_matching_choice($field, $values[$field_id]);
				}
			}
		}
		unset($field);

		return $form;
	}

	public static function maybe_readonly_field($content, $field, $value, $lead_id, $form_id) {
		$config = KAS_Plugin::get_form_config((int) $form_id);
		if (!$config) {
			return $content;
		}

		$locked_ids = self::get_locked_field_ids($config['mappings']);
		if (empty($locked_ids)) {
			return $content;
		}

		$names = self::get_locked_input_names_for_field($field, $locked_ids);
		if (empty($names)) {
			return $content;
		}

		foreach ($names as $name) {
			$content = preg_replace_callback(
				'/<(input|textarea)\b([^>]*\bname=["\']' . preg_quote($name, '/') . '["\'][^>]*)>/i',
				function ($m) {
					if (preg_match('/\breadonly\b/i', $m[0])) {
						return $m[0];
					}

					if (
						strtolower($m[1]) === 'input'
						&& preg_match('/\btype=["\']/i', $m[0])
						&& !preg_match('/\btype=["\'](?:text|email|url|tel|number|hidden)["\']/i', $m[0])
					) {
						return $m[0];
					}

					return '<' . $m[1] . $m[2] . ' readonly="readonly">';
				},
				$content,
				1
			);
		}

		return $content;
	}

	private static function get_locked_input_names_for_field($field, $locked_ids) {
		$names = array();
		$field_id = (string) $field->id;

		// Parent field locked → lock the simple input and all visible sub-inputs.
		if (isset($locked_ids[$field_id])) {
			$names[] = 'input_' . $field_id;

			if (!empty($field->inputs) && is_array($field->inputs)) {
				foreach ($field->inputs as $input) {
					if (empty($input['id']) || !empty($input['isHidden'])) {
						continue;
					}
					// GF uses dots in name attributes: input_1.3, input_1.6
					$names[] = 'input_' . $input['id'];
				}
			}
		}

		if (!empty($field->inputs) && is_array($field->inputs)) {
			foreach ($field->inputs as $input) {
				if (empty($input['id'])) {
					continue;
				}

				$input_id = (string) $input['id'];
				if (!isset($locked_ids[$input_id])) {
					continue;
				}

				// Keep the dot — GF markup is name="input_1.6", not input_1_6.
				$names[] = 'input_' . $input_id;
			}
		}

		return array_values(array_unique($names));
	}

	public static function get_mapped_values($product, $mappings) {
		$values = array();

		foreach ((array) $mappings as $row) {
			if (empty($row['field_id']) || empty($row['source'])) {
				continue;
			}

			$key   = isset($row['source_key']) ? $row['source_key'] : '';
			$value = self::resolve_source_value($product, $row['source'], $key);

			if ($value === '' || $value === null) {
				continue;
			}

			$values[(string) $row['field_id']] = $value;
		}

		return apply_filters('kas_mapped_values', $values, $product, $mappings);
	}

	private static function get_locked_field_ids($mappings) {
		$locked = array();

		foreach ((array) $mappings as $row) {
			if (empty($row['locked']) || empty($row['field_id'])) {
				continue;
			}
			$locked[(string) $row['field_id']] = true;
		}

		return $locked;
	}

	public static function resolve_source_value($product, $source, $source_key = '') {
		switch ($source) {
			case 'product_title':
				return $product->get_name();

			case 'product_sku':
				return (string) $product->get_sku();

			case 'product_id':
				return (string) $product->get_id();

			case 'product_url':
				return get_permalink($product->get_id());

			case 'product_short_description':
				return wp_strip_all_tags($product->get_short_description());

			case 'product_categories':
				return self::get_term_names($product->get_id(), 'product_cat');

			case 'product_tags':
				return self::get_term_names($product->get_id(), 'product_tag');

			case 'attribute':
				if (!$source_key) return '';
				$value = $product->get_attribute($source_key);
				if ($value === '' && strpos($source_key, 'pa_') === 0) {
					$value = $product->get_attribute(substr($source_key, 3));
				}
				return $value;

			case 'acf':
				if (!$source_key || !function_exists('get_field')) return '';
				return self::stringify_value(get_field($source_key, $product->get_id()));

			case 'meta':
				if (!$source_key) return '';
				return self::stringify_value(get_post_meta($product->get_id(), $source_key, true));
		}

		return '';
	}

	public static function get_current_product() {
		if (!function_exists('wc_get_product')) {
			return null;
		}

		$product_id = 0;

		if (is_product()) {
			$product_id = get_queried_object_id();
		} elseif (!empty($GLOBALS['product']) && is_a($GLOBALS['product'], 'WC_Product')) {
			$product_id = $GLOBALS['product']->get_id();
		} elseif (
			doing_action('woocommerce_after_single_product_summary')
			|| doing_action('woocommerce_after_single_product')
			|| doing_action('woocommerce_before_single_product_summary')
			|| doing_action('woocommerce_product_after_tabs')
		) {
			$product_id = get_the_ID();
		}

		$product_id = (int) apply_filters('kas_current_product_id', $product_id);
		if (!$product_id) {
			return null;
		}

		$product = wc_get_product($product_id);
		return $product ? $product : null;
	}

	private static function is_form_submitted($form_id) {
		return isset($_POST['gform_submit']) && (int) $_POST['gform_submit'] === (int) $form_id;
	}

	private static function get_term_names($product_id, $taxonomy) {
		$terms = get_the_terms($product_id, $taxonomy);
		if (empty($terms) || is_wp_error($terms)) {
			return '';
		}

		return implode(', ', wp_list_pluck($terms, 'name'));
	}

	private static function stringify_value($value) {
		if (is_array($value)) {
			$flat = array();
			array_walk_recursive($value, function ($item) use (&$flat) {
				if (is_scalar($item) && $item !== '') {
					$flat[] = (string) $item;
				}
			});
			return implode(', ', $flat);
		}

		if (is_object($value)) {
			if (isset($value->post_title)) return (string) $value->post_title;
			if (isset($value->name)) return (string) $value->name;
			return '';
		}

		if (is_bool($value)) {
			return $value ? '1' : '0';
		}

		return is_scalar($value) ? (string) $value : '';
	}

	private static function select_matching_choice($field, $value) {
		if (empty($field->choices) || !is_array($field->choices)) {
			return;
		}

		foreach ($field->choices as &$choice) {
			$choice_value = isset($choice['value']) ? $choice['value'] : '';
			$choice_text  = isset($choice['text']) ? $choice['text'] : '';

			$choice['isSelected'] = (
				(string) $choice_value === (string) $value
				|| (string) $choice_text === (string) $value
				|| strcasecmp((string) $choice_text, (string) $value) === 0
			);
		}
		unset($choice);
	}

	private static function set_input_default($field, $input_id, $value) {
		if (empty($field->inputs) || !is_array($field->inputs)) {
			return;
		}

		foreach ($field->inputs as &$input) {
			if ((string) $input['id'] === (string) $input_id) {
				$input['defaultValue'] = $value;
			}
		}
		unset($input);

		if (count($field->inputs) === 1) {
			$field->defaultValue = $value;
		}
	}
}

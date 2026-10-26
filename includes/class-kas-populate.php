<?php

if (!defined('ABSPATH')) exit;

class KAS_Populate {

	private static $forced_checkbox_fields = array();

	public static function init() {
		add_filter('gform_pre_render', array(__CLASS__, 'pre_render'));
		add_filter('gform_pre_validation', array(__CLASS__, 'pre_render'));
		add_filter('gform_pre_submission_filter', array(__CLASS__, 'pre_render'));
		add_filter('gform_admin_pre_render', array(__CLASS__, 'pre_render'));
		add_filter('gform_field_content', array(__CLASS__, 'maybe_readonly_field'), 10, 5);
		add_filter('gform_field_content', array(__CLASS__, 'maybe_force_checkbox_checked'), 20, 5);
		add_filter('gform_field_choice_markup_pre_render', array(__CLASS__, 'maybe_check_choice_markup'), 10, 4);
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
			$field_id   = (string) $field->id;
			$input_type = self::field_input_type($field);

			// checkbox + GF "Flervalg" (multi_choice) med flere valg.
			if (self::is_multi_select_choice_field($field)) {
				$raw = self::get_choice_source_value($field, $values);
				if ($raw !== '') {
					self::select_matching_choices($field, $raw);
					self::$forced_checkbox_fields[(int) $form['id'] . ':' . $field_id] = true;
				}
				continue;
			}

			if (!empty($field->inputs) && is_array($field->inputs) && $input_type !== 'radio') {
				foreach ($field->inputs as $input) {
					$input_id = (string) $input['id'];
					if (isset($values[$input_id]) && $values[$input_id] !== '') {
						self::set_input_default($field, $input_id, $values[$input_id]);
					}
				}
			}

			if (isset($values[$field_id]) && $values[$field_id] !== '') {
				$field->defaultValue = $values[$field_id];

				if (in_array($input_type, array('select', 'radio'), true)) {
					self::select_matching_choices($field, $values[$field_id]);
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
			$quoted = preg_quote($name, '/');

			// <select> ignorerer readonly — bruk disabled + hidden så verdien fortsatt sendes inn.
			$content = preg_replace_callback(
				'/<select\b([^>]*\bname=["\']' . $quoted . '["\'][^>]*)>(.*?)<\/select>/is',
				function ($m) use ($name) {
					$attrs = $m[1];
					$inner = $m[2];

					if (!preg_match('/\bdisabled\b/i', $attrs)) {
						$attrs .= ' disabled="disabled"';
					}

					$selected = '';
					if (preg_match('/<option\b([^>]*\bselected\b[^>]*)>/i', $inner, $om)) {
						if (preg_match('/\bvalue=["\']([^"\']*)["\']/i', $om[1], $vm)) {
							$selected = html_entity_decode($vm[1], ENT_QUOTES, 'UTF-8');
						} elseif (preg_match('/<option\b[^>]*\bselected\b[^>]*>(.*?)<\/option>/is', $inner, $tm)) {
							$selected = trim(wp_strip_all_tags($tm[1]));
						}
					} elseif (preg_match('/<option\b([^>]*)>(.*?)<\/option>/is', $inner, $fm)) {
						// Første option hvis ingen er markert selected.
						if (preg_match('/\bvalue=["\']([^"\']*)["\']/i', $fm[1], $vm)) {
							$selected = html_entity_decode($vm[1], ENT_QUOTES, 'UTF-8');
						} else {
							$selected = trim(wp_strip_all_tags($fm[2]));
						}
					}

					$out = '<select' . $attrs . '>' . $inner . '</select>';
					$out .= '<input type="hidden" name="' . esc_attr($name) . '" value="' . esc_attr($selected) . '">';
					return $out;
				},
				$content,
				1
			);

			$content = preg_replace_callback(
				'/<(input|textarea)\b([^>]*\bname=["\']' . $quoted . '["\'][^>]*)>/i',
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

	public static function maybe_check_choice_markup($choice_markup, $choice, $field, $value) {
		if (empty($choice['isSelected']) || empty($field->id)) {
			return $choice_markup;
		}

		$form_id = !empty($field->formId) ? (int) $field->formId : 0;
		$key     = $form_id . ':' . (string) $field->id;

		if (empty(self::$forced_checkbox_fields[$key])) {
			// Fallback if formId wasn't set on the field object yet.
			$matched = false;
			foreach (self::$forced_checkbox_fields as $stored_key => $flag) {
				if (!$flag) {
					continue;
				}
				if (substr((string) $stored_key, -1 * (strlen((string) $field->id) + 1)) === ':' . $field->id) {
					$matched = true;
					break;
				}
			}
			if (!$matched) {
				return $choice_markup;
			}
		}

		if (preg_match('/\bchecked(\s*=|\s|>|\/)/i', $choice_markup)) {
			return $choice_markup;
		}

		return preg_replace('/<input\b/i', '<input checked="checked"', $choice_markup, 1);
	}

	public static function maybe_force_checkbox_checked($content, $field, $value, $lead_id, $form_id) {
		if (!self::is_multi_select_choice_field($field) || empty($field->id)) {
			return $content;
		}

		$key = (int) $form_id . ':' . (string) $field->id;
		if (empty(self::$forced_checkbox_fields[$key])) {
			return $content;
		}

		if (empty($field->choices) || !is_array($field->choices)) {
			return $content;
		}

		$needles = array();
		foreach ($field->choices as $choice) {
			if (empty($choice['isSelected'])) {
				continue;
			}
			if (isset($choice['value']) && $choice['value'] !== '') {
				$needles[] = (string) $choice['value'];
			}
			if (isset($choice['text']) && $choice['text'] !== '') {
				$needles[] = (string) $choice['text'];
			}
		}

		if (empty($needles)) {
			return $content;
		}

		return preg_replace_callback(
			'/<input\b([^>]*\btype=["\']checkbox["\'][^>]*)>/i',
			function ($m) use ($needles) {
				$tag = $m[0];

				if (preg_match('/\bchecked(\s*=|\s|>|\/)/i', $tag) || preg_match('/\bgfield_choice_all_toggle\b/i', $tag)) {
					return $tag;
				}

				if (!preg_match('/\bvalue=["\']([^"\']*)["\']/i', $tag, $vm)) {
					return $tag;
				}

				$raw = html_entity_decode($vm[1], ENT_QUOTES, 'UTF-8');
				$raw = trim(explode('|', $raw)[0]);

				if (!self::choice_matches_needles($raw, $raw, $needles)) {
					return $tag;
				}

				return preg_replace('/<input\b/i', '<input checked="checked"', $tag, 1);
			},
			$content
		);
	}

	private static function field_input_type($field) {
		if (is_object($field) && is_callable(array($field, 'get_input_type'))) {
			return (string) $field->get_input_type();
		}

		return isset($field->type) ? (string) $field->type : '';
	}

	private static function is_multi_select_choice_field($field) {
		// Classic Checkboxes, or Flervalg (multi_choice) with "multiple" → inputType checkbox.
		$input_type = self::field_input_type($field);
		$type       = isset($field->type) ? (string) $field->type : '';

		return $input_type === 'checkbox' || $type === 'checkbox';
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

	private static function get_choice_source_value($field, $values) {
		$field_id = (string) $field->id;

		if (isset($values[$field_id]) && $values[$field_id] !== '') {
			return (string) $values[$field_id];
		}

		$parts = array();
		if (!empty($field->inputs) && is_array($field->inputs)) {
			foreach ($field->inputs as $input) {
				if (empty($input['id'])) {
					continue;
				}
				$input_id = (string) $input['id'];
				if (isset($values[$input_id]) && $values[$input_id] !== '') {
					$parts[] = (string) $values[$input_id];
				}
			}
		}

		return $parts ? implode(', ', $parts) : '';
	}

	private static function select_matching_choices($field, $value) {
		if (empty($field->choices) || !is_array($field->choices)) {
			return;
		}

		$needles = self::split_choice_values($value);
		if (empty($needles)) {
			return;
		}

		$choices      = array();
		$any_selected = false;

		foreach ($field->choices as $choice) {
			$choice_value = isset($choice['value']) ? (string) $choice['value'] : '';
			$choice_text  = isset($choice['text']) ? (string) $choice['text'] : '';
			$selected    = self::choice_matches_needles($choice_value, $choice_text, $needles);

			$choice['isSelected'] = $selected;
			$choices[] = $choice;

			if ($selected) {
				$any_selected = true;
			}
		}

		$field->choices = $choices;

		// Keep a readable default for exports/conditionals; checked state comes from isSelected + markup filter.
		if (self::is_multi_select_choice_field($field) && $any_selected) {
			$selected_labels = array();
			foreach ($choices as $choice) {
				if (empty($choice['isSelected'])) {
					continue;
				}
				$selected_labels[] = isset($choice['value']) && $choice['value'] !== ''
					? $choice['value']
					: (isset($choice['text']) ? $choice['text'] : '');
			}
			$field->defaultValue = implode(', ', array_filter($selected_labels, 'strlen'));
		}
	}

	private static function choice_matches_needles($choice_value, $choice_text, $needles) {
		foreach ($needles as $needle) {
			$needle = html_entity_decode(trim((string) $needle), ENT_QUOTES, 'UTF-8');
			$cv     = html_entity_decode(trim((string) $choice_value), ENT_QUOTES, 'UTF-8');
			$ct     = html_entity_decode(trim((string) $choice_text), ENT_QUOTES, 'UTF-8');

			if (
				$cv === $needle
				|| $ct === $needle
				|| strcasecmp($ct, $needle) === 0
				|| strcasecmp($cv, $needle) === 0
			) {
				return true;
			}
		}

		return false;
	}

	private static function split_choice_values($value) {
		if (is_array($value)) {
			$parts = $value;
		} else {
			$parts = preg_split('/\s*,\s*/', (string) $value);
		}

		$parts = array_map('trim', $parts);
		$parts = array_filter($parts, function ($part) {
			return $part !== '';
		});

		return array_values($parts);
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

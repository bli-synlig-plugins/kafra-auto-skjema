<?php

if (!defined('ABSPATH')) exit;

class KAS_Frontend {

	private static $rendered = array();

	public static function init() {
		add_shortcode('kafra_produkt_skjema', array(__CLASS__, 'shortcode'));
		add_action('wp', array(__CLASS__, 'maybe_register_display_hooks'));
		add_action('wp_enqueue_scripts', array(__CLASS__, 'enqueue_styles'));
	}

	public static function maybe_register_display_hooks() {
		if (!is_product()) return;

		foreach (KAS_Plugin::get_configured_forms() as $form_id => $config) {
			if (empty($config['auto_display'])) {
				continue;
			}

			$hook     = !empty($config['display_hook']) ? $config['display_hook'] : 'woocommerce_after_single_product_summary';
			$priority = !empty($config['display_priority']) ? (int) $config['display_priority'] : 25;

			add_action($hook, function () use ($form_id) {
				echo self::render_form((int) $form_id);
			}, $priority);
		}
	}

	public static function shortcode($atts = array()) {
		$atts = shortcode_atts(array(
			'form_id' => 0,
		), $atts, 'kafra_produkt_skjema');

		$form_id = (int) $atts['form_id'];
		if (!$form_id) {
			$forms = KAS_Plugin::get_configured_forms();
			if (empty($forms)) {
				return '';
			}
			$form_id = (int) key($forms);
		}

		return self::render_form($form_id);
	}

	public static function render_form($form_id = 0) {
		$form_id = (int) $form_id;
		$config  = KAS_Plugin::get_form_config($form_id);

		if (!$config || !function_exists('gravity_form')) {
			return '';
		}

		if (!is_admin() && isset(self::$rendered[$form_id])) {
			return '';
		}

		self::$rendered[$form_id] = true;

		ob_start();
		?>
		<section class="kas-inquiry" id="kas-inquiry-<?php echo esc_attr($form_id); ?>">
			<?php if (!empty($config['heading'])) : ?>
				<h2 class="kas-inquiry__heading"><?php echo esc_html($config['heading']); ?></h2>
			<?php endif; ?>

			<?php if (!empty($config['intro'])) : ?>
				<p class="kas-inquiry__intro"><?php echo esc_html($config['intro']); ?></p>
			<?php endif; ?>

			<div class="kas-inquiry__form">
				<?php gravity_form($form_id, false, false, false, null, true, 0, true); ?>
			</div>
		</section>
		<?php
		return ob_get_clean();
	}

	public static function enqueue_styles() {
		if (!is_product()) return;
		if (empty(KAS_Plugin::get_configured_forms())) return;

		wp_register_style('kas-frontend', false, array(), KAS_VERSION);
		wp_enqueue_style('kas-frontend');
		wp_add_inline_style('kas-frontend', '
			.kas-inquiry {
				margin: 3rem 0 2rem;
				padding: 2rem 0 0;
				border-top: 1px solid rgba(0,0,0,.08);
				clear: both;
			}
			.kas-inquiry__heading {
				margin: 0 0 .5rem;
				font-size: clamp(1.4rem, 2vw, 1.85rem);
				line-height: 1.25;
			}
			.kas-inquiry__intro {
				margin: 0 0 1.5rem;
				max-width: 42rem;
				opacity: .85;
			}
			.kas-inquiry__form input[readonly],
			.kas-inquiry__form textarea[readonly] {
				background: rgba(0,0,0,.03);
				cursor: default;
			}
		');
	}
}

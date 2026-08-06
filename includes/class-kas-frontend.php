<?php

if (!defined('ABSPATH')) exit;

class KAS_Frontend {

	private static $rendered = false;

	public static function init() {
		add_shortcode('kafra_produkt_skjema', array(__CLASS__, 'shortcode'));
		add_action('wp', array(__CLASS__, 'maybe_register_display_hook'));
		add_action('wp_enqueue_scripts', array(__CLASS__, 'enqueue_styles'));
	}

	public static function maybe_register_display_hook() {
		if (!is_product()) return;

		$settings = KAS_Plugin::get_settings();
		if (empty($settings['auto_display']) || empty($settings['form_id'])) {
			return;
		}

		$hook     = !empty($settings['display_hook']) ? $settings['display_hook'] : 'woocommerce_after_single_product_summary';
		$priority = !empty($settings['display_priority']) ? (int) $settings['display_priority'] : 25;

		add_action($hook, array(__CLASS__, 'render_on_product'), $priority);
	}

	public static function render_on_product() {
		echo self::render_form();
	}

	public static function shortcode($atts = array()) {
		$atts = shortcode_atts(array(
			'form_id' => 0,
		), $atts, 'kafra_produkt_skjema');

		return self::render_form((int) $atts['form_id']);
	}

	public static function render_form($form_id = 0) {
		$settings = KAS_Plugin::get_settings();
		$form_id  = $form_id ? $form_id : (int) $settings['form_id'];

		if (!$form_id || !function_exists('gravity_form')) {
			return '';
		}

		if (self::$rendered && !is_admin()) {
			return '';
		}

		self::$rendered = true;

		ob_start();
		?>
		<section class="kas-inquiry" id="kas-inquiry">
			<?php if (!empty($settings['heading'])) : ?>
				<h2 class="kas-inquiry__heading"><?php echo esc_html($settings['heading']); ?></h2>
			<?php endif; ?>

			<?php if (!empty($settings['intro'])) : ?>
				<p class="kas-inquiry__intro"><?php echo esc_html($settings['intro']); ?></p>
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

		$settings = KAS_Plugin::get_settings();
		if (empty($settings['form_id'])) return;

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

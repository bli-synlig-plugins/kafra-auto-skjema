<?php

if (!defined('ABSPATH')) exit;

class KAS_Frontend {

	private static $rendered = array();

	public static function init() {
		add_shortcode('kafra_produkt_skjema', array(__CLASS__, 'shortcode'));
		add_action('wp', array(__CLASS__, 'maybe_register_display_hooks'));
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

		$title_mode = !empty($config['title_mode']) ? $config['title_mode'] : 'custom';
		$desc_mode  = !empty($config['description_mode']) ? $config['description_mode'] : 'custom';

		ob_start();
		?>
		<section class="kas-inquiry" id="kas-inquiry-<?php echo esc_attr($form_id); ?>">
			<?php if ($title_mode === 'custom' && !empty($config['heading'])) : ?>
				<h2 class="kas-inquiry__heading"><?php echo esc_html($config['heading']); ?></h2>
			<?php endif; ?>

			<?php if ($desc_mode === 'custom' && !empty($config['intro'])) : ?>
				<p class="kas-inquiry__intro"><?php echo esc_html($config['intro']); ?></p>
			<?php endif; ?>

			<div class="kas-inquiry__form">
				<?php echo do_shortcode(KAS_Plugin::build_gf_shortcode($form_id, $config)); ?>
			</div>
		</section>
		<?php
		return ob_get_clean();
	}
}

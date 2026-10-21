<?php
defined( 'ABSPATH' ) || exit;

class SMC_Settings {

	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
	}

	public static function register() {
		register_setting(
			'smc_settings',
			'smc_settings',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
			)
		);
	}

	public static function sanitize( $in ) {
		$old = smc_settings();
		$in  = is_array( $in ) ? $in : array();
		$out = array();

		$out['provider'] = isset( $in['provider'], smc_providers()[ $in['provider'] ] ) ? $in['provider'] : $old['provider'];
		foreach ( smc_providers() as $id => $p ) {
			// Để trống ô API key nghĩa là giữ key cũ.
			$key              = isset( $in[ $p['key'] ] ) ? trim( (string) $in[ $p['key'] ] ) : '';
			$out[ $p['key'] ] = '' !== $key ? sanitize_text_field( $key ) : $old[ $p['key'] ];
			if ( ! empty( $in[ 'clear_' . $p['key'] ] ) ) {
				$out[ $p['key'] ] = '';
			}
			$model              = isset( $in[ $p['model'] ] ) ? smc_clean_model( $in[ $p['model'] ] ) : '';
			$out[ $p['model'] ] = '' !== $model ? $model : $p['default'];
		}
		$pexels            = isset( $in['pexels_key'] ) ? trim( (string) $in['pexels_key'] ) : '';
		$out['pexels_key'] = '' !== $pexels ? sanitize_text_field( $pexels ) : $old['pexels_key'];
		if ( ! empty( $in['clear_pexels'] ) ) {
			$out['pexels_key'] = '';
		}
		$out['effort']     = isset( $in['effort'] ) && in_array( $in['effort'], array( 'low', 'medium', 'high' ), true ) ? $in['effort'] : 'medium';
		$out['width']      = (string) max( 320, min( 1600, isset( $in['width'] ) ? (int) $in['width'] : 760 ) );
		$out['style_note'] = isset( $in['style_note'] ) ? sanitize_textarea_field( $in['style_note'] ) : '';
		foreach ( smc_tokens() as $name => $def ) {
			$val                     = isset( $in[ 'token_' . $name ] ) ? smc_clean_token( sanitize_text_field( $in[ 'token_' . $name ] ) ) : '';
			$out[ 'token_' . $name ] = '' !== $val ? $val : $def[1];
		}
		return $out;
	}

	/** Trang để dò màu và font: bài mới nhất đã đăng (không phải bài Smart Content), không có thì trang chủ. */
	private static function probe_url() {
		$posts = get_posts(
			array(
				'numberposts' => 1,
				'post_status' => 'publish',
				'meta_query'  => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => '_smc',
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);
		return add_query_arg( 'smc_probe', 1, $posts ? get_permalink( $posts[0] ) : home_url( '/' ) );
	}

	public static function page() {
		$s       = smc_settings();
		wp_enqueue_script( 'smc-settings', SMC_URL . 'assets/settings.js', array( 'wp-i18n' ), SMC_VERSION, true );
		wp_set_script_translations( 'smc-settings', 'smart-content-seo', SMC_DIR . 'languages' );
		wp_localize_script( 'smc-settings', 'SMC_PROBE', array( 'url' => self::probe_url() ) );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Smart Content: Settings', 'smart-content-seo' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'smc_settings' ); ?>

				<h2><?php esc_html_e( 'AI connection', 'smart-content-seo' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="smc-provider"><?php esc_html_e( 'AI provider', 'smart-content-seo' ); ?></label></th>
						<td>
							<select id="smc-provider" name="smc_settings[provider]">
								<?php foreach ( smc_providers() as $id => $p ) : ?>
									<option value="<?php echo esc_attr( $id ); ?>" <?php selected( smc_provider(), $id ); ?>><?php echo esc_html( $p['label'] ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Post content and the photos you choose are sent to this provider for processing; usage is billed to the account of the key.', 'smart-content-seo' ); ?></p>
						</td>
					</tr>
				</table>
				<?php foreach ( smc_providers() as $id => $p ) : ?>
					<?php $has = '' !== smc_api_key( $id ); ?>
					<table class="form-table smc-provider-rows" role="presentation" data-provider="<?php echo esc_attr( $id ); ?>">
						<tr>
							<th scope="row"><label for="smc-key-<?php echo esc_attr( $id ); ?>">
								<?php
								/* translators: %s: AI provider name, e.g. Google Gemini. */
								echo esc_html( sprintf( __( '%s API key', 'smart-content-seo' ), $p['label'] ) );
								?>
							</label></th>
							<td>
								<?php if ( defined( $p['const'] ) && constant( $p['const'] ) ) : ?>
									<p>
										<?php
										/* translators: %s: PHP constant name, e.g. SMC_API_KEY. */
										echo wp_kses_post( sprintf( __( 'Using the key defined in <code>wp-config.php</code> (<code>%s</code>).', 'smart-content-seo' ), esc_html( $p['const'] ) ) );
										?>
									</p>
								<?php else : ?>
									<input type="password" id="smc-key-<?php echo esc_attr( $id ); ?>" name="smc_settings[<?php echo esc_attr( $p['key'] ); ?>]" class="regular-text" autocomplete="off"
										placeholder="<?php echo $has ? esc_attr__( 'Saved. Leave empty to keep it.', 'smart-content-seo' ) : esc_attr__( 'Paste your API key here', 'smart-content-seo' ); ?>">
									<?php if ( $has ) : ?>
										<label style="margin-left:12px"><input type="checkbox" name="smc_settings[clear_<?php echo esc_attr( $p['key'] ); ?>]" value="1"> <?php esc_html_e( 'Delete saved key', 'smart-content-seo' ); ?></label>
									<?php endif; ?>
									<p class="description">
										<?php
										printf(
											/* translators: %s: link to the provider page where the API key is created. */
											esc_html__( 'Get a key at %s.', 'smart-content-seo' ),
											'<a href="' . esc_url( $p['get_key'] ) . '" target="_blank" rel="noopener">' . esc_html( wp_parse_url( $p['get_key'], PHP_URL_HOST ) ) . '</a>'
										);
										?>
										<?php echo esc_html( $p['note'] ); ?>
									</p>
								<?php endif; ?>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="smc-model-<?php echo esc_attr( $id ); ?>"><?php esc_html_e( 'Model', 'smart-content-seo' ); ?></label></th>
							<td>
								<input type="text" id="smc-model-<?php echo esc_attr( $id ); ?>" name="smc_settings[<?php echo esc_attr( $p['model'] ); ?>]" class="regular-text code"
									list="smc-models-<?php echo esc_attr( $id ); ?>" value="<?php echo esc_attr( smc_model( $id ) ); ?>">
								<datalist id="smc-models-<?php echo esc_attr( $id ); ?>">
									<?php foreach ( $p['models'] as $model => $label ) : ?>
										<option value="<?php echo esc_attr( $model ); ?>"><?php echo esc_html( $label ); ?></option>
									<?php endforeach; ?>
								</datalist>
								<p class="description">
									<?php
									$list = array();
									foreach ( $p['models'] as $model => $label ) {
										$list[] = '<code>' . esc_html( $model ) . '</code>: ' . esc_html( preg_replace( '/^[^(]*\(|\)$/', '', $label ) );
									}
									echo wp_kses_post( implode( '<br>', $list ) );
									?>
									<br><?php esc_html_e( 'When a new model comes out, type its exact model ID here.', 'smart-content-seo' ); ?>
								</p>
							</td>
						</tr>
					</table>
				<?php endforeach; ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="smc-effort"><?php esc_html_e( 'Reasoning effort', 'smart-content-seo' ); ?></label></th>
						<td>
							<select id="smc-effort" name="smc_settings[effort]">
								<option value="low" <?php selected( $s['effort'], 'low' ); ?>><?php esc_html_e( 'Low (faster, cheaper)', 'smart-content-seo' ); ?></option>
								<option value="medium" <?php selected( $s['effort'], 'medium' ); ?>><?php esc_html_e( 'Medium', 'smart-content-seo' ); ?></option>
								<option value="high" <?php selected( $s['effort'], 'high' ); ?>><?php esc_html_e( 'High (slower, costs more)', 'smart-content-seo' ); ?></option>
							</select>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Free stock photos for AI-written posts', 'smart-content-seo' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="smc-pexels"><?php esc_html_e( 'Pexels API key', 'smart-content-seo' ); ?></label></th>
						<td>
							<?php if ( defined( 'SMC_PEXELS_KEY' ) && SMC_PEXELS_KEY ) : ?>
								<p>
									<?php
									/* translators: %s: PHP constant name, e.g. SMC_API_KEY. */
									echo wp_kses_post( sprintf( __( 'Using the key defined in <code>wp-config.php</code> (<code>%s</code>).', 'smart-content-seo' ), 'SMC_PEXELS_KEY' ) );
									?>
								</p>
							<?php else : ?>
								<input type="password" id="smc-pexels" name="smc_settings[pexels_key]" class="regular-text" autocomplete="off"
									placeholder="<?php echo '' !== $s['pexels_key'] ? esc_attr__( 'Saved. Leave empty to keep it.', 'smart-content-seo' ) : esc_attr__( 'Optional', 'smart-content-seo' ); ?>">
								<?php if ( '' !== $s['pexels_key'] ) : ?>
									<label style="margin-left:12px"><input type="checkbox" name="smc_settings[clear_pexels]" value="1"> <?php esc_html_e( 'Delete saved key', 'smart-content-seo' ); ?></label>
								<?php endif; ?>
								<p class="description">
									<?php
									printf(
										/* translators: %s: link to pexels.com/api. */
										esc_html__( 'Recommended: Pexels has far more and better photos. Get a free key at %s.', 'smart-content-seo' ),
										'<a href="https://www.pexels.com/api/" target="_blank" rel="noopener">pexels.com/api</a>'
									);
									?>
									<?php esc_html_e( 'Without a key the plugin searches Openverse and only takes CC0 and public domain photos (free to use, no attribution required).', 'smart-content-seo' ); ?>
									<?php esc_html_e( 'Photos are downloaded to the site\'s Media Library; the author and source are noted in the photo description.', 'smart-content-seo' ); ?>
								</p>
							<?php endif; ?>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Site style', 'smart-content-seo' ); ?></h2>
				<p>
					<?php esc_html_e( 'The AI uses these values so posts match the site design. Changing them here updates every published Smart Content post.', 'smart-content-seo' ); ?>
					<button type="button" class="button" id="smc-probe"><?php esc_html_e( 'Detect from site', 'smart-content-seo' ); ?></button>
					<span id="smc-probe-msg" style="margin-left:8px"></span>
				</p>
				<table class="form-table" role="presentation">
					<?php foreach ( smc_tokens() as $name => $def ) : ?>
						<tr>
							<th scope="row"><label for="smc-t-<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $def[0] ); ?></label></th>
							<td>
								<input type="text" class="regular-text code" id="smc-t-<?php echo esc_attr( $name ); ?>" data-token="<?php echo esc_attr( $name ); ?>"
									name="smc_settings[token_<?php echo esc_attr( $name ); ?>]" value="<?php echo esc_attr( $s[ 'token_' . $name ] ); ?>">
								<?php if ( false === strpos( $name, 'font' ) && 'radius' !== $name ) : ?>
									<span class="smc-swatch" style="display:inline-block;width:22px;height:22px;vertical-align:middle;border:1px solid #c3c4c7;border-radius:4px"></span>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					<tr>
						<th scope="row"><label for="smc-width"><?php esc_html_e( 'Content column width (px)', 'smart-content-seo' ); ?></label></th>
						<td><input type="number" id="smc-width" name="smc_settings[width]" value="<?php echo esc_attr( $s['width'] ); ?>" min="320" max="1600" class="small-text"></td>
					</tr>
					<tr>
						<th scope="row"><label for="smc-note"><?php esc_html_e( 'Style description', 'smart-content-seo' ); ?></label></th>
						<td>
							<textarea id="smc-note" name="smc_settings[style_note]" rows="4" class="large-text" placeholder="<?php esc_attr_e( 'For example: minimal, lots of white space, square corners, no shadows. Friendly tone.', 'smart-content-seo' ); ?>"><?php echo esc_textarea( $s['style_note'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Optional. A few sentences about the site\'s design taste; the AI will follow it.', 'smart-content-seo' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}

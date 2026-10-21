<?php
defined( 'ABSPATH' ) || exit;

/** Quyền cần có để dùng Smart Content. */
function smc_cap() {
	return apply_filters( 'smc_capability', 'manage_options' );
}

/** Các biến thiết kế: tên => [nhãn, giá trị mặc định]. */
function smc_tokens() {
	return array(
		'primary'      => array( __( 'Primary color', 'smart-content-seo' ), '#2563eb' ),
		'accent'       => array( __( 'Accent color', 'smart-content-seo' ), '#f59e0b' ),
		'text'         => array( __( 'Text color', 'smart-content-seo' ), '#1f2937' ),
		'bg'           => array( __( 'Page background', 'smart-content-seo' ), '#ffffff' ),
		'muted'        => array( __( 'Muted text color', 'smart-content-seo' ), 'color-mix(in srgb, var(--smc-text) 65%, var(--smc-bg))' ),
		'surface'      => array( __( 'Block background', 'smart-content-seo' ), 'color-mix(in srgb, var(--smc-text) 5%, var(--smc-bg))' ),
		'border'       => array( __( 'Border color', 'smart-content-seo' ), 'color-mix(in srgb, var(--smc-text) 14%, var(--smc-bg))' ),
		'radius'       => array( __( 'Corner radius', 'smart-content-seo' ), '12px' ),
		'font-heading' => array( __( 'Heading font', 'smart-content-seo' ), 'inherit' ),
		'font-body'    => array( __( 'Body font', 'smart-content-seo' ), 'inherit' ),
	);
}

/**
 * Các nhà cung cấp AI dùng được: mã => [ tên, ô lưu key, hằng số trong wp-config.php, ô lưu model, model gợi ý, model mặc định, nơi lấy key ].
 * Ô model nhận cả mã model khác danh sách gợi ý, để dùng được model mới ra mà không phải cập nhật plugin.
 */
function smc_providers() {
	return array(
		'claude' => array(
			'label'   => 'Claude (Anthropic)',
			'key'     => 'api_key',
			'const'   => 'SMC_API_KEY',
			'model'   => 'model',
			'models'  => array(
				'claude-opus-5-5'   => __( 'Claude Opus 5.5 (highest quality)', 'smart-content-seo' ),
				'claude-sonnet-5-5' => __( 'Claude Sonnet 5.5 (faster and cheaper)', 'smart-content-seo' ),
				'claude-haiku-5-5'  => __( 'Claude Haiku 5.5 (cheapest)', 'smart-content-seo' ),
			),
			'default' => 'claude-opus-5-5',
			'get_key' => 'https://platform.claude.com/',
			'note'    => __( 'Pay as you go; you need to buy credit first.', 'smart-content-seo' ),
		),
		'openai' => array(
			'label'   => 'ChatGPT (OpenAI)',
			'key'     => 'openai_key',
			'const'   => 'SMC_OPENAI_KEY',
			'model'   => 'model_openai',
			'models'  => array(
				'gpt-6-astra' => __( 'GPT-6 Astra (highest quality)', 'smart-content-seo' ),
				'gpt-6.1-sol' => __( 'GPT-6.1 Sol (balanced quality and price)', 'smart-content-seo' ),
				'gpt-6-luna'  => __( 'GPT-6 Luna (cheapest)', 'smart-content-seo' ),
			),
			'default' => 'gpt-6.1-sol',
			'get_key' => 'https://platform.openai.com/api-keys',
			'note'    => __( 'Pay as you go; you need to buy credit first.', 'smart-content-seo' ),
		),
		'gemini' => array(
			'label'   => 'Google Gemini',
			'key'     => 'gemini_key',
			'const'   => 'SMC_GEMINI_KEY',
			'model'   => 'model_gemini',
			'models'  => array(
				'gemini-4-argon'         => __( 'Gemini 4 Argon (highest quality, paid)', 'smart-content-seo' ),
				'gemini-3.1-pro-preview' => __( 'Gemini 3.1 Pro Preview (paid)', 'smart-content-seo' ),
				'gemini-3.8-flash'       => __( 'Gemini 3.8 Flash (free tier available)', 'smart-content-seo' ),
				'gemini-3.6-flash'       => __( 'Gemini 3.6 Flash (free tier available)', 'smart-content-seo' ),
				'gemini-3.5-flash-lite'  => __( 'Gemini 3.5 Flash-Lite (free tier available, fastest)', 'smart-content-seo' ),
			),
			'default' => 'gemini-3.8-flash',
			'get_key' => 'https://aistudio.google.com/apikey',
			'note'    => __( 'Flash models have a free tier (rate limited; on the free tier Google may use the data you send to improve its products). Pro and Argon are paid only.', 'smart-content-seo' ),
		),
	);
}

/** Nhà cung cấp AI đang dùng. */
function smc_provider() {
	$s = smc_settings();
	return isset( smc_providers()[ $s['provider'] ] ) ? $s['provider'] : 'claude';
}

/** Mã model hợp lệ (chữ, số, dấu chấm, gạch ngang...). */
function smc_clean_model( $model ) {
	$model = trim( (string) $model );
	return preg_match( '#^[a-z0-9][a-z0-9._:/-]{1,80}$#i', $model ) ? $model : '';
}

/** Model đang dùng của nhà cung cấp. */
function smc_model( $provider = '' ) {
	$p     = smc_providers()[ $provider ? $provider : smc_provider() ];
	$s     = smc_settings();
	$model = smc_clean_model( $s[ $p['model'] ] );
	return '' !== $model ? $model : $p['default'];
}

function smc_defaults() {
	$d = array(
		'api_key'    => '',
		'provider'     => 'claude',
		'model'        => 'claude-opus-5-5',
		'openai_key'   => '',
		'model_openai' => 'gpt-6.1-sol',
		'gemini_key'   => '',
		'model_gemini' => 'gemini-3.8-flash',
		'effort'     => 'medium',
		'width'      => '760',
		'style_note' => '',
		'pexels_key' => '',
	);
	foreach ( smc_tokens() as $key => $def ) {
		$d[ 'token_' . $key ] = $def[1];
	}
	return $d;
}

function smc_settings() {
	$saved = get_option( 'smc_settings', array() );
	return wp_parse_args( is_array( $saved ) ? $saved : array(), smc_defaults() );
}

/** Key của nhà cung cấp AI (mặc định: nhà cung cấp đang dùng). Khai báo trong wp-config.php thì ưu tiên. */
function smc_api_key( $provider = '' ) {
	$p = smc_providers()[ $provider ? $provider : smc_provider() ];
	if ( defined( $p['const'] ) && constant( $p['const'] ) ) {
		return (string) constant( $p['const'] );
	}
	$s = smc_settings();
	return (string) $s[ $p['key'] ];
}

/** Đã có key để gọi AI chưa (chế độ thử thì không cần key). */
function smc_ai_ready() {
	return '' !== smc_api_key() || ( defined( 'SMC_MOCK_AI' ) && SMC_MOCK_AI );
}

/** Key của Pexels (ảnh miễn phí). Rỗng thì tìm ảnh trên Openverse. */
function smc_pexels_key() {
	if ( defined( 'SMC_PEXELS_KEY' ) && SMC_PEXELS_KEY ) {
		return SMC_PEXELS_KEY;
	}
	$s = smc_settings();
	return (string) $s['pexels_key'];
}

/** Độ dài bài cho AI viết: số chữ => nhãn. */
function smc_lengths() {
	$out = array();
	foreach ( array( 1000, 1400, 1800, 2200, 2500 ) as $n ) {
		/* translators: %s: number of words, e.g. 1,800. */
		$out[ $n ] = sprintf( __( '~%s words', 'smart-content-seo' ), number_format_i18n( $n ) );
	}
	return $out;
}

/** Đếm chữ như công cụ SEO tiếng Việt: mỗi tiếng cách nhau bằng khoảng trắng là một chữ. Không tính chữ trong ảnh và chú thích ảnh. */
function smc_word_count( $html ) {
	$html  = preg_replace( '#<(figure|figcaption|script|style)\b[^>]*>.*?</\1\s*>#is', ' ', (string) $html );
	$text  = html_entity_decode( wp_strip_all_tags( preg_replace( '#<[^>]+>#', ' $0 ', $html ) ), ENT_QUOTES, 'UTF-8' );
	$words = preg_split( '/\s+/u', trim( $text ) );
	return count( preg_grep( '/[\p{L}\p{N}]/u', $words ) );
}

function smc_is_smart( $post_id ) {
	return $post_id && (bool) get_post_meta( $post_id, '_smc', true );
}

/** Bài đang hiện ra ngoài site (đã đăng hoặc riêng tư). */
function smc_is_live( $post ) {
	return in_array( get_post_status( $post ), array( 'publish', 'private' ), true );
}

/** Bài viết thường có thể chuyển sang Smart Content không. */
function smc_can_convert( $post ) {
	$post = get_post( $post );
	if ( ! $post || 'attachment' === $post->post_type || in_array( $post->post_status, array( 'trash', 'auto-draft' ), true ) ) {
		return false;
	}
	$type = get_post_type_object( $post->post_type );
	return $type && $type->public && ( post_type_supports( $post->post_type, 'editor' ) || smc_is_smart( $post->ID ) );
}

function smc_composer_url( $post_id ) {
	return add_query_arg( 'post', (int) $post_id, admin_url( 'admin.php?page=smart-content' ) );
}

/** Danh sách bài (của WordPress) lọc theo bài Smart Content. */
function smc_list_url( $post_type = 'post' ) {
	$args = array( 'smc_filter' => 'smart' );
	if ( $post_type && 'post' !== $post_type ) {
		$args['post_type'] = $post_type;
	}
	return add_query_arg( $args, admin_url( 'edit.php' ) );
}

/** Màn hình sửa bài của WordPress (tiêu đề, danh mục, ảnh đại diện...). Link "Chỉnh sửa" của bài Smart Content thì mở trình soạn Smart Content. */
function smc_wp_edit_url( $post_id ) {
	return admin_url( 'post.php?post=' . (int) $post_id . '&action=edit' );
}

/** Mở trình soạn và bắt đầu chuyển bài ngay. */
function smc_convert_url( $post_id ) {
	return add_query_arg( 'smc_go', wp_create_nonce( 'smc_go_' . (int) $post_id ), smc_composer_url( $post_id ) );
}

/** Giá trị biến CSS: bỏ các ký tự có thể thoát ra khỏi khai báo. */
function smc_clean_token( $value ) {
	return trim( preg_replace( '/[;{}<>\\\\]/', '', (string) $value ) );
}

/** Khai báo biến --smc-* cho toàn site, theo trang Cài đặt. */
function smc_tokens_css() {
	$s   = smc_settings();
	$out = '';
	foreach ( smc_tokens() as $key => $def ) {
		$val  = smc_clean_token( $s[ 'token_' . $key ] );
		$out .= '--smc-' . $key . ':' . ( '' === $val ? $def[1] : $val ) . ';';
	}
	return ':root{' . $out . '}';
}

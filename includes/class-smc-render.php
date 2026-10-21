<?php
defined( 'ABSPATH' ) || exit;

/** Hiển thị ngoài trang: nạp CSS/JS cho bài Smart Content và dựng bản xem trước của bản đang soạn. */
class SMC_Render {

	/** ID các bài đã in CSS. */
	private static $printed = array();

	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'wp_head', array( __CLASS__, 'head_css' ), 50 );
		add_filter( 'the_content', array( __CLASS__, 'preview_content' ), 0 );
		add_filter( 'the_content', array( __CLASS__, 'late_css' ), 20 );
		add_filter( 'show_admin_bar', array( __CLASS__, 'admin_bar' ) );
	}

	/** Đang xem trước bản đang soạn trong khung của trình soạn Smart Content. */
	public static function is_preview() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- chỉ đọc, đã kiểm tra quyền sửa bài.
		if ( empty( $_GET['smc_preview'] ) || ! is_singular() ) {
			return false;
		}
		$id = get_queried_object_id();
		return smc_is_smart( $id ) && current_user_can( 'edit_post', $id );
	}

	public static function admin_bar( $show ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return ( isset( $_GET['smc_preview'] ) || isset( $_GET['smc_probe'] ) ) ? false : $show;
	}

	/** Các bài Smart Content có trong trang hiện tại. */
	private static function smart_posts() {
		global $wp_query;
		$ids = array();
		foreach ( isset( $wp_query->posts ) ? (array) $wp_query->posts : array() as $p ) {
			if ( smc_is_smart( $p->ID ) ) {
				$ids[] = $p->ID;
			}
		}
		return $ids;
	}

	private static function assets() {
		wp_enqueue_style( 'smc-base', SMC_URL . 'assets/base.css', array(), SMC_VERSION );
		wp_add_inline_style( 'smc-base', smc_tokens_css() );
		wp_enqueue_script( 'smc-front', SMC_URL . 'assets/front.js', array(), SMC_VERSION, true );
		// Nhãn nút của khung xem ảnh lớn (front.js không dùng wp-i18n để trang ngoài không phải tải thêm script).
		wp_localize_script(
			'smc-front',
			'SMC_FRONT',
			array(
				'close' => __( 'Close', 'smart-content-seo' ),
				'prev'  => __( 'Previous', 'smart-content-seo' ),
				'next'  => __( 'Next', 'smart-content-seo' ),
			)
		);
	}

	public static function enqueue() {
		if ( ! self::smart_posts() ) {
			return;
		}
		self::assets();
		if ( self::is_preview() ) {
			nocache_headers();
			wp_enqueue_script( 'smc-preview', SMC_URL . 'assets/preview.js', array(), SMC_VERSION, true );
		}
	}

	private static function css_for( $id ) {
		if ( self::is_preview() && get_queried_object_id() === $id ) {
			$draft = SMC_Posts::get_draft( $id );
			return SMC_Posts::colors_css( $id ) . SMC_CSS::scope( $draft['css'], $id );
		}
		return (string) get_post_meta( $id, '_smc_css', true );
	}

	private static function style_tag( $id ) {
		self::$printed[ $id ] = true;
		$css                  = self::css_for( $id );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSS đã qua SMC_CSS::scope (bỏ </style).
		return '' === $css ? '' : '<style id="smc-css-' . (int) $id . '">' . str_ireplace( '</style', '', $css ) . "</style>\n";
	}

	public static function head_css() {
		foreach ( self::smart_posts() as $id ) {
			// Theme khối dựng nội dung trước wp_head, khi đó late_css() đã in CSS của bài rồi.
			if ( ! isset( self::$printed[ $id ] ) ) {
				echo self::style_tag( $id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
		}
	}

	/** Bài hiện ra ở nơi không nằm trong truy vấn chính (widget, khối danh sách bài...): in CSS ngay trước nội dung. */
	public static function late_css( $content ) {
		$id = get_the_ID();
		if ( ! $id || isset( self::$printed[ $id ] ) || false === strpos( $content, 'smc-p' . $id ) ) {
			return $content;
		}
		self::assets();
		return self::style_tag( $id ) . $content;
	}

	/** Khi xem trước: thay nội dung bài bằng bản đang soạn. */
	public static function preview_content( $content ) {
		if ( ! self::is_preview() || get_the_ID() !== get_queried_object_id() ) {
			return $content;
		}
		$draft = SMC_Posts::get_draft( get_the_ID() );
		return '' === $draft['html'] ? $content : SMC_Posts::wrap( get_the_ID(), $draft['html'] );
	}
}

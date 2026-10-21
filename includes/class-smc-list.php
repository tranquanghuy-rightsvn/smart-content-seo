<?php
defined( 'ABSPATH' ) || exit;

/**
 * Smart Content trong danh sách Bài viết của WordPress (không có danh sách riêng):
 * - badge "Smart Content" cạnh tiêu đề, cột "Smart Content" và bộ lọc "Smart Content" / "AI đã viết";
 * - link "Chỉnh sửa" của bài Smart Content mở thẳng trình soạn Smart Content.
 * Chuyển sang Smart Content (và chuyển về bài viết gốc) chỉ làm trong màn hình sửa bài, không làm từ danh sách.
 */
class SMC_List {

	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'filter_query' ) );
		add_filter( 'get_edit_post_link', array( __CLASS__, 'edit_link' ), 10, 3 );
		add_filter( 'display_post_states', array( __CLASS__, 'badge' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	/** Loại bài chuyển được sang Smart Content: công khai và có trình soạn thảo. */
	private static function types() {
		$out = array();
		foreach ( get_post_types( array( 'public' => true ) ) as $type ) {
			if ( 'attachment' !== $type && post_type_supports( $type, 'editor' ) ) {
				$out[] = $type;
			}
		}
		return $out;
	}

	public static function register() {
		if ( ! current_user_can( smc_cap() ) ) {
			return;
		}
		foreach ( self::types() as $type ) {
			add_filter( "manage_{$type}_posts_columns", array( __CLASS__, 'columns' ) );
			add_action( "manage_{$type}_posts_custom_column", array( __CLASS__, 'column' ), 10, 2 );
			add_filter( "views_edit-{$type}", array( __CLASS__, 'views' ) );
		}
	}

	/* ---------- Badge, cột và bộ lọc ---------- */

	/**
	 * Badge cạnh tiêu đề bài, như nhãn "Bản nháp" của WordPress. Chỉ dùng HTML ở danh sách bài;
	 * nơi khác (menu, chọn trang...) WordPress có thể in nhãn dạng chữ thường.
	 */
	public static function badge( $states, $post ) {
		if ( ! smc_is_smart( $post->ID ) ) {
			return $states;
		}
		$screen          = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$states['smc']   = $screen && 'edit' === $screen->base
			? '<span class="smc-badge" title="' . esc_attr__( 'This post is displayed with Smart Content', 'smart-content-seo' ) . '">✦ Smart Content</span>'
			: 'Smart Content';
		return $states;
	}


	public static function columns( $cols ) {
		$out = array();
		foreach ( $cols as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'title' === $key ) {
				$out['smc'] = 'Smart Content';
			}
		}
		return isset( $out['smc'] ) ? $out : $out + array( 'smc' => 'Smart Content' );
	}

	public static function column( $col, $post_id ) {
		if ( 'smc' !== $col ) {
			return;
		}
		if ( smc_is_smart( $post_id ) ) {
			$job = SMC_Jobs::active_for_post( $post_id );
			// Badge đã nằm cạnh tiêu đề: cột chỉ ghi tình trạng của thiết kế.
			if ( $job ) {
				echo '<span class="smc-col is-busy">' . esc_html__( 'AI is working…', 'smart-content-seo' ) . '</span>';
			} elseif ( '' === SMC_Posts::get_draft( $post_id )['html'] ) {
				echo '<span class="description">' . esc_html__( 'No design yet', 'smart-content-seo' ) . '</span>';
			} elseif ( smc_is_live( $post_id ) && SMC_Posts::is_dirty( $post_id ) ) {
				echo '<span class="smc-col is-warn">' . esc_html__( 'New design awaiting review', 'smart-content-seo' ) . '</span>';
			} else {
				echo '<span class="smc-col is-on">' . esc_html__( '✓ Live', 'smart-content-seo' ) . '</span>';
			}
			return;
		}
		$write = SMC_Writer::get( $post_id );
		if ( ! empty( $write['html'] ) ) {
			echo '<span class="smc-col">' . esc_html__( 'Written by AI', 'smart-content-seo' ) . '</span><br><span class="description">';
			/* translators: %s: approximate number of words in the post. */
			echo esc_html( sprintf( _n( '~%s word, not converted', '~%s words, not converted', (int) $write['words'], 'smart-content-seo' ), number_format_i18n( (int) $write['words'] ) ) );
			echo '</span>';
		} elseif ( ! empty( $write['outline'] ) ) {
			echo '<span class="description">' . esc_html__( 'Outline ready', 'smart-content-seo' ) . '</span>';
		} else {
			echo '<span aria-hidden="true">—</span>';
		}
	}

	private static function count( $type, $filter ) {
		$q = new WP_Query(
			array(
				'post_type'      => $type,
				'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private' ),
				'meta_query'     => self::meta_query( $filter ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'fields'         => 'ids',
				'posts_per_page' => 1,
			)
		);
		return (int) $q->found_posts;
	}

	private static function meta_query( $filter ) {
		if ( 'smart' === $filter ) {
			return array(
				array(
					'key'     => '_smc',
					'compare' => 'EXISTS',
				),
			);
		}
		return array(
			array(
				'key'     => '_smc_write',
				'compare' => 'EXISTS',
			),
			array(
				'key'     => '_smc',
				'compare' => 'NOT EXISTS',
			),
		);
	}

	private static function current_filter() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- chỉ lọc danh sách.
		$f = isset( $_GET['smc_filter'] ) ? sanitize_key( wp_unslash( $_GET['smc_filter'] ) ) : '';
		return in_array( $f, array( 'smart', 'ai' ), true ) ? $f : '';
	}

	public static function views( $views ) {
		$type   = get_current_screen() ? get_current_screen()->post_type : 'post';
		$active = self::current_filter();
		$labels = array(
			'smart' => 'Smart Content',
			'ai'    => __( 'Written by AI, not converted', 'smart-content-seo' ),
		);
		foreach ( $labels as $key => $label ) {
			$n = self::count( $type, $key );
			if ( ! $n && $key !== $active ) {
				continue;
			}
			$url           = add_query_arg(
				array(
					'post_type'  => $type,
					'smc_filter' => $key,
				),
				admin_url( 'edit.php' )
			);
			$views[ 'smc_' . $key ] = '<a href="' . esc_url( $url ) . '"' . ( $key === $active ? ' class="current" aria-current="page"' : '' ) . '>'
				. esc_html( $label ) . ' <span class="count">(' . (int) $n . ')</span></a>';
		}
		if ( $active && isset( $views['all'] ) ) {
			$views['all'] = str_replace( array( ' class="current"', ' aria-current="page"' ), '', $views['all'] );
		}
		return $views;
	}

	public static function filter_query( $q ) {
		$f = self::current_filter();
		if ( ! $f || ! is_admin() || ! $q->is_main_query() || 'edit' !== ( get_current_screen() ? get_current_screen()->base : '' ) ) {
			return;
		}
		$q->set( 'meta_query', self::meta_query( $f ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
	}

	/**
	 * "Chỉnh sửa" bài Smart Content (danh sách bài, thanh quản trị) mở trình soạn Smart Content.
	 * Chỉ đổi link để hiển thị: các link dạng 'url'/'raw' mà WordPress dùng để chuyển trang sau khi lưu vẫn giữ nguyên.
	 */
	public static function edit_link( $link, $post_id, $context ) {
		if ( 'display' !== $context || ! smc_is_smart( $post_id ) || ! current_user_can( smc_cap() ) ) {
			return $link;
		}
		return esc_url( smc_composer_url( $post_id ) );
	}

	public static function assets( $hook ) {
		if ( 'edit.php' === $hook ) {
			wp_enqueue_style( 'smc-write', SMC_URL . 'assets/write.css', array(), SMC_VERSION );
		}
	}
}

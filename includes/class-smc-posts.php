<?php
defined( 'ABSPATH' ) || exit;

/**
 * Lưu bài Smart Content.
 * - Bài gốc viết bằng trình soạn thảo thường được giữ trong meta _smc_source để có thể trở về.
 * - Bản đang soạn: meta _smc_draft_html / _smc_draft_css.
 * - Nội dung bài: post_content (một khối Custom HTML) + meta _smc_css.
 *   Bài chưa lên site thì nội dung bài luôn theo bản đang soạn, nên đăng và lên lịch như bài thường.
 *   Bài đang hiện trên site thì chỉ đổi khi admin bấm Cập nhật.
 */
class SMC_Posts {

	const MAX_VERSIONS = 10;

	/** Nội dung plugin sắp ghi vào post_content, theo ID bài. */
	private static $pending = array();

	public static function init() {
		add_filter( 'wp_insert_post_data', array( __CLASS__, 'guard_content' ), 99, 2 );
	}

	/**
	 * Nội dung bài Smart Content chỉ do plugin ghi. Mọi lần lưu khác (sửa nhanh, trình soạn thảo, REST)
	 * giữ nguyên nội dung cũ, nên HTML không bị trình soạn thảo hay bộ lọc KSES làm hỏng.
	 */
	public static function guard_content( $data, $postarr ) {
		$id = isset( $postarr['ID'] ) ? (int) $postarr['ID'] : 0;
		if ( ! $id || 'revision' === $data['post_type'] ) {
			return $data;
		}
		if ( isset( self::$pending[ $id ] ) ) {
			$data['post_content'] = wp_slash( self::$pending[ $id ] );
		} elseif ( smc_is_smart( $id ) ) {
			$current = get_post_field( 'post_content', $id, 'raw' );
			if ( '' !== (string) $current ) {
				$data['post_content'] = wp_slash( $current );
			}
		}
		return $data;
	}

	/**
	 * Lấy nội dung bài viết thường làm chất liệu cho AI: HTML gọn (bỏ class, style, khối bao của trình soạn thảo)
	 * và danh sách ảnh trong Thư viện mà bài dùng.
	 *
	 * @return array [ 'content' => string, 'images' => int[] ]
	 */
	public static function material( $post ) {
		$raw  = (string) $post->post_content;
		$html = has_blocks( $raw ) ? do_blocks( $raw ) : wpautop( $raw );
		$html = do_shortcode( shortcode_unautop( $html ) );
		$html = preg_replace( '#<(script|style)\b[^>]*>.*?</\1\s*>#is', '', $html );

		$ids  = array();
		$html = preg_replace_callback(
			'#<img\b[^>]*>#i',
			function ( $m ) use ( &$ids ) {
				$tag = $m[0];
				if ( ! preg_match( '/wp-image-(\d+)/', $tag, $n ) && ! preg_match( '/data-id=["\']?(\d+)/', $tag, $n ) ) {
					return $tag;
				}
				$id  = (int) $n[1];
				$src = wp_get_attachment_image_src( $id, 'large' );
				if ( ! $src ) {
					return $tag;
				}
				$ids[] = $id;
				$alt   = preg_match( '/\balt=("([^"]*)"|\'([^\']*)\')/i', $tag, $a ) ? html_entity_decode( $a[2] . ( isset( $a[3] ) ? $a[3] : '' ), ENT_QUOTES ) : '';
				if ( '' === trim( $alt ) ) {
					$alt = (string) get_post_meta( $id, '_wp_attachment_image_alt', true );
				}
				// Dùng đúng URL và kích thước sẽ gửi kèm ở danh sách ảnh, để AI ghép được ảnh với vị trí trong bài.
				return '<img src="' . esc_url( $src[0] ) . '" width="' . (int) $src[1] . '" height="' . (int) $src[2] . '" alt="' . esc_attr( $alt ) . '">';
			},
			$html
		);

		$allowed = array(
			'a'      => array( 'href' => true ),
			'img'    => array(
				'src'    => true,
				'alt'    => true,
				'width'  => true,
				'height' => true,
			),
			'th'     => array(
				'colspan' => true,
				'rowspan' => true,
			),
			'td'     => array(
				'colspan' => true,
				'rowspan' => true,
			),
			'video'  => array(
				'src'    => true,
				'poster' => true,
			),
			'source' => array( 'src' => true ),
			'iframe' => array( 'src' => true ),
		);
		foreach ( explode( ' ', 'h1 h2 h3 h4 h5 h6 p ul ol li blockquote strong em b i u s br hr figure figcaption table thead tbody tfoot tr code pre sup sub cite mark dl dt dd' ) as $tag ) {
			$allowed[ $tag ] = array();
		}
		$html = wp_kses( $html, $allowed );
		$html = trim( preg_replace( '/\n\s*\n+/', "\n", $html ) );

		return array(
			'content' => $html,
			'images'  => array_values( array_unique( $ids ) ),
		);
	}

	/** Bỏ những thứ bài không được chứa: script, style, iframe, thuộc tính on*, chú thích HTML. */
	public static function clean_html( $html ) {
		$tags = 'script|style|iframe|object|embed|link|meta|base';
		$html = preg_replace( '#<!--.*?-->#s', '', (string) $html );
		$html = preg_replace( '#<(' . $tags . ')\b[^>]*>.*?</\1\s*>#is', '', $html );
		$html = preg_replace( '#</?(' . $tags . ')\b[^>]*>#i', '', $html );
		$html = preg_replace( '#\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $html );
		$html = preg_replace( '#\b(href|src)\s*=\s*(["\']?)\s*javascript:[^"\'>\s]*#i', '$1=$2#', $html );
		return trim( $html );
	}

	public static function get_draft( $id ) {
		return array(
			'html' => (string) get_post_meta( $id, '_smc_draft_html', true ),
			'css'  => (string) get_post_meta( $id, '_smc_draft_css', true ),
		);
	}

	public static function save_draft( $id, $html, $css, $note ) {
		$html = self::clean_html( $html );
		$css  = trim( (string) $css );
		update_post_meta( $id, '_smc_draft_html', wp_slash( $html ) );
		update_post_meta( $id, '_smc_draft_css', wp_slash( $css ) );

		$versions   = self::get_versions( $id );
		$versions[] = array(
			'id'   => uniqid(),
			'time' => time(),
			'note' => $note,
			'html' => $html,
			'css'  => $css,
		);
		$versions = array_slice( $versions, -self::MAX_VERSIONS );
		update_post_meta( $id, '_smc_versions', wp_slash( $versions ) );
		self::sync( $id );
	}

	public static function get_versions( $id ) {
		$v = get_post_meta( $id, '_smc_versions', true );
		return is_array( $v ) ? $v : array();
	}

	public static function restore( $id, $version_id ) {
		foreach ( self::get_versions( $id ) as $v ) {
			if ( $v['id'] === $version_id ) {
				update_post_meta( $id, '_smc_draft_html', wp_slash( $v['html'] ) );
				update_post_meta( $id, '_smc_draft_css', wp_slash( $v['css'] ) );
				self::sync( $id );
				return true;
			}
		}
		return false;
	}

	/**
	 * Đổi một ảnh trong bản đang soạn sang ảnh khác của Thư viện, không cần AI. Giữ nguyên alt vì nó tả vai trò của ảnh trong bài.
	 * Bài gốc dùng để tạo lại thiết kế cũng đổi theo, để tạo lại không mang ảnh cũ trở lại.
	 */
	public static function swap_image( $id, $old, $att ) {
		$src = wp_get_attachment_image_src( $att, 'large' );
		if ( ! $src || '' === $old ) {
			return new WP_Error( 'smc_swap', __( 'The new image is not valid.', 'smart-content-seo' ), array( 'status' => 400 ) );
		}
		$draft = self::get_draft( $id );
		if ( false === strpos( $draft['html'], $old ) ) {
			return new WP_Error( 'smc_swap', __( 'The old image was not found in the post.', 'smart-content-seo' ), array( 'status' => 404 ) );
		}
		$new  = $src[0];
		$html = str_replace( $old, $new, $draft['html'] );
		// Ảnh mới có thể khác tỉ lệ: sửa width/height của các thẻ <img> dùng ảnh mới cho đúng.
		$html = preg_replace_callback(
			'#<img\b[^>]*\bsrc\s*=\s*["\']' . preg_quote( $new, '#' ) . '["\'][^>]*>#i',
			function ( $m ) use ( $src ) {
				$tag = preg_replace( '/\s(width|height|srcset|sizes)\s*=\s*("[^"]*"|\'[^\']*\'|\S+)/i', '', $m[0] );
				return preg_replace( '#\s*/?>$#', ' width="' . (int) $src[1] . '" height="' . (int) $src[2] . '">', $tag );
			},
			$html
		);
		self::save_draft( $id, $html, str_replace( $old, $new, $draft['css'] ), __( 'Image replaced', 'smart-content-seo' ) );

		$brief = get_post_meta( $id, '_smc_brief', true );
		if ( is_array( $brief ) ) {
			$images = isset( $brief['images'] ) ? array_map( 'intval', (array) $brief['images'] ) : array();
			$at     = false;
			foreach ( $images as $i => $img ) {
				$s = wp_get_attachment_image_src( $img, 'large' );
				if ( $s && $s[0] === $old ) {
					$at = $i;
				}
			}
			if ( false !== $at ) {
				if ( (int) get_post_thumbnail_id( $id ) === $images[ $at ] ) {
					set_post_thumbnail( $id, $att );
				}
				$images[ $at ] = (int) $att;
			} else {
				$images[] = (int) $att;
			}
			$brief['images']  = array_values( array_unique( $images ) );
			$brief['content'] = str_replace( $old, $new, (string) $brief['content'] );
			update_post_meta( $id, '_smc_brief', wp_slash( $brief ) );
		}
		return true;
	}

	/** Màu chủ đạo và màu phụ người dùng chọn riêng cho bài; rỗng nghĩa là dùng màu của site. */
	public static function get_colors( $id ) {
		$c   = get_post_meta( $id, '_smc_colors', true );
		$out = array();
		foreach ( array( 'primary', 'accent' ) as $k ) {
			$out[ $k ] = ( is_array( $c ) && isset( $c[ $k ] ) && preg_match( '/^#[0-9a-f]{6}$/i', $c[ $k ] ) ) ? strtolower( $c[ $k ] ) : '';
		}
		return $out;
	}

	public static function set_colors( $id, $primary, $accent ) {
		update_post_meta(
			$id,
			'_smc_colors',
			array(
				'primary' => (string) $primary,
				'accent'  => (string) $accent,
			)
		);
		self::sync( $id );
	}

	/** Khai báo biến màu riêng của bài, đè lên biến của site trong phạm vi bài đó. */
	public static function colors_css( $id ) {
		$c    = self::get_colors( $id );
		$decl = ( $c['primary'] ? '--smc-primary:' . $c['primary'] . ';' : '' ) . ( $c['accent'] ? '--smc-accent:' . $c['accent'] . ';' : '' );
		return '' === $decl ? '' : SMC_CSS::scope_class( $id ) . '{' . $decl . '}';
	}

	public static function wrap( $id, $html ) {
		return "<!-- wp:html -->\n<div class=\"smc-root smc-p" . (int) $id . "\">\n" . $html . "\n</div>\n<!-- /wp:html -->";
	}

	public static function hash( $id, $draft ) {
		return md5( $draft['html'] . "\n" . $draft['css'] . "\n" . implode( ',', self::get_colors( $id ) ) );
	}

	/** Bản đang soạn có khác bản đã đăng không. */
	public static function is_dirty( $id ) {
		$draft = self::get_draft( $id );
		return '' !== $draft['html'] && self::hash( $id, $draft ) !== get_post_meta( $id, '_smc_pub_hash', true );
	}

	/** Bài chưa lên site: nội dung bài luôn theo bản đang soạn. */
	private static function sync( $id ) {
		if ( smc_is_smart( $id ) && ! smc_is_live( $id ) ) {
			self::apply( $id );
		}
	}

	/** Ghi bản đang soạn vào nội dung bài, kèm các thay đổi khác của bài (trạng thái, ngày đăng). */
	private static function apply( $id, $args = array() ) {
		$draft = self::get_draft( $id );
		if ( '' === $draft['html'] ) {
			return new WP_Error( 'smc_empty', __( 'The post has no content yet.', 'smart-content-seo' ), array( 'status' => 400 ) );
		}
		self::$pending[ $id ] = self::wrap( $id, $draft['html'] );
		$result               = wp_update_post(
			array_merge(
				array(
					'ID'           => $id,
					'post_content' => '',
				),
				$args
			),
			true
		);
		unset( self::$pending[ $id ] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		update_post_meta( $id, '_smc_css', wp_slash( self::colors_css( $id ) . SMC_CSS::scope( $draft['css'], $id ) ) );
		update_post_meta( $id, '_smc_pub_hash', self::hash( $id, $draft ) );
		return true;
	}

	/**
	 * Đăng bài, lên lịch, hoặc cập nhật bài đang hiện trên site.
	 *
	 * @param string $date Thời điểm đăng theo giờ của site, dạng 2026-10-20T08:00; rỗng là đăng ngay.
	 */
	public static function publish( $id, $date = '' ) {
		$post = get_post( $id );
		$args = array();
		if ( ! smc_is_live( $post ) ) {
			$args['post_status'] = 'publish';
			if ( '' !== $date ) {
				$when = date_create_immutable_from_format( 'Y-m-d\TH:i', substr( $date, 0, 16 ), wp_timezone() );
				if ( ! $when || $when->getTimestamp() <= time() ) {
					return new WP_Error( 'smc_date', __( 'The scheduled time must be in the future.', 'smart-content-seo' ), array( 'status' => 400 ) );
				}
				// Ngày ở tương lai thì WordPress tự chuyển bài sang trạng thái "đã lên lịch" và đăng khi đến giờ.
				$args['post_date']     = $when->format( 'Y-m-d H:i:00' );
				$args['post_date_gmt'] = get_gmt_from_date( $args['post_date'] );
				$args['edit_date']     = true;
			} elseif ( 'future' === $post->post_status || strtotime( $post->post_date_gmt . ' UTC' ) > time() ) {
				$args['post_date']     = current_time( 'mysql' );
				$args['post_date_gmt'] = current_time( 'mysql', true );
				$args['edit_date']     = true;
			}
		}
		return self::apply( $id, $args );
	}

	/** Bài bắt đầu là Smart Content: giữ lại nội dung gốc để có thể trở về. */
	public static function mark_smart( $id, $source ) {
		if ( ! smc_is_smart( $id ) ) {
			update_post_meta( $id, '_smc_source', wp_slash( $source ) );
			update_post_meta( $id, '_smc', 1 );
		}
	}

	public static function can_revert( $id ) {
		return smc_is_smart( $id ) && metadata_exists( 'post', $id, '_smc_source' );
	}

	/** Bỏ Smart Content: trả nội dung bài về bản viết bằng trình soạn thảo thường. */
	public static function revert( $id ) {
		if ( ! self::can_revert( $id ) ) {
			return new WP_Error( 'smc_no_source', __( 'This post has no original version to revert to.', 'smart-content-seo' ), array( 'status' => 400 ) );
		}
		self::$pending[ $id ] = (string) get_post_meta( $id, '_smc_source', true );
		$result               = wp_update_post(
			array(
				'ID'           => $id,
				'post_content' => '',
			),
			true
		);
		unset( self::$pending[ $id ] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		foreach ( array( '_smc', '_smc_source', '_smc_css', '_smc_draft_html', '_smc_draft_css', '_smc_versions', '_smc_pub_hash', '_smc_job' ) as $key ) {
			delete_post_meta( $id, $key );
		}
		return true;
	}
}

<?php
defined( 'ABSPATH' ) || exit;

/**
 * AI viết bài viết thường (chưa phải Smart Content) từ tiêu đề, mô tả, từ khoá và độ dài mong muốn:
 * 1. Lên outline: chọn số mục theo độ dài, ước số chữ cho từng mục (không chia đều), từ khoá tìm ảnh cho từng mục.
 * 2. Viết bài: tìm và tải ảnh miễn phí cho từng mục, rồi viết theo outline và đặt ảnh vào đúng chỗ.
 * Bài viết xong được chèn vào trình soạn thảo để người viết xem lại; chuyển sang Smart Content là bước riêng.
 *
 * Ảnh: ưu tiên ảnh có sẵn trong Thư viện ảnh khi AI thấy ảnh hợp với mục (mục 'library' của outline), phần còn thiếu mới tìm ảnh miễn phí.
 *
 * Meta _smc_write của bài: [ description, keywords, length, use_library, angle, outline, html, words, queries ].
 */
class SMC_Writer {

	const MAX_IMAGES = 18;

	/** Số ảnh có sẵn trong Thư viện tối đa đưa cho AI xem khi lên outline. */
	const MAX_CANDIDATES = 30;

	/** Hướng dẫn bố cục theo độ dài bài. */
	private static function plan_for( $length ) {
		$plans = array(
			1000 => array( '3-4', 'Do not use H3 subheadings.', 'No FAQ section.', 7, '80-110', '60-90' ),
			1400 => array( '4-5', 'Use H3 only where a section clearly has 2-4 parallel parts.', 'An FAQ section of 3 questions is optional; include it only if readers commonly have follow-up questions.', 9, '100-130', '70-100' ),
			1800 => array( '5-6', 'Use H3 subheadings in the one or two longest sections.', 'Include an FAQ section of 3-4 questions if the topic has common follow-up questions.', 11, '110-150', '80-120' ),
			2200 => array( '6-7', 'Use H3 subheadings in the two or three longest sections.', 'Include an FAQ section of 4-5 questions.', 13, '130-170', '90-130' ),
			2500 => array( '7-8', 'Use H3 subheadings in the two or three longest sections.', 'Include an FAQ section of 4-6 questions.', 15, '140-180', '100-140' ),
		);
		$key = 1000;
		foreach ( array_keys( $plans ) as $k ) {
			if ( $length >= $k ) {
				$key = $k;
			}
		}
		$p = $plans[ $key ];
		return array(
			'sections' => $p[0],
			'h3'       => $p[1],
			'faq'      => $p[2],
			'images'   => min( self::MAX_IMAGES, $p[3] ),
			'intro'    => $p[4],
			'outro'    => $p[5],
		);
	}

	public static function get( $post_id ) {
		$w = get_post_meta( $post_id, '_smc_write', true );
		return is_array( $w ) ? $w : array();
	}

	private static function save( $post_id, $patch ) {
		update_post_meta( $post_id, '_smc_write', wp_slash( array_merge( self::get( $post_id ), $patch ) ) );
	}

	/** Yêu cầu viết bài từ trình duyệt, đã làm sạch. */
	public static function clean_brief( $req ) {
		$lengths = smc_lengths();
		$length  = (int) $req['length'];
		return array(
			'title'       => sanitize_text_field( (string) $req['title'] ),
			'description' => sanitize_textarea_field( (string) $req['description'] ),
			'keywords'    => sanitize_text_field( (string) $req['keywords'] ),
			'length'      => isset( $lengths[ $length ] ) ? $length : 1400,
			'use_library' => null === $req['use_library'] ? true : rest_sanitize_boolean( $req['use_library'] ),
		);
	}

	private static function brief_text( $b ) {
		return 'Title: ' . $b['title'] . "\n"
			. 'What the article must deliver (from the site owner): ' . ( '' !== trim( $b['description'] ) ? $b['description'] : '(not given; infer from the title)' ) . "\n"
			. 'SEO keywords (the first is the main keyword): ' . ( '' !== trim( $b['keywords'] ) ? $b['keywords'] : '(not given; infer from the title)' ) . "\n"
			. 'Target length: about ' . $b['length'] . " words\n";
	}

	/* ---------- Outline ---------- */

	public static function outline( $post_id, $b, $tick ) {
		$plan       = self::plan_for( $b['length'] );
		$candidates = $b['use_library'] ? self::library_candidates( $post_id, $b ) : array();
		if ( defined( 'SMC_MOCK_AI' ) && SMC_MOCK_AI ) {
			return self::with_thumbs( self::normalize( self::mock_outline( $b, $plan, $candidates ), $b['length'] ) );
		}
		$system = 'You are a senior content strategist planning an article for a business\'s WordPress site. The article is read by real customers and must also rank in Google for its keywords. Write the outline in the language of the title.

## How to plan
- Work out the search intent behind the title and keywords, and what a reader needs in order to act on it. Put that in `angle`: one or two sentences on who reads this and what the article gives them.
- An article is made of an intro, body sections, optionally an FAQ, and a conclusion, in that order. Each is one entry in `sections`, with `kind` set to intro, body, faq or conclusion.
- The intro has no heading in the article; give it a short label as its `heading`, such as "Introduction" in the title\'s language. The conclusion and FAQ get real headings.
- Headings are specific to this topic and tell the reader what they get ("What goes into the cost of building a 2-storey, 80 m² house", not "Costs"). Work the main keyword or a close variant naturally into the intro\'s points and into two or three headings, never forced.
- `points`: the 2-5 things that section covers, as short phrases. `subheadings`: the H3 titles inside the section, if any; for the FAQ, the questions.

## Word budget
The total of `words` over all sections equals the target length. Budgets are not equal: give the most words to the sections that answer the main intent (how, how much, which to choose, step by step) and fewer to background, definitions and context. A section that compares options or explains a process needs more room than one that lists benefits. Count words as space-separated words (for Vietnamese, each space-separated syllable is one word, as Vietnamese SEO tools count).

## Images
The owner wants as many relevant photos as possible: about {images} in total.
{library}
In each section\'s `images`, list one English stock-photo search phrase per further photo the section needs (2-5 words, a concrete photographable scene such as "modern two storey house facade", never text, logos, brand names or abstract ideas). Put more photos in sections that describe things a reader would want to see; the intro gets at most one, the FAQ none, the conclusion at most one. Vary the phrases so the photos differ.';
		$library = $candidates
			? "Photos from the site's own media library are shown in the user message, each labelled with its ID. They are the business's own pictures (its projects, products, team, premises), so they are preferred over stock photos. Put a library photo's ID in a section's `library` when the photo clearly shows what that section is about; judge by what you see in the photo, helped by its title. Use each at most once and leave out any that do not fit: a wrong photo is worse than a stock one. Library photos count toward the total."
			: 'There are no library photos for this article: `library` is always an empty list.';
		$system  = str_replace( array( '{images}', '{library}' ), array( (string) $plan['images'], $library ), $system );
		$text   = self::brief_text( $b )
			. "\nStructure for this length:\n"
			. '- ' . $plan['sections'] . " body sections.\n"
			. '- ' . $plan['h3'] . "\n"
			. '- ' . $plan['faq'] . "\n"
			. '- Intro about ' . $plan['intro'] . ' words, conclusion about ' . $plan['outro'] . ' words, FAQ answers 40-70 words each, the rest to the body sections.';

		$content = array();
		foreach ( $candidates as $c ) {
			$content[] = array(
				'type' => 'text',
				'text' => 'Library photo ID ' . $c['id'] . ': ' . $c['label'],
			);
			if ( '' !== $c['data'] ) {
				$content[] = array(
					'type'   => 'image',
					'source' => array(
						'type'       => 'base64',
						'media_type' => $c['mime'],
						'data'       => $c['data'],
					),
				);
			}
		}
		$content[] = array(
			'type' => 'text',
			'text' => $text,
		);

		$format = array(
			'type'   => 'json_schema',
			'schema' => array(
				'type'                 => 'object',
				'additionalProperties' => false,
				'required'             => array( 'angle', 'sections' ),
				'properties'           => array(
					'angle'    => array( 'type' => 'string' ),
					'sections' => array(
						'type'  => 'array',
						'items' => array(
							'type'                 => 'object',
							'additionalProperties' => false,
							'required'             => array( 'kind', 'heading', 'words', 'points', 'subheadings', 'library', 'images' ),
							'properties'           => array(
								'kind'        => array(
									'type' => 'string',
									'enum' => array( 'intro', 'body', 'faq', 'conclusion' ),
								),
								'heading'     => array( 'type' => 'string' ),
								'words'       => array( 'type' => 'integer' ),
								'points'      => array(
									'type'  => 'array',
									'items' => array( 'type' => 'string' ),
								),
								'subheadings' => array(
									'type'  => 'array',
									'items' => array( 'type' => 'string' ),
								),
								'library'     => array(
									'type'  => 'array',
									'items' => array( 'type' => 'integer' ),
								),
								'images'      => array(
									'type'  => 'array',
									'items' => array( 'type' => 'string' ),
								),
							),
						),
					),
				),
			),
		);
		$res = SMC_AI::call( $system, $content, array( 'format' => $format ), $tick );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$json = json_decode( $res['text'], true );
		if ( ! is_array( $json ) || empty( $json['sections'] ) ) {
			return new WP_Error( 'smc_format', __( 'The AI returned an invalid outline. Please try again.', 'smart-content-seo' ) );
		}
		// AI chỉ được chọn trong số ảnh đã đưa cho nó xem.
		$allowed = wp_list_pluck( $candidates, 'id' );
		foreach ( $json['sections'] as &$sec ) {
			$sec['library'] = isset( $sec['library'] ) ? array_values( array_intersect( array_map( 'intval', (array) $sec['library'] ), $allowed ) ) : array();
		}
		unset( $sec );
		return self::with_thumbs( self::normalize( $json, $b['length'] ) );
	}

	/**
	 * Ảnh có sẵn trong Thư viện có thể hợp với bài: chấm điểm theo chữ trùng giữa tiêu đề và từ khoá của bài với
	 * tiêu đề, alt, chú thích, tên file của ảnh và tiêu đề bài mà ảnh đang gắn vào. AI xem ảnh và quyết định dùng hay không.
	 *
	 * @return array [ id, label, data, mime ]
	 */
	private static function library_candidates( $post_id, $b ) {
		$norm  = function ( $t ) {
			return trim( preg_replace( '/[^a-z0-9]+/', ' ', strtolower( remove_accents( (string) $t ) ) ) );
		};
		$stop  = array_flip( explode( ' ', 'va cua cho cac nhung la co khong voi trong mot nhu the nao gi de ve tu den khi duoc nen bao nhieu hay tai o the the and for with how what your from that this are you was were has have had not but all can will its into about when which who why our out more most also than then them they their there these those over under any each other such only own same just very a an of to in on at by as is it or be do does' ) );
		$words = array_values(
			array_filter(
				explode( ' ', $norm( $b['title'] . ' ' . str_replace( ',', ' ', $b['keywords'] ) ) ),
				function ( $w ) use ( $stop ) {
					return strlen( $w ) > 1 && ! isset( $stop[ $w ] );
				}
			)
		);
		if ( ! $words ) {
			return array();
		}
		$pairs = array();
		for ( $i = 0; $i < count( $words ) - 1; $i++ ) {
			$pairs[ $words[ $i ] . ' ' . $words[ $i + 1 ] ] = true;
		}
		$words = array_flip( $words );

		// Ảnh mới nhất, cộng ảnh tìm được theo từng từ khoá (ảnh cũ hơn nhưng liên quan).
		$base  = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'post_mime_type' => array( 'image/jpeg', 'image/png', 'image/webp', 'image/gif' ),
			'fields'         => 'ids',
			'no_found_rows'  => true,
		);
		$ids   = get_posts( $base + array( 'posts_per_page' => 300 ) );
		$terms = array_slice( array_filter( array_map( 'trim', explode( ',', $b['keywords'] ) ) ), 0, 3 );
		foreach ( $terms as $term ) {
			$ids = array_merge( $ids, get_posts( $base + array( 'posts_per_page' => 50, 's' => $term ) ) );
		}

		$scored = array();
		foreach ( array_unique( $ids ) as $id ) {
			$att = get_post( $id );
			if ( ! $att ) {
				continue;
			}
			$parent = $att->post_parent ? get_the_title( $att->post_parent ) : '';
			$alt    = (string) get_post_meta( $id, '_wp_attachment_image_alt', true );
			$file   = pathinfo( (string) get_post_meta( $id, '_wp_attached_file', true ), PATHINFO_FILENAME );
			$text   = ' ' . $norm( $att->post_title . ' ' . $alt . ' ' . $att->post_excerpt . ' ' . $file . ' ' . $parent ) . ' ';
			$score  = 0;
			foreach ( array_keys( $pairs ) as $pair ) {
				$score += false !== strpos( $text, ' ' . $pair . ' ' ) ? 3 : 0;
			}
			foreach ( array_unique( explode( ' ', trim( $text ) ) ) as $w ) {
				$score += isset( $words[ $w ] ) ? 1 : 0;
			}
			// Ảnh người viết đã tải lên cho chính bài này.
			$score += (int) $att->post_parent === (int) $post_id ? 5 : 0;
			if ( $score >= 2 ) {
				$scored[ $id ] = array( $score, $att->post_title, $alt, $parent );
			}
		}
		uasort(
			$scored,
			function ( $x, $y ) {
				return $y[0] - $x[0];
			}
		);

		$out = array();
		foreach ( array_slice( $scored, 0, self::MAX_CANDIDATES, true ) as $id => $info ) {
			$d     = SMC_AI::describe_images( array( $id ) );
			$label = 'title="' . $info[1] . '"' . ( '' !== $info[2] ? ' alt="' . $info[2] . '"' : '' ) . ( '' !== $info[3] ? ' used in post "' . $info[3] . '"' : '' );
			$out[] = array(
				'id'    => (int) $id,
				'label' => $label,
				'data'  => $d ? $d[0]['data'] : '',
				'mime'  => $d ? $d[0]['mime'] : '',
			);
		}
		return $out;
	}

	/** Ảnh thu nhỏ của các ảnh Thư viện trong outline, để trình duyệt hiện ra. */
	public static function thumbs( $sections ) {
		$out = array();
		foreach ( (array) $sections as $s ) {
			foreach ( isset( $s['library'] ) ? (array) $s['library'] : array() as $id ) {
				$src = wp_get_attachment_image_src( $id, 'medium' );
				if ( $src ) {
					$out[ $id ] = $src[0];
				}
			}
		}
		return $out;
	}

	private static function with_thumbs( $o ) {
		$o['thumbs'] = (object) self::thumbs( $o['sections'] );
		return $o;
	}

	/** Làm sạch outline; $target > 0 thì chia lại số chữ cho tổng đúng bằng độ dài mong muốn, giữ tỉ lệ giữa các mục. */
	public static function normalize( $o, $target = 0 ) {
		$kinds    = array( 'intro', 'body', 'faq', 'conclusion' );
		$sections = array();
		$images   = 0;
		$seen     = array();
		foreach ( isset( $o['sections'] ) ? (array) $o['sections'] : array() as $s ) {
			if ( ! is_array( $s ) ) {
				continue;
			}
			$heading = isset( $s['heading'] ) ? sanitize_text_field( (string) $s['heading'] ) : '';
			$kind    = isset( $s['kind'] ) && in_array( $s['kind'], $kinds, true ) ? $s['kind'] : 'body';
			if ( '' === $heading && 'intro' !== $kind ) {
				continue;
			}
			$list = function ( $key, $max ) use ( $s ) {
				$v = isset( $s[ $key ] ) ? (array) $s[ $key ] : array();
				return array_slice( array_values( array_filter( array_map( 'sanitize_text_field', array_map( 'strval', $v ) ) ) ), 0, $max );
			};
			$library = array();
			foreach ( isset( $s['library'] ) ? (array) $s['library'] : array() as $lib ) {
				$lib = (int) $lib;
				if ( $lib && ! isset( $seen[ $lib ] ) && $images < self::MAX_IMAGES && wp_attachment_is_image( $lib ) ) {
					$seen[ $lib ] = true;
					$library[]    = $lib;
					++$images;
				}
			}
			$imgs       = array_slice( $list( 'images', 4 ), 0, max( 0, self::MAX_IMAGES - $images ) );
			$images    += count( $imgs );
			$words = max( 20, isset( $s['words'] ) ? (int) $s['words'] : 100 );
			// Outline do AI lập: mỗi câu hỏi FAQ cần chỗ cho cả câu hỏi và câu trả lời, nếu không AI viết vượt xa ngân sách.
			if ( $target > 0 && 'faq' === $kind ) {
				$words = max( $words, 55 * count( $list( 'subheadings', 8 ) ) );
			}
			$sections[] = array(
				'kind'        => $kind,
				'heading'     => '' !== $heading ? $heading : __( 'Introduction', 'smart-content-seo' ),
				'words'       => $words,
				'points'      => $list( 'points', 8 ),
				'subheadings' => $list( 'subheadings', 8 ),
				'library'     => $library,
				'images'      => $imgs,
			);
		}
		if ( $target > 0 && $sections ) {
			$total = array_sum( wp_list_pluck( $sections, 'words' ) );
			$big   = 0;
			$sum   = 0;
			foreach ( $sections as $i => $s ) {
				$sections[ $i ]['words'] = max( 30, (int) round( $s['words'] * $target / $total / 10 ) * 10 );
				$sum                    += $sections[ $i ]['words'];
				if ( $sections[ $i ]['words'] > $sections[ $big ]['words'] ) {
					$big = $i;
				}
			}
			// Phần lệch do làm tròn dồn vào mục dài nhất.
			$sections[ $big ]['words'] += $target - $sum;
		}
		return array(
			'angle'    => isset( $o['angle'] ) ? sanitize_textarea_field( (string) $o['angle'] ) : '',
			'sections' => $sections,
		);
	}

	public static function save_outline( $post_id, $b, $o ) {
		self::save(
			$post_id,
			array(
				'description' => $b['description'],
				'keywords'    => $b['keywords'],
				'length'      => $b['length'],
				'use_library' => $b['use_library'],
				'angle'       => $o['angle'],
				'outline'     => $o['sections'],
			)
		);
	}

	/* ---------- Viết bài ---------- */

	/**
	 * @param array    $b     Yêu cầu viết bài kèm 'angle' và 'outline' (danh sách mục).
	 * @param callable $stage fn( string $việc_đang_làm ).
	 * @return array|WP_Error [ html, words, images, featured, queries ]
	 */
	public static function write( $post_id, $b, $tick, $stage ) {
		$sections = $b['outline'];

		// 1. Ảnh: tìm và tải trước để AI nhìn thấy ảnh khi viết, rồi đặt ảnh và viết chú thích cho đúng.
		$want = array();
		foreach ( $sections as $i => $s ) {
			foreach ( $s['images'] as $q ) {
				$want[] = array( $i, $q );
			}
		}
		$images = array();
		$used   = array();
		foreach ( $sections as $i => $s ) {
			foreach ( $s['library'] as $lib ) {
				$images[ count( $images ) + 1 ] = array(
					'section' => $i,
					'id'      => (int) $lib,
					'query'   => '',
				);
			}
		}
		foreach ( $want as $k => $w ) {
			/* translators: 1: current photo number, 2: total number of photos. */
			call_user_func( $stage, sprintf( __( 'Finding and downloading free stock photos (%1$d/%2$d)…', 'smart-content-seo' ), $k + 1, count( $want ) ) );
			$att = SMC_Stock::find( $w[1], $post_id, $used );
			if ( $att ) {
				$images[ count( $images ) + 1 ] = array(
					'section' => $w[0],
					'id'      => $att,
					'query'   => $w[1],
				);
			}
		}

		call_user_func( $stage, __( 'The AI is writing the post…', 'smart-content-seo' ) );
		if ( defined( 'SMC_MOCK_AI' ) && SMC_MOCK_AI ) {
			$raw = self::mock_write( $sections, $images );
		} else {
			$raw = self::ai_write( $b, $sections, $images, $tick );
			if ( is_wp_error( $raw ) ) {
				return $raw;
			}
		}

		// 2. Đổi chỗ giữ ảnh <img data-smc-img="N"> thành ảnh thật trong Thư viện, như ảnh chèn bằng trình soạn thảo.
		$html  = preg_replace( '#<p>\s*(<img\b[^>]*\bdata-smc-img\b[^>]*>)\s*</p>#i', '$1', $raw );
		$html  = preg_replace( '#<img\b(?![^>]*\bdata-smc-img\b)[^>]*>#i', '', $html );
		$alts  = array();
		$first = 0;
		$html  = preg_replace_callback(
			'#<img\b[^>]*\bdata-smc-img\s*=\s*["\']?(\d+)[^>]*>#i',
			function ( $m ) use ( $images, &$alts, &$first ) {
				$n = (int) $m[1];
				if ( ! isset( $images[ $n ] ) || isset( $alts[ $n ] ) ) {
					return '';
				}
				$id  = $images[ $n ]['id'];
				$src = wp_get_attachment_image_src( $id, 'large' );
				if ( ! $src ) {
					return '';
				}
				$alt        = preg_match( '/\balt\s*=\s*"([^"]*)"/i', $m[0], $a ) ? sanitize_text_field( html_entity_decode( $a[1], ENT_QUOTES ) ) : '';
				$alts[ $n ] = $alt;
				$first      = $first ? $first : $id;
				return "\n<figure class=\"wp-block-image size-large\"><img src=\"" . esc_url( $src[0] ) . '" alt="' . esc_attr( $alt ) . '" class="wp-image-' . (int) $id . '" width="' . (int) $src[1] . '" height="' . (int) $src[2] . "\"></figure>\n";
			},
			$html
		);
		$html = wp_kses_post( $html );
		$html = preg_replace( '#(</(p|h2|h3|h4|ul|ol|table|blockquote|figure)>)\s*#i', "$1\n\n", $html );
		$html = trim( preg_replace( "/\n{3,}/", "\n\n", $html ) );
		if ( '' === trim( wp_strip_all_tags( $html ) ) ) {
			return new WP_Error( 'smc_format', __( 'The AI returned an empty post. Please try again.', 'smart-content-seo' ) );
		}

		// Ảnh vừa tìm từ nguồn ngoài có mô tả tiếng Anh: thay bằng mô tả AI viết theo ngữ cảnh bài.
		// Ảnh có sẵn trong Thư viện giữ nguyên mô tả vì có thể đang dùng ở bài khác; alt cho bài này nằm trong thẻ <img>.
		$queries = array();
		foreach ( $alts as $n => $alt ) {
			$id = $images[ $n ]['id'];
			if ( '' === $images[ $n ]['query'] ) {
				continue;
			}
			$queries[ $id ] = $images[ $n ]['query'];
			if ( '' !== $alt && get_post_meta( $id, '_smc_stock', true ) ) {
				update_post_meta( $id, '_wp_attachment_image_alt', $alt );
			}
		}

		$words = smc_word_count( $html );
		self::save(
			$post_id,
			array(
				'html'    => $html,
				'words'   => $words,
				'queries' => $queries,
			)
		);
		return array(
			'html'     => $html,
			'words'    => $words,
			'images'   => count( $alts ),
			'featured' => $first,
			'queries'  => $queries,
		);
	}

	private static function ai_write( $b, $sections, $images, $tick ) {
		$system = 'You are an experienced writer for a business\'s WordPress site. You write articles that customers find useful and trust, and that rank in Google for their keywords. Write in the language of the title.

## Follow the outline
- Write every section of the outline in order. Keep its headings (you may polish the wording) and cover its points; use its subheadings as H3s.
- The intro has no heading: it is the paragraphs right under the title. Every other section starts with an `<h2>`. FAQ questions are `<h3>`, each followed by a short answer.
- Word budgets: each section\'s length stays inside the range given for it, headings included. The budgets were set on purpose: a short intro or conclusion stays short, and the main sections get their full length instead of being cut early. If a section\'s points do not fill its budget, go deeper with concrete detail, examples or steps; if they overflow it, tighten. Keep a running count as you write. Count words as space-separated words (for Vietnamese, each space-separated syllable is one word, as Vietnamese SEO tools count). Spend words on substance, never on filler such as "In this article, we will explore..." or repeating the heading.

## Writing
- Lead with what the reader came for. Short paragraphs of 2-4 sentences. Use a list where the content is a list, numbered steps for a process, a table for a comparison, and bold for a few key phrases only.
- Keywords: the main keyword appears naturally in the first 100 words and in a few headings; the others where they fit. Never stuff keywords.
- Facts the owner gave in the description (prices, services, areas, experience) may be used as given. Beyond those, do not invent specific facts: no made-up statistics, prices, dates, studies, quotes, awards, addresses or phone numbers. Where a reader needs a number you cannot be sure of, explain what it depends on, or give a range clearly framed as typical and approximate.
- The conclusion sums up what to do next and may invite the reader to get in touch, without inventing contact details.

## Images
Each supplied photo is numbered and assigned to a section. Place every photo exactly once, in its section, on its own line between blocks, right after the paragraph it illustrates, as `<img data-smc-img="N" alt="...">`. Write the alt in the article\'s language: what the photo shows, tied to the topic, under 125 characters. Never put a photo before the first paragraph of the intro and never add other images.

## Output
Plain HTML using only h2, h3, p, ul, ol, li, strong, em, table, thead, tbody, tr, th, td, blockquote and the image placeholders. No classes, styles, links or wrappers. Return exactly:
<smc-article>
...
</smc-article>';

		$content = array();
		foreach ( $images as $n => $img ) {
			$d         = SMC_AI::describe_images( array( $img['id'] ) );
			$content[] = array(
				'type' => 'text',
				'text' => 'Photo #' . $n . ' (for section ' . ( $img['section'] + 1 ) . ': ' . $sections[ $img['section'] ]['heading'] . ')',
			);
			if ( $d && $d[0]['data'] ) {
				$content[] = array(
					'type'   => 'image',
					'source' => array(
						'type'       => 'base64',
						'media_type' => $d[0]['mime'],
						'data'       => $d[0]['data'],
					),
				);
			}
		}

		$total = array_sum( wp_list_pluck( $sections, 'words' ) );
		$text  = self::brief_text( array( 'length' => $total ) + $b );
		if ( ! empty( $b['angle'] ) ) {
			$text .= 'Angle: ' . $b['angle'] . "\n";
		}
		$text .= "\n<outline>\n";
		$names = array(
			'intro'      => 'intro, no heading',
			'body'       => 'body',
			'faq'        => 'FAQ',
			'conclusion' => 'conclusion',
		);
		foreach ( $sections as $i => $s ) {
			$text .= ( $i + 1 ) . '. [' . $names[ $s['kind'] ] . '] ' . ( 'intro' === $s['kind'] ? '' : $s['heading'] . ' ' )
				. '(' . $s['words'] . ' words: write between ' . (int) round( $s['words'] * 0.9 ) . ' and ' . (int) round( $s['words'] * 1.1 ) . ")\n";
			if ( $s['points'] ) {
				$text .= '   Covers: ' . implode( '; ', $s['points'] ) . "\n";
			}
			if ( $s['subheadings'] ) {
				$text .= '   ' . ( 'faq' === $s['kind'] ? 'Questions' : 'H3' ) . ': ' . implode( ' | ', $s['subheadings'] ) . "\n";
			}
			$nums = array();
			foreach ( $images as $n => $img ) {
				if ( $img['section'] === $i ) {
					$nums[] = '#' . $n;
				}
			}
			if ( $nums ) {
				$text .= '   Photos: ' . implode( ', ', $nums ) . "\n";
			}
		}
		$text     .= '</outline>';
		$content[] = array(
			'type' => 'text',
			'text' => $text,
		);

		$res = SMC_AI::call( $system, $content, array(), $tick );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$html = SMC_AI::between( $res['text'], 'smc-article' );
		return '' !== $html ? $html : new WP_Error( 'smc_format', __( 'The AI returned an invalid format. Please try again.', 'smart-content-seo' ) );
	}

	/* ---------- Chế độ thử (không gọi AI) ---------- */

	private static function mock_outline( $b, $plan, $candidates ) {
		$n        = (int) $plan['sections'];
		$sections = array(
			array(
				'kind'    => 'intro',
				'heading' => 'Introduction',
				'words'   => 100,
				'points'  => array( 'The reader\'s problem', 'What this post offers' ),
				'images'  => array( 'modern house exterior' ),
			),
		);
		$queries  = array( 'architect drawing plans', 'construction site workers', 'modern living room', 'house garden', 'kitchen interior', 'bedroom interior', 'house facade evening', 'family home' );
		for ( $i = 1; $i <= $n; $i++ ) {
			$sections[] = array(
				'kind'    => 'body',
				'heading' => 'Part ' . $i . ': ' . $b['title'],
				'words'   => 150 + 60 * ( $i % 3 ),
				'points'  => array( 'Key point one', 'Key point two' ),
				'images'  => array( $queries[ ( $i - 1 ) % count( $queries ) ] ),
			);
		}
		// Bản thử: ảnh có sẵn đầu tiên dùng cho mục thân bài đầu tiên.
		if ( $candidates ) {
			$sections[1]['library'] = array( $candidates[0]['id'] );
		}
		if ( $b['length'] >= 1800 ) {
			$sections[] = array(
				'kind'        => 'faq',
				'heading'     => 'Frequently asked questions',
				'words'       => 200,
				'subheadings' => array( 'Question one?', 'Question two?', 'Question three?' ),
			);
		}
		$sections[] = array(
			'kind'    => 'conclusion',
			'heading' => 'Conclusion',
			'words'   => 90,
			'points'  => array( 'Summary', 'Next step' ),
		);
		return array(
			'angle'    => 'Demo (no AI call).',
			'sections' => $sections,
		);
	}

	private static function mock_write( $sections, $images ) {
		$out = '';
		foreach ( $sections as $i => $s ) {
			if ( 'intro' !== $s['kind'] ) {
				$out .= '<h2>' . esc_html( $s['heading'] ) . "</h2>\n";
			}
			$left = $s['words'];
			while ( $left > 0 ) {
				$n     = min( 60, $left );
				$out  .= '<p>' . trim( str_repeat( 'word ', $n ) ) . "</p>\n";
				$left -= $n;
				if ( $left === $s['words'] - $n ) {
					foreach ( $images as $k => $img ) {
						if ( $img['section'] === $i ) {
							$out .= '<img data-smc-img="' . $k . '" alt="Illustration ' . $k . "\">\n";
						}
					}
				}
			}
		}
		return $out;
	}
}

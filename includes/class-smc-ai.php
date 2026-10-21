<?php
defined( 'ABSPATH' ) || exit;

/**
 * Gọi AI (Claude, ChatGPT hoặc Gemini, theo Cài đặt) qua HTTP API của WordPress, đọc kết quả dạng stream
 * để yêu cầu dài không bị ngắt và để báo tiến độ.
 */
class SMC_AI {

	const URL        = 'https://api.anthropic.com/v1/messages';
	const MAX_TOKENS = 64000;
	const MAX_IMAGES = 20;

	/* ---------- Tạo bài ---------- */

	public static function generate( $brief, $tick ) {
		if ( defined( 'SMC_MOCK_AI' ) && SMC_MOCK_AI ) {
			return self::mock_generate( $brief );
		}
		$keep = 'rewrite' !== $brief['mode'];
		$task = "## This task\nTurn the author's article in the user message into a Smart Content article. The material is the article as they wrote it in the WordPress editor, given as plain HTML: its headings show the structure they intended, and each `<img>` shows which picture they placed where. Keep every picture with the text it illustrates unless a clearer grouping (a gallery or slider of pictures that sit together) is evident, and use every picture they included. A bare video URL, `<video>` or `<iframe>` is an embedded video: present it with `data-smc-video`.\n\n"
			. ( $keep
				? "Text policy: use the author's text as given. Keep their wording, facts, numbers and links. You may split it into sections, turn prose into lists/steps/tables where that is what it is, and add the short headings, labels and captions a component needs. Do not add claims or remove content."
				: "Text policy: you may rewrite, tighten and expand the text so it reads well and is easy to scan, in the same language as the material. Do not invent facts, figures, prices, testimonials or sources." )
			. "\n\nReturn exactly this and nothing else:\n<smc-html>\n(inner HTML of the wrapper)\n</smc-html>\n<smc-css>\n(the CSS)\n</smc-css>";

		$content = array();
		$images  = self::describe_images( $brief['images'] );
		foreach ( $images as $img ) {
			$content[] = array(
				'type' => 'text',
				'text' => $img['line'],
			);
			if ( $img['data'] ) {
				$content[] = array(
					'type'   => 'image',
					'source' => array(
						'type'       => 'base64',
						'media_type' => $img['mime'],
						'data'       => $img['data'],
					),
				);
			}
		}
		$text = "Title (printed by the theme, do not repeat it): " . $brief['title'] . "\n\n"
			. ( $images ? "The images above are the ones used in the material, shown so you can see what each contains.\n\n" : '' )
			. "<material>\n" . $brief['content'] . "\n</material>";
		if ( '' !== trim( $brief['instructions'] ) ) {
			$text .= "\n\n<owner_instructions>\n" . $brief['instructions'] . "\n</owner_instructions>";
		}
		$content[] = array(
			'type' => 'text',
			'text' => $text,
		);

		$res = self::call( self::system_prompt( $brief['colors'] ) . "\n\n" . $task, $content, array(), $tick );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$html = self::between( $res['text'], 'smc-html' );
		$css  = self::between( $res['text'], 'smc-css' );
		if ( '' === $html ) {
			return new WP_Error( 'smc_format', __( 'The AI returned an invalid format. Please try again.', 'smart-content-seo' ) );
		}
		return array(
			'html' => $html,
			'css'  => $css,
		);
	}

	/* ---------- Sửa theo góp ý ---------- */

	public static function edit( $draft, $comments, $images, $colors, $tick ) {
		if ( defined( 'SMC_MOCK_AI' ) && SMC_MOCK_AI ) {
			return self::mock_edit( $draft, $comments );
		}
		$task = "## This task\nYou are revising an existing article according to the site owner's comments. Change what the comments ask for and whatever must change with it (for example the CSS a new element needs); leave everything else exactly as it is, because the owner has already approved it.\n\n"
			. "Return a JSON object with a short `summary` (one sentence, in the language of the comments, saying what you changed) and a list of `edits`. Each edit replaces one passage:\n"
			. "- `target`: \"html\" or \"css\".\n"
			. "- `find`: an exact substring copied character for character from the current html or css, including whitespace and line breaks. It must occur exactly once, so include enough surrounding text to be unique, and no more than needed.\n"
			. "- `replace`: the new text for that passage. Use an empty string to delete.\n"
			. "- To add new CSS rules, use target \"css\" with an empty `find`; the `replace` text is appended to the stylesheet.\n"
			. "Edits are applied in order, each to the result of the previous one.";

		$list = '';
		foreach ( $comments as $n => $c ) {
			$list .= ( $n + 1 ) . '. ' . $c['note'] . "\n";
			if ( '' !== $c['quote'] ) {
				$list .= '   Selected text: "' . $c['quote'] . "\"\n";
			}
			if ( '' !== $c['html'] ) {
				$list .= "   Selected element (may be truncated):\n   " . $c['html'] . "\n";
			}
		}
		$text = "<current_html>\n" . $draft['html'] . "\n</current_html>\n\n<current_css>\n" . $draft['css'] . "\n</current_css>\n\n";
		$imgs = self::describe_images( $images, false );
		if ( $imgs ) {
			$text .= "<available_images>\n" . implode( "\n", wp_list_pluck( $imgs, 'line' ) ) . "\n</available_images>\n\n";
		}
		$text .= "<comments>\n" . $list . '</comments>';

		$format = array(
			'type'   => 'json_schema',
			'schema' => array(
				'type'                 => 'object',
				'additionalProperties' => false,
				'required'             => array( 'summary', 'edits' ),
				'properties'           => array(
					'summary' => array( 'type' => 'string' ),
					'edits'   => array(
						'type'  => 'array',
						'items' => array(
							'type'                 => 'object',
							'additionalProperties' => false,
							'required'             => array( 'target', 'find', 'replace' ),
							'properties'           => array(
								'target'  => array(
									'type' => 'string',
									'enum' => array( 'html', 'css' ),
								),
								'find'    => array( 'type' => 'string' ),
								'replace' => array( 'type' => 'string' ),
							),
						),
					),
				),
			),
		);

		$retry_note = '';
		for ( $attempt = 0; $attempt < 2; $attempt++ ) {
			$res = self::call(
				self::system_prompt( $colors ) . "\n\n" . $task,
				array(
					array(
						'type' => 'text',
						'text' => $text . $retry_note,
					),
				),
				array( 'format' => $format ),
				$tick
			);
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			$json = json_decode( $res['text'], true );
			if ( ! is_array( $json ) || ! isset( $json['edits'] ) || ! is_array( $json['edits'] ) ) {
				return new WP_Error( 'smc_format', __( 'The AI returned an invalid format. Please try again.', 'smart-content-seo' ) );
			}
			$applied = self::apply_edits( $draft, $json['edits'] );
			if ( ! $applied['failed'] ) {
				if ( ! $json['edits'] ) {
					/* translators: %s: the AI's own one-sentence summary. */
					return new WP_Error( 'smc_noop', sprintf( __( 'The AI did not change anything. %s', 'smart-content-seo' ), (string) $json['summary'] ) );
				}
				return array(
					'html'    => $applied['html'],
					'css'     => $applied['css'],
					'summary' => isset( $json['summary'] ) ? (string) $json['summary'] : '',
				);
			}
			$retry_note = "\n\n<previous_attempt_failed>\nYour previous edits could not be applied because these `find` strings did not occur exactly once in the current text. Copy them exactly from above, with more context if they were ambiguous:\n"
				. implode( "\n---\n", $applied['failed'] ) . "\n</previous_attempt_failed>";
		}
		return new WP_Error( 'smc_locate', __( 'The AI could not locate the passage to change. Select the passage again and make your comment more specific.', 'smart-content-seo' ) );
	}

	/** Áp các chỗ thay thế; tất cả hoặc không gì cả. */
	public static function apply_edits( $draft, $edits ) {
		$doc    = array(
			'html' => $draft['html'],
			'css'  => $draft['css'],
		);
		$failed = array();
		foreach ( $edits as $e ) {
			$t = ( isset( $e['target'] ) && 'css' === $e['target'] ) ? 'css' : 'html';
			$f = isset( $e['find'] ) ? (string) $e['find'] : '';
			$r = isset( $e['replace'] ) ? (string) $e['replace'] : '';
			if ( '' === $f ) {
				if ( 'css' === $t ) {
					$doc['css'] = rtrim( $doc['css'] ) . "\n\n" . $r;
				} else {
					$failed[] = '(empty find is only allowed for css)';
				}
				continue;
			}
			if ( 1 !== substr_count( $doc[ $t ], $f ) ) {
				$failed[] = $f;
				continue;
			}
			$pos       = strpos( $doc[ $t ], $f );
			$doc[ $t ] = substr( $doc[ $t ], 0, $pos ) . $r . substr( $doc[ $t ], $pos + strlen( $f ) );
		}
		$doc['failed'] = $failed;
		return $doc;
	}

	/* ---------- Prompt ---------- */

	/**
	 * @param array $colors Màu riêng của bài: [ 'primary' => '#..', 'accent' => '#..' ], rỗng thì dùng màu của site.
	 */
	private static function system_prompt( $colors = array() ) {
		$s = smc_settings();
		foreach ( array( 'primary', 'accent' ) as $k ) {
			if ( ! empty( $colors[ $k ] ) ) {
				$s[ 'token_' . $k ] = $colors[ $k ];
			}
		}
		$tokens = '';
		foreach ( smc_tokens() as $key => $def ) {
			$val     = smc_clean_token( $s[ 'token_' . $key ] );
			$tokens .= '- `var(--smc-' . $key . ')`: ' . $def[0] . ', currently `' . ( '' === $val ? $def[1] : $val ) . "`\n";
		}
		$width = max( 320, (int) $s['width'] );

		$p = 'You design "Smart Content" articles for a WordPress site: article bodies that are visually clear and pleasant to read, written as hand-crafted HTML and CSS instead of plain paragraphs. Your output is placed inside a wrapper `<div class="smc-root">` in the theme\'s article template, below the post title that the theme already prints.

## What a good result looks like
Each article gets its own layout, chosen for its content; there is no fixed template to fill. Read the material first and decide what presentation serves each part: steps become a timeline or numbered process, comparisons a table or side-by-side cards, items with pictures a card grid or slider, key numbers stat blocks, questions an accordion, warnings a callout. Plain prose stays as well-set paragraphs; not everything needs a component. A reader skimming on a phone should grasp the structure at a glance, and the page should look like it belongs to this site rather than to a generic landing page.

## Constraints of the environment
- Output only the inner HTML of the wrapper. No `<html>`, `<head>`, `<body>`, `<style>`, `<script>`, `<link>`, `<iframe>`, no inline event handlers, no HTML comments, and no external fonts, stylesheets or scripts. The plugin strips these.
- The theme prints the title as `<h1>`. Start at `<h2>` and keep heading levels in order.
- Everything inside the wrapper is reset by the plugin so that theme styles cannot leak in: margins, padding, borders, backgrounds, list markers, link and button styling are zeroed, and text inherits from the wrapper. So style every element you use, including paragraph spacing, heading sizes, list markers, links and tables.
- Every class name starts with `smc-`. Write selectors normally (for example `.smc-card h3`); the plugin scopes them to this post. To address the wrapper itself use `.smc-root`. Do not use `!important`, `position: fixed`, `@import`, or selectors for anything outside the wrapper.
- Colours and type come from the site\'s design tokens, so the article matches the site and follows it when the theme changes. Use these custom properties instead of hard-coded brand colours. Neutral black/white overlays and `color-mix()` of the tokens are fine. Set the font tokens with a separate `font-family` declaration, never inside the `font` shorthand: their value can be `inherit`, which makes a shorthand invalid.
' . $tokens . '- The wrapper is a size container named `smc`. The content column is about ' . $width . 'px wide on desktop and is often much narrower than the viewport, so make layouts responsive with `@container smc (max-width: ...)` queries, not viewport `@media` width queries. Design for a narrow column first; it has to work at 320px with no horizontal overflow.
- Images: use only images that appear in the material or in the supplied image list, by their exact URLs, with `width` and `height` attributes, `loading="lazy"` and meaningful `alt` text. If there are none, design without photographs: typography, shapes and small inline SVG icons carry the design. Never invent an image URL.
- Icons are inline `<svg>` using `currentColor`, small and simple.
- Accessibility: readable contrast, real `<button>` elements for controls, `<table>` with `<th>` for tabular data, `<details>`/`<summary>` for accordions.

## Interactive behaviour
You cannot write scripts. The plugin\'s script provides these behaviours when you add the attributes; you write all of their styling.
- Slider: `<div data-smc-slider>` containing `<div data-smc-track>` whose direct children are the slides, plus optional `<button data-smc-prev>`, `<button data-smc-next>` and an empty `<div data-smc-dots>`. The track scrolls horizontally with snap; each slide is full width unless you set another `flex-basis` on the slides. The script fills the dots container with `<button>` elements and puts `is-active` on the current dot and slide, and `disabled` on prev/next at the ends.
- Tabs: `<div data-smc-tabs>` containing `<button data-smc-tab="key">` and `<div data-smc-panel="key">`. The script puts `is-active` on the selected tab and the `hidden` attribute on the other panels; the first tab starts selected.
- Zoom: `data-smc-zoom` on an `<img>` (or on an `<a href="large image URL">` around one) opens it in a full-screen viewer; images inside the same `data-smc-gallery` ancestor can be stepped through.
- Video: `<div data-smc-video="URL">` (YouTube, Vimeo or a video file URL) containing a poster image and a play button of your design. On click the script replaces the contents with the player.
- Reveal on scroll: `data-smc-reveal` gets the class `is-visible` when it enters the viewport. The wrapper gets the class `smc-js` once the script runs, so write the hidden state as `.smc-root.smc-js [data-smc-reveal] { ... }` and content stays visible if the script does not load.';
		if ( '' !== trim( $s['style_note'] ) ) {
			$p .= "\n\n## Style notes from the site owner\n" . trim( $s['style_note'] );
		}
		return $p;
	}

	/**
	 * Thông tin ảnh cho AI: dòng mô tả (URL, kích thước, alt) và ảnh thu nhỏ để AI nhìn được nội dung ảnh.
	 */
	public static function describe_images( $ids, $with_data = true ) {
		$out = array();
		foreach ( array_slice( array_map( 'intval', (array) $ids ), 0, self::MAX_IMAGES ) as $id ) {
			$src = wp_get_attachment_image_src( $id, 'large' );
			if ( ! $src ) {
				continue;
			}
			$alt  = trim( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) );
			$line = 'Image: url="' . $src[0] . '" width=' . (int) $src[1] . ' height=' . (int) $src[2]
				. ' title="' . get_the_title( $id ) . '"' . ( '' !== $alt ? ' alt="' . $alt . '"' : '' )
				. ( '' !== wp_get_attachment_caption( $id ) ? ' caption="' . wp_get_attachment_caption( $id ) . '"' : '' );
			$item = array(
				'line' => $line,
				'data' => '',
				'mime' => '',
			);
			if ( $with_data ) {
				$path = self::thumb_path( $id );
				$mime = $path ? wp_check_filetype( $path ) : array( 'type' => '' );
				if ( $path && in_array( $mime['type'], array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ), true ) && filesize( $path ) < 1500000 ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- file ảnh cục bộ trong uploads.
					$item['data'] = base64_encode( file_get_contents( $path ) );
					$item['mime'] = $mime['type'];
				}
			}
			$out[] = $item;
		}
		return $out;
	}

	private static function thumb_path( $id ) {
		$size = image_get_intermediate_size( $id, 'medium' );
		if ( $size && ! empty( $size['path'] ) ) {
			$uploads = wp_get_upload_dir();
			$path    = trailingslashit( $uploads['basedir'] ) . $size['path'];
			if ( file_exists( $path ) ) {
				return $path;
			}
		}
		$path = get_attached_file( $id );
		return ( $path && file_exists( $path ) ) ? $path : '';
	}

	public static function between( $text, $tag ) {
		if ( preg_match( '#<' . $tag . '>(.*?)(</' . $tag . '>|\z)#s', $text, $m ) ) {
			return trim( $m[1] );
		}
		return '';
	}

	/* ---------- HTTP ---------- */

	/**
	 * Gọi nhà cung cấp AI đang chọn trong Cài đặt (Claude, ChatGPT hoặc Gemini) và đọc kết quả dạng stream.
	 * Mọi nơi trong plugin dựng nội dung theo dạng khối của Claude; từng nhà cung cấp tự chuyển sang dạng của mình.
	 *
	 * @param string   $system  Prompt hệ thống.
	 * @param array    $content Các khối nội dung của tin nhắn người dùng: [ type => text, text ] hoặc [ type => image, source => [ media_type, data ] ].
	 * @param array    $opts    'format' => [ type => json_schema, schema => ... ] cho kết quả JSON (tuỳ chọn).
	 * @param callable $tick    Gọi mỗi khi nhận được dữ liệu: fn( int $so_ky_tu ).
	 * @return array|WP_Error   [ 'text' => ..., 'stop' => ... ]
	 */
	public static function call( $system, $content, $opts, $tick ) {
		$provider = smc_provider();
		$name     = smc_providers()[ $provider ]['label'];
		$key      = smc_api_key();
		if ( '' === $key ) {
			/* translators: %s: AI provider name, e.g. Google Gemini. */
			return new WP_Error( 'smc_no_key', sprintf( __( 'No %s API key yet. Enter it in Smart Content > Settings.', 'smart-content-seo' ), $name ) );
		}
		$s      = smc_settings();
		$effort = in_array( $s['effort'], array( 'low', 'medium', 'high' ), true ) ? $s['effort'] : 'medium';
		$schema = ! empty( $opts['format']['schema'] ) ? $opts['format']['schema'] : null;
		$req    = call_user_func( array( __CLASS__, 'req_' . $provider ), smc_model(), $key, $effort, $system, $content, $schema );

		// stop: '' | max_tokens | refusal.
		$st = array(
			'buf'    => '',
			'text'   => '',
			'raw'    => '',
			'stop'   => '',
			'error'  => '',
			'hooked' => false,
		);

		$parse = $req['parse'];
		$feed  = function ( $chunk ) use ( &$st, $tick, $parse ) {
			if ( strlen( $st['raw'] ) < 4000 ) {
				$st['raw'] .= $chunk;
			}
			$st['buf'] .= $chunk;
			while ( false !== ( $nl = strpos( $st['buf'], "\n" ) ) ) {
				$line      = rtrim( substr( $st['buf'], 0, $nl ), "\r" );
				$st['buf'] = substr( $st['buf'], $nl + 1 );
				if ( 0 !== strpos( $line, 'data:' ) ) {
					continue;
				}
				$ev = json_decode( trim( substr( $line, 5 ) ), true );
				if ( is_array( $ev ) ) {
					$parse( $ev, $st );
				}
			}
			if ( $tick ) {
				call_user_func( $tick, strlen( $st['text'] ) );
			}
		};

		// Nhận dữ liệu ngay khi nó về, thay vì chờ hết yêu cầu.
		$url  = $req['url'];
		$hook = function ( $handle, $args, $hook_url ) use ( &$st, $feed, $url ) {
			if ( $hook_url !== $url ) {
				return;
			}
			$st['hooked'] = true;
			// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- chỉ gắn hàm nhận dữ liệu vào yêu cầu của WP HTTP API.
			curl_setopt(
				$handle,
				CURLOPT_WRITEFUNCTION,
				function ( $ch, $chunk ) use ( $feed ) {
					$feed( $chunk );
					return strlen( $chunk );
				}
			);
		};
		add_action( 'http_api_curl', $hook, 10, 3 );
		$res = wp_remote_post(
			$url,
			array(
				'timeout' => 1500,
				'headers' => $req['headers'] + array( 'content-type' => 'application/json' ),
				'body'    => wp_json_encode( $req['body'] ),
			)
		);
		remove_action( 'http_api_curl', $hook, 10 );

		if ( is_wp_error( $res ) ) {
			/* translators: 1: AI provider name, 2: error message. */
			return new WP_Error( 'smc_http', sprintf( __( 'Could not connect to %1$s: %2$s', 'smart-content-seo' ), $name, $res->get_error_message() ) );
		}
		if ( ! $st['hooked'] ) {
			$feed( wp_remote_retrieve_body( $res ) . "\n" );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( 200 !== $code ) {
			$err = json_decode( $st['raw'], true );
			$err = isset( $err[0] ) ? $err[0] : $err; // Gemini đôi khi trả lỗi trong một mảng.
			$msg = isset( $err['error']['message'] ) ? $err['error']['message'] : 'HTTP ' . $code;
			if ( 401 === $code || 403 === $code || false !== stripos( $msg, 'API key' ) ) {
				/* translators: 1: AI provider name, 2: model id, 3: error message from the provider. */
				$msg = sprintf( __( 'The %1$s API key is invalid or not allowed to use the model %2$s. (%3$s)', 'smart-content-seo' ), $name, smc_model(), $msg );
			} elseif ( 404 === $code ) {
				/* translators: 1: model id, 2: AI provider name, 3: error message from the provider. */
				$msg = sprintf( __( 'The model %1$s does not exist at %2$s. Check the model name in Settings. (%3$s)', 'smart-content-seo' ), smc_model(), $name, $msg );
			} elseif ( 429 === $code ) {
				/* translators: 1: AI provider name, 2: error message from the provider. */
				$msg = sprintf( __( 'Your %1$s account has hit a limit (rate limit or free quota). Please try again in a few minutes. (%2$s)', 'smart-content-seo' ), $name, $msg );
			} elseif ( $code >= 500 ) {
				/* translators: 1: AI provider name, 2: error message from the provider. */
				$msg = sprintf( __( '%1$s is overloaded. Please try again in a few minutes. (%2$s)', 'smart-content-seo' ), $name, $msg );
			}
			return new WP_Error( 'smc_api', $msg );
		}
		if ( '' !== $st['error'] ) {
			return new WP_Error( 'smc_api', $st['error'] );
		}
		if ( 'refusal' === $st['stop'] ) {
			return new WP_Error( 'smc_refusal', __( 'The AI refused to process this content.', 'smart-content-seo' ) );
		}
		if ( 'max_tokens' === $st['stop'] ) {
			return new WP_Error( 'smc_too_long', __( 'The post is too long, so the result was cut off. Shorten the content or split it into several posts.', 'smart-content-seo' ) );
		}
		if ( '' === $st['text'] ) {
			return new WP_Error( 'smc_empty', __( 'The AI returned no content. Please try again.', 'smart-content-seo' ) );
		}
		return array(
			'text' => $st['text'],
			'stop' => $st['stop'],
		);
	}

	/* Claude: Messages API. */
	private static function req_claude( $model, $key, $effort, $system, $content, $schema ) {
		$body = array(
			'model'         => $model,
			'max_tokens'    => self::MAX_TOKENS,
			'stream'        => true,
			'output_config' => array( 'effort' => $effort ),
			'system'        => array(
				array(
					'type'          => 'text',
					'text'          => $system,
					'cache_control' => array( 'type' => 'ephemeral' ),
				),
			),
			'messages'      => array(
				array(
					'role'    => 'user',
					'content' => $content,
				),
			),
		);
		if ( $schema ) {
			$body['output_config']['format'] = array(
				'type'   => 'json_schema',
				'schema' => $schema,
			);
		}
		$headers = array(
			'x-api-key'         => $key,
			'anthropic-version' => '2023-06-01',
		);
		// Khi bộ lọc an toàn từ chối nhầm, API tự chạy lại trên mô hình dự phòng trong cùng yêu cầu.
		if ( in_array( $model, array( 'claude-opus-5-5', 'claude-sonnet-5-5' ), true ) ) {
			$body['fallbacks']         = 'default';
			$headers['anthropic-beta'] = 'server-side-fallback-2026-07-01';
		}
		return array(
			'url'     => self::URL,
			'headers' => $headers,
			'body'    => $body,
			'parse'   => function ( $ev, &$st ) {
				$type = isset( $ev['type'] ) ? $ev['type'] : '';
				if ( 'content_block_delta' === $type && isset( $ev['delta']['type'] ) && 'text_delta' === $ev['delta']['type'] ) {
					$st['text'] .= $ev['delta']['text'];
				} elseif ( 'message_delta' === $type && ! empty( $ev['delta']['stop_reason'] ) ) {
					$st['stop'] = in_array( $ev['delta']['stop_reason'], array( 'max_tokens', 'refusal' ), true ) ? $ev['delta']['stop_reason'] : '';
				} elseif ( 'error' === $type ) {
					$st['error'] = isset( $ev['error']['message'] ) ? $ev['error']['message'] : __( 'Unknown error from the API.', 'smart-content-seo' );
				}
			},
		);
	}

	/* ChatGPT: Responses API. */
	private static function req_openai( $model, $key, $effort, $system, $content, $schema ) {
		$input = array();
		foreach ( $content as $c ) {
			$input[] = 'image' === $c['type']
				? array(
					'type'      => 'input_image',
					'image_url' => 'data:' . $c['source']['media_type'] . ';base64,' . $c['source']['data'],
				)
				: array(
					'type' => 'input_text',
					'text' => $c['text'],
				);
		}
		$body = array(
			'model'             => $model,
			'instructions'      => $system,
			'input'             => array(
				array(
					'role'    => 'user',
					'content' => $input,
				),
			),
			'max_output_tokens' => self::MAX_TOKENS,
			'reasoning'         => array( 'effort' => $effort ),
			'stream'            => true,
			'store'             => false,
		);
		if ( $schema ) {
			$body['text'] = array(
				'format' => array(
					'type'   => 'json_schema',
					'name'   => 'smc_result',
					'schema' => $schema,
					'strict' => true,
				),
			);
		}
		return array(
			'url'     => 'https://api.openai.com/v1/responses',
			'headers' => array( 'Authorization' => 'Bearer ' . $key ),
			'body'    => $body,
			'parse'   => function ( $ev, &$st ) {
				$type = isset( $ev['type'] ) ? $ev['type'] : '';
				if ( 'response.output_text.delta' === $type ) {
					$st['text'] .= (string) $ev['delta'];
				} elseif ( 'response.refusal.delta' === $type ) {
					$st['stop'] = 'refusal';
				} elseif ( 'response.incomplete' === $type ) {
					$why        = isset( $ev['response']['incomplete_details']['reason'] ) ? $ev['response']['incomplete_details']['reason'] : '';
					$st['stop'] = 'content_filter' === $why ? 'refusal' : 'max_tokens';
				} elseif ( 'response.failed' === $type ) {
					$st['error'] = isset( $ev['response']['error']['message'] ) ? $ev['response']['error']['message'] : __( 'ChatGPT reported an error while processing.', 'smart-content-seo' );
				} elseif ( 'error' === $type ) {
					$st['error'] = isset( $ev['message'] ) ? $ev['message'] : ( isset( $ev['error']['message'] ) ? $ev['error']['message'] : __( 'Unknown error from the API.', 'smart-content-seo' ) );
				}
			},
		);
	}

	/* Gemini: generateContent dạng stream (SSE). */
	private static function req_gemini( $model, $key, $effort, $system, $content, $schema ) {
		$parts = array();
		foreach ( $content as $c ) {
			$parts[] = 'image' === $c['type']
				? array(
					'inlineData' => array(
						'mimeType' => $c['source']['media_type'],
						'data'     => $c['source']['data'],
					),
				)
				: array( 'text' => $c['text'] );
		}
		$config = array( 'maxOutputTokens' => self::MAX_TOKENS );
		// Gemini 3 trở đi đặt mức suy nghĩ theo cấp; Gemini cũ hơn để mặc định.
		if ( preg_match( '/^gemini-([3-9]|\d\d)/', $model ) ) {
			$config['thinkingConfig'] = array( 'thinkingLevel' => $effort );
		}
		if ( $schema ) {
			$config['responseMimeType']   = 'application/json';
			$config['responseJsonSchema'] = $schema;
		}
		return array(
			'url'     => 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode( $model ) . ':streamGenerateContent?alt=sse',
			'headers' => array( 'x-goog-api-key' => $key ),
			'body'    => array(
				'systemInstruction' => array( 'parts' => array( array( 'text' => $system ) ) ),
				'contents'          => array(
					array(
						'role'  => 'user',
						'parts' => $parts,
					),
				),
				'generationConfig'  => $config,
			),
			'parse'   => function ( $ev, &$st ) {
				if ( isset( $ev['error']['message'] ) ) {
					$st['error'] = $ev['error']['message'];
					return;
				}
				if ( ! empty( $ev['promptFeedback']['blockReason'] ) ) {
					$st['stop'] = 'refusal';
				}
				$cand = isset( $ev['candidates'][0] ) ? $ev['candidates'][0] : array();
				foreach ( isset( $cand['content']['parts'] ) ? (array) $cand['content']['parts'] : array() as $part ) {
					// Bỏ phần suy nghĩ của model, chỉ lấy câu trả lời.
					if ( isset( $part['text'] ) && empty( $part['thought'] ) ) {
						$st['text'] .= $part['text'];
					}
				}
				$why = isset( $cand['finishReason'] ) ? $cand['finishReason'] : '';
				if ( 'MAX_TOKENS' === $why ) {
					$st['stop'] = 'max_tokens';
				} elseif ( in_array( $why, array( 'SAFETY', 'PROHIBITED_CONTENT', 'BLOCKLIST', 'SPII', 'RECITATION', 'IMAGE_SAFETY' ), true ) ) {
					$st['stop'] = 'refusal';
				}
			},
		);
	}

	/* ---------- Chế độ thử (không gọi AI): define( 'SMC_MOCK_AI', true ) ---------- */

	private static function mock_generate( $brief ) {
		$paras = '';
		$text  = wp_strip_all_tags( preg_replace( '#</(p|h\d|li|figure|tr)>#i', "\n", $brief['content'] ) );
		foreach ( array_filter( array_map( 'trim', explode( "\n", $text ) ) ) as $p ) {
			$paras .= '<p class="smc-p">' . esc_html( $p ) . "</p>\n";
		}
		$img = '';
		foreach ( (array) $brief['images'] as $id ) {
			$src = wp_get_attachment_image_src( (int) $id, 'large' );
			if ( $src ) {
				$img .= '<div class="smc-slide"><img data-smc-zoom src="' . esc_url( $src[0] ) . '" width="' . (int) $src[1] . '" height="' . (int) $src[2] . '" loading="lazy" alt=""></div>';
			}
		}
		$html = '<section class="smc-intro" data-smc-reveal><h2 class="smc-h2">Demo (no AI call)</h2>' . "\n" . $paras . '</section>'
			. ( $img ? '<div class="smc-slider" data-smc-slider data-smc-gallery><div class="smc-track" data-smc-track>' . $img . '</div><button class="smc-nav" data-smc-prev>‹</button><button class="smc-nav" data-smc-next>›</button><div class="smc-dots" data-smc-dots></div></div>' : '' )
			. '<div class="smc-grid"><div class="smc-card"><h3>Card one</h3><p>Card content.</p></div><div class="smc-card"><h3>Card two</h3><p>Card content.</p></div></div>'
			. '<details class="smc-faq"><summary>Sample question?</summary><p>Sample answer.</p></details>';
		$css  = '.smc-root{display:grid;gap:24px}.smc-h2{font-family:var(--smc-font-heading);font-weight:700;font-size:1.6em;line-height:1.25;color:var(--smc-primary)}.smc-p{margin-top:12px}'
			. '.smc-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}@container smc (max-width:520px){.smc-grid{grid-template-columns:1fr}}'
			. '.smc-card{padding:20px;background:var(--smc-surface);border:1px solid var(--smc-border);border-radius:var(--smc-radius)}.smc-card h3{font-weight:700;margin-bottom:6px}'
			. '.smc-slider{position:relative}.smc-slide img{width:100%;border-radius:var(--smc-radius)}.smc-nav{position:absolute;top:45%;background:var(--smc-bg);width:36px;height:36px;border-radius:50%}'
			. '.smc-nav[data-smc-next]{right:8px}.smc-nav[data-smc-prev]{left:8px}.smc-dots{display:flex;gap:6px;justify-content:center;margin-top:10px}'
			. '.smc-dots button{width:8px;height:8px;border-radius:50%;background:var(--smc-border)}.smc-dots button.is-active{background:var(--smc-primary)}'
			. '.smc-faq{padding:14px 18px;border:1px solid var(--smc-border);border-radius:var(--smc-radius)}.smc-faq summary{font-weight:700;cursor:pointer}';
		return array(
			'html' => $html,
			'css'  => $css,
		);
	}

	private static function mock_edit( $draft, $comments ) {
		$note = esc_html( $comments[0]['note'] );
		return array(
			'html'    => $draft['html'] . "\n" . '<p class="smc-p smc-mock-note">Comment received: ' . $note . '</p>',
			'css'     => $draft['css'] . "\n.smc-mock-note{color:var(--smc-accent)}",
			'summary' => 'Demo: added a line noting the comment.',
		);
	}
}

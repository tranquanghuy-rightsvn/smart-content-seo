/* Hộp "AI viết bài" trong trình soạn thảo: lên outline, viết bài kèm ảnh miễn phí rồi chèn thẳng vào trình soạn thảo, đổi ảnh. */
(function () {
	'use strict';
	var W = window.SMC_WRITE;
	if (!W || !document.getElementById('smc-w-outline')) return;
	var __ = wp.i18n.__, sprintf = wp.i18n.sprintf;

	function $(id) { return document.getElementById(id); }

	var el = {
		desc: $('smc-w-desc'), kw: $('smc-w-kw'), len: $('smc-w-len'), lib: $('smc-w-lib'), outline: $('smc-w-outline'), msg: $('smc-w-msg'),
		plan: $('smc-w-plan'), angle: $('smc-w-angle'), sections: $('smc-w-sections'), total: $('smc-w-total'),
		fit: $('smc-w-fit'), add: $('smc-w-add'), write: $('smc-w-write'),
		insertBox: $('smc-w-insert-box'), insert: $('smc-w-insert'),
		imgsBox: $('smc-w-imgs-box'), imgs: $('smc-w-imgs'), imgsReload: $('smc-w-imgs-reload')
	};
	var saved = W.write || {};
	var plan = saved.outline ? { angle: saved.angle || '', sections: saved.outline } : null;
	var queries = saved.queries || {}; // ID ảnh => từ khoá đã dùng để tìm ảnh đó
	var thumbs = W.thumbs || {};       // ID ảnh trong Thư viện => ảnh thu nhỏ
	var busy = false;
	var KINDS = {
		intro: __('Introduction', 'smart-content-seo'),
		body: __('Body', 'smart-content-seo'),
		faq: __('FAQ', 'smart-content-seo'),
		conclusion: __('Conclusion', 'smart-content-seo')
	};

	/* ---------- Gọi máy chủ ---------- */

	function api(method, path, data, query) {
		var url = W.rest + path;
		if (query) url += (url.indexOf('?') === -1 ? '?' : '&') + query;
		return fetch(url, {
			method: method,
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': W.nonce, 'Content-Type': 'application/json' },
			body: data ? JSON.stringify(data) : undefined
		}).then(function (r) {
			return r.text().then(function (t) {
				var j = {};
				try { j = JSON.parse(t); } catch (e) { /* máy chủ trả về không phải JSON */ }
				/* translators: %d: HTTP status code. */
				if (!r.ok) throw new Error(j.message || sprintf(__('The server returned error %d.', 'smart-content-seo'), r.status));
				return j;
			});
		});
	}

	function esc(s) {
		var d = document.createElement('div');
		d.textContent = s == null ? '' : String(s);
		return d.innerHTML;
	}
	function num(n) { return Number(n || 0).toLocaleString(document.documentElement.lang || undefined); }

	function say(text, kind) {
		el.msg.textContent = text || '';
		el.msg.className = 'smc-w-msg' + (kind ? ' is-' + kind : '');
	}

	/* ---------- Trình soạn thảo: khối (Gutenberg) hoặc cổ điển ---------- */

	var isBlock = document.body.classList.contains('block-editor-page') && window.wp && wp.data && wp.data.select('core/editor');

	function classic() {
		var mce = window.tinymce && window.tinymce.get('content');
		return { mce: mce && !mce.isHidden() ? mce : null, area: $('content') };
	}
	function classicGet() {
		var c = classic();
		return c.mce ? c.mce.getContent() : (c.area ? c.area.value : '');
	}
	function classicSet(html) {
		var c = classic();
		if (c.mce) c.mce.setContent(html);
		if (c.area) c.area.value = html;
	}

	var editor = {
		title: function () {
			return isBlock ? wp.data.select('core/editor').getEditedPostAttribute('title') || '' : ($('title') ? $('title').value : '');
		},
		content: function () {
			return isBlock ? wp.data.select('core/editor').getEditedPostContent() || '' : classicGet();
		},
		isEmpty: function () {
			var html = editor.content();
			return !/<img\b/i.test(html) && !html.replace(/<[^>]*>/g, '').trim();
		},
		set: function (html) {
			if (isBlock) {
				wp.data.dispatch('core/editor').resetEditorBlocks(wp.blocks.rawHandler({ HTML: html }));
			} else {
				// Trình soạn thảo cổ điển chèn ảnh dạng <img class="... wp-image-ID">, không dùng <figure>.
				classicSet(html.replace(/<figure\b[^>]*>\s*(<img\b[^>]*>)\s*<\/figure>/gi, function (m, img) {
					return '<p>' + img.replace('class="wp-image-', 'class="aligncenter size-large wp-image-') + '</p>';
				}));
			}
		},
		/* Mô tả và ảnh đại diện: chỉ điền khi còn trống. */
		extras: function (excerpt, featured) {
			if (isBlock) {
				var sel = wp.data.select('core/editor'), edit = {};
				if (excerpt && !sel.getEditedPostAttribute('excerpt')) edit.excerpt = excerpt;
				if (featured && !sel.getEditedPostAttribute('featured_media')) edit.featured_media = featured;
				wp.data.dispatch('core/editor').editPost(edit);
				return;
			}
			var ex = $('excerpt'), th = $('_thumbnail_id');
			if (excerpt && ex && !ex.value.trim()) ex.value = excerpt;
			if (featured && th && (!th.value || th.value === '-1')) th.value = featured;
		},
		images: function () {
			var out = [];
			if (isBlock) {
				(function walk(blocks) {
					blocks.forEach(function (b) {
						if (b.name === 'core/image' && b.attributes.url) {
							out.push({ ref: b.clientId, url: b.attributes.url, id: b.attributes.id, alt: b.attributes.alt || '' });
						}
						if (b.innerBlocks && b.innerBlocks.length) walk(b.innerBlocks);
					});
				})(wp.data.select('core/block-editor').getBlocks());
				return out;
			}
			var tpl = document.createElement('template');
			tpl.innerHTML = classicGet();
			Array.prototype.forEach.call(tpl.content.querySelectorAll('img'), function (img, i) {
				var m = /wp-image-(\d+)/.exec(img.className);
				out.push({ ref: i, url: img.getAttribute('src'), id: m ? +m[1] : 0, alt: img.getAttribute('alt') || '' });
			});
			return out;
		},
		/* Giữ alt cũ nếu có: nó tả vai trò của ảnh trong bài. */
		replace: function (item, att) {
			if (isBlock) {
				wp.data.dispatch('core/block-editor').updateBlockAttributes(item.ref, {
					id: att.id, url: att.url, alt: item.alt || att.alt, sizeSlug: 'large', width: undefined, height: undefined, aspectRatio: undefined
				});
				return;
			}
			var tpl = document.createElement('template');
			tpl.innerHTML = classicGet();
			var img = tpl.content.querySelectorAll('img')[item.ref];
			if (!img) return;
			img.setAttribute('src', att.url);
			img.setAttribute('width', att.width);
			img.setAttribute('height', att.height);
			img.removeAttribute('srcset');
			img.removeAttribute('sizes');
			if (!img.getAttribute('alt')) img.setAttribute('alt', att.alt);
			img.className = /wp-image-\d+/.test(img.className) ? img.className.replace(/wp-image-\d+/, 'wp-image-' + att.id) : (img.className + ' wp-image-' + att.id).trim();
			classicSet(tpl.innerHTML);
		}
	};

	/* ---------- Outline ---------- */

	function target() { return +el.len.value; }
	function lines(s) { return String(s || '').split('\n').map(function (x) { return x.trim(); }).filter(Boolean); }

	function renderPlan() {
		el.plan.hidden = !plan;
		el.outline.textContent = plan ? __('Recreate outline', 'smart-content-seo') : __('Create outline', 'smart-content-seo');
		el.outline.classList.toggle('button-primary', !plan);
		if (!plan) return;
		el.angle.textContent = plan.angle || '';
		el.angle.hidden = !plan.angle;
		var last = plan.sections.length - 1;
		el.sections.innerHTML = plan.sections.map(function (s, i) {
			var intro = s.kind === 'intro';
			return '<div class="smc-w-sec is-' + esc(s.kind) + '" data-i="' + i + '">' +
				'<div class="smc-w-row">' +
					'<span class="smc-w-kind">' + esc(KINDS[s.kind] || s.kind) + '</span>' +
					(intro ? '<span class="smc-w-h smc-w-nohead">' + esc(__('Opening paragraphs, no heading', 'smart-content-seo')) + '</span>'
						: '<input type="text" class="smc-w-h" data-f="heading" value="' + esc(s.heading) + '" placeholder="' + esc(__('Section heading (H2)', 'smart-content-seo')) + '">') +
					'<label class="smc-w-words"><input type="number" min="20" step="10" data-f="words" value="' + (+s.words) + '"> ' + esc(__('words', 'smart-content-seo')) + '</label>' +
					'<span class="smc-w-tools">' +
						'<button type="button" class="button-link" data-act="up" title="' + esc(__('Move up', 'smart-content-seo')) + '"' + (i === 0 ? ' disabled' : '') + '>↑</button>' +
						'<button type="button" class="button-link" data-act="down" title="' + esc(__('Move down', 'smart-content-seo')) + '"' + (i === last ? ' disabled' : '') + '>↓</button>' +
						'<button type="button" class="button-link smc-w-del" data-act="del" title="' + esc(__('Delete section', 'smart-content-seo')) + '">×</button>' +
					'</span>' +
				'</div>' +
				'<div class="smc-w-cols">' +
					'<textarea rows="2" data-f="points" placeholder="' + esc(__('Key points, one per line', 'smart-content-seo')) + '">' + esc((s.points || []).join('\n')) + '</textarea>' +
					(intro ? '' : '<textarea rows="2" data-f="subheadings" placeholder="' + esc(s.kind === 'faq' ? __('Questions, one per line', 'smart-content-seo') : __('H3 subheadings, one per line (optional)', 'smart-content-seo')) + '">' + esc((s.subheadings || []).join('\n')) + '</textarea>') +
				'</div>' +
				'<div class="smc-w-lib"><span>' + esc(__('Library images', 'smart-content-seo')) + '</span>' + (s.library || []).map(function (id) {
					return '<span class="smc-w-lt"><img src="' + esc(thumbs[id] || '') + '" alt=""><button type="button" data-act="unlib" data-id="' + (+id) + '" title="' + esc(__('Remove this image', 'smart-content-seo')) + '">×</button></span>';
				}).join('') + '<button type="button" class="button-link" data-act="lib">' + esc(__('+ Choose from Media Library', 'smart-content-seo')) + '</button></div>' +
				'<label class="smc-w-img">' + esc(__('Free photos', 'smart-content-seo')) + ' <input type="text" data-f="images" value="' + esc((s.images || []).join('; ')) + '" placeholder="' + esc(__('Photo search keywords (in English), separated by ; leave empty if no more photos are needed', 'smart-content-seo')) + '"></label>' +
			'</div>';
		}).join('');
		renderTotal();
	}

	function renderTotal() {
		if (!plan) return;
		var words = 0, imgs = 0;
		plan.sections.forEach(function (s) { words += +s.words || 0; imgs += (s.images || []).length + (s.library || []).length; });
		var off = Math.abs(words - target()) > target() * 0.05;
		el.total.innerHTML = sprintf(
			/* translators: 1: words in the outline, 2: target words, 3: number of images, 4: number of sections. */
			__('Total <strong>%1$s</strong> / ~%2$s words · %3$s images · %4$s sections', 'smart-content-seo'),
			num(words), num(target()), num(imgs), num(plan.sections.length)
		);
		el.total.classList.toggle('is-off', off);
		el.fit.hidden = !off;
	}

	/* Chia lại số chữ cho đúng độ dài đã chọn, giữ tỉ lệ giữa các mục. */
	function fit() {
		var total = 0, sum = 0, big = 0;
		plan.sections.forEach(function (s) { total += +s.words || 0; });
		if (!total) return;
		plan.sections.forEach(function (s, i) {
			s.words = Math.max(30, Math.round((+s.words || 0) * target() / total / 10) * 10);
			sum += s.words;
			if (s.words > plan.sections[big].words) big = i;
		});
		plan.sections[big].words += target() - sum;
		renderPlan();
	}

	el.sections.addEventListener('input', function (e) {
		var f = e.target.getAttribute('data-f');
		var box = e.target.closest('[data-i]');
		if (!f || !box) return;
		var s = plan.sections[+box.getAttribute('data-i')];
		if (f === 'words') s.words = +e.target.value || 0;
		else if (f === 'images') s.images = e.target.value.split(';').map(function (x) { return x.trim(); }).filter(Boolean);
		else if (f === 'heading') s.heading = e.target.value;
		else s[f] = lines(e.target.value);
		renderTotal();
	});
	el.sections.addEventListener('click', function (e) {
		var b = e.target.closest('button[data-act]');
		if (!b) return;
		var i = +b.closest('[data-i]').getAttribute('data-i'), list = plan.sections, act = b.getAttribute('data-act');
		if (act === 'unlib') {
			var id = +b.getAttribute('data-id');
			list[i].library = (list[i].library || []).filter(function (x) { return +x !== id; });
		} else if (act === 'lib') {
			pickLibrary(list[i]);
			return;
		} else if (act === 'del') {
			if (list.length < 2) return;
			list.splice(i, 1);
		} else {
			var j = act === 'up' ? i - 1 : i + 1;
			if (j < 0 || j >= list.length) return;
			list.splice(j, 0, list.splice(i, 1)[0]);
		}
		renderPlan();
	});
	el.add.addEventListener('click', function () {
		// Mục mới đặt trước phần hỏi đáp và kết bài.
		var at = plan.sections.length;
		while (at > 0 && /^(faq|conclusion)$/.test(plan.sections[at - 1].kind)) at--;
		plan.sections.splice(at, 0, { kind: 'body', heading: '', words: 200, points: [], subheadings: [], library: [], images: [] });
		renderPlan();
		var inputs = el.sections.querySelectorAll('.smc-w-sec')[at];
		if (inputs) inputs.querySelector('.smc-w-h').focus();
	});
	el.fit.addEventListener('click', fit);

	/* Thêm ảnh trong Thư viện cho một mục; một ảnh chỉ dùng ở một mục. */
	function pickLibrary(sec) {
		if (!window.wp || !wp.media) return;
		var frame = wp.media({ title: __('Choose library images for this section', 'smart-content-seo'), library: { type: 'image' }, multiple: true, button: { text: __('Use these images', 'smart-content-seo') } });
		frame.on('select', function () {
			frame.state().get('selection').toJSON().forEach(function (a) {
				plan.sections.forEach(function (s) { s.library = (s.library || []).filter(function (x) { return +x !== a.id; }); });
				sec.library = (sec.library || []).concat(a.id);
				thumbs[a.id] = ((a.sizes && (a.sizes.medium || a.sizes.thumbnail)) || a).url;
			});
			renderPlan();
		});
		frame.open();
		// Bài mới chưa có ảnh thì WordPress mở tab Tải lên; ở đây cần chọn ảnh có sẵn nên mở tab Thư viện.
		frame.content.mode('browse');
	}
	el.len.addEventListener('change', renderTotal);

	// Enter trong ô nhập không được gửi form sửa bài của trình soạn thảo cổ điển.
	document.querySelector('#smc-write-box .smc-w').addEventListener('keydown', function (e) {
		if (e.key === 'Enter' && e.target.tagName === 'INPUT') e.preventDefault();
	});

	/* ---------- Tác vụ AI ---------- */

	function lock(on) {
		busy = on;
		[el.outline, el.write, el.insert].forEach(function (b) { b.disabled = on; });
		el.plan.classList.toggle('is-busy', on);
	}

	function brief() {
		return { post_id: W.postId, title: editor.title().trim(), description: el.desc.value, keywords: el.kw.value, length: target(), use_library: el.lib.checked };
	}

	function watch(job, runIt) {
		lock(true);
		var runFailed = null, since = Date.now(), timer;
		if (runIt) api('POST', '/jobs/' + job.id + '/run').catch(function (e) { runFailed = e; });
		say(job.type === 'write' ? __('Preparing…', 'smart-content-seo') : __('AI is creating the outline…', 'smart-content-seo'), 'busy');
		timer = setInterval(function () {
			api('GET', '/jobs/' + job.id, null, '_=' + Date.now()).then(function (j) {
				if (j.status === 'queued') {
					/* translators: %s: error message. */
					if (runFailed && Date.now() - since > 6000) { stop(); say(sprintf(__('The server could not run the task: %s', 'smart-content-seo'), runFailed.message), 'error'); }
					return;
				}
				if (j.status === 'running') {
					var text = j.stage || (j.type === 'write' ? __('AI is writing the post…', 'smart-content-seo') : __('AI is creating the outline…', 'smart-content-seo'));
					/* translators: %s: number of characters received so far. */
					say(text + (j.chars ? ' ' + sprintf(__('%s characters', 'smart-content-seo'), num(j.chars)) : ''), 'busy');
					return;
				}
				stop();
				if (j.status === 'error') { say(j.message || __('Something went wrong while processing.', 'smart-content-seo'), 'error'); return; }
				if (j.type === 'outline') {
					plan = j.result;
					Object.keys(j.result.thumbs || {}).forEach(function (k) { thumbs[k] = j.result.thumbs[k]; });
					renderPlan();
					say(j.message + ' ' + __('Review and adjust it if needed, then click Write post and add images.', 'smart-content-seo'), 'ok');
				} else {
					inserted(j.result, j.message);
				}
			}).catch(function () { /* lỗi mạng tạm thời: thử lại ở lượt sau */ });
		}, 2000);
		function stop() { clearInterval(timer); lock(false); }
	}

	function start(path, data) {
		if (busy) return;
		if (!W.hasKey) { say(__('No API key yet. Go to Smart Content > Settings to add one.', 'smart-content-seo'), 'error'); return; }
		lock(true);
		say(__('Sending…', 'smart-content-seo'), 'busy');
		api('POST', path, data)
			.then(function (r) { watch({ id: r.job_id, type: path === '/write' ? 'write' : 'outline' }, true); })
			.catch(function (e) { lock(false); say(e.message, 'error'); });
	}

	el.outline.addEventListener('click', function () {
		var b = brief();
		if (!b.title) { say(__('Enter the post title above first.', 'smart-content-seo'), 'error'); return; }
		if (plan && !window.confirm(__('Create a new outline to replace the current one?', 'smart-content-seo'))) return;
		start('/outline', b);
	});

	el.write.addEventListener('click', function () {
		var b = brief();
		if (!b.title) { say(__('Enter the post title above first.', 'smart-content-seo'), 'error'); return; }
		if (plan.sections.some(function (s) { return s.kind !== 'intro' && !String(s.heading).trim(); })) {
			say(__('Some sections have no heading.', 'smart-content-seo'), 'error');
			return;
		}
		if (!editor.isEmpty() && !window.confirm(__('The finished post will replace all content currently in the editor. Continue?', 'smart-content-seo'))) return;
		b.angle = plan.angle;
		b.outline = plan.sections;
		start('/write', b);
	});

	function inserted(result, message) {
		editor.set(result.html);
		editor.extras(el.desc.value.trim(), result.featured);
		queries = result.queries || {};
		saved.html = result.html;
		if (result.words) saved.words = result.words;
		el.insertBox.hidden = true;
		say(message + ' ' + __('Review the post and edit it if needed (click an image in the list below to replace it), then click Convert to Smart Content.', 'smart-content-seo'), 'ok');
		if (isBlock) {
			closeDrawer();
			wp.data.dispatch('core/notices').createNotice('success', message + ' ' + __('Review the post, then save it. To replace images: AI Writer > Images in this post.', 'smart-content-seo'), { id: 'smc-write', type: 'snackbar' });
		}
		setTimeout(renderImages, 300);
	}

	el.insert.addEventListener('click', function () {
		if (!editor.isEmpty() && !window.confirm(__('Replace all content currently in the editor with the post the AI wrote?', 'smart-content-seo'))) return;
		/* translators: %s: number of words. */
		inserted({ html: saved.html, featured: 0, queries: queries }, sprintf(__('Inserted the post the AI wrote (~%s words).', 'smart-content-seo'), num(saved.words)));
	});

	/* ---------- Ảnh trong bài ---------- */

	function renderImages() {
		var list = editor.images();
		el.imgsBox.hidden = !list.length;
		el.imgs.innerHTML = list.map(function (it, i) {
			return '<button type="button" data-i="' + i + '" title="' + esc(__('Replace this image', 'smart-content-seo')) + '"><img src="' + esc(it.url) + '" alt=""><span>' + esc(__('Replace', 'smart-content-seo')) + '</span></button>';
		}).join('');
		el.imgs._list = list;
	}
	el.imgs.addEventListener('click', function (e) {
		var b = e.target.closest('button[data-i]');
		if (!b || !window.SMCPicker) return;
		var item = el.imgs._list[+b.getAttribute('data-i')];
		window.SMCPicker.open({
			query: queries[item.id] || item.alt,
			onPick: function (att) {
				editor.replace(item, att);
				if (att.query) queries[att.id] = att.query;
				say(__('Image replaced.', 'smart-content-seo'), 'ok');
				setTimeout(renderImages, 200);
			}
		});
	});
	el.imgsReload.addEventListener('click', renderImages);

	/* ---------- Trình soạn thảo khối: mở bằng nút trên thanh công cụ ---------- */

	// Khung meta box của trình soạn thảo khối nằm thu gọn ở cuối trang, khó thấy: đưa hộp vào một cửa sổ riêng.
	var drawer = null;
	function openDrawer() {
		drawer.hidden = false;
		setTimeout(renderImages, 50);
		(plan ? el.write : el.desc).focus();
	}
	function closeDrawer() { drawer.hidden = true; }
	if (isBlock) {
		drawer = document.createElement('div');
		drawer.className = 'smc-wd';
		drawer.hidden = true;
		drawer.innerHTML = '<div class="smc-wd-box" role="dialog" aria-label="' + esc(__('AI Writer', 'smart-content-seo')) + '"><div class="smc-wd-head"><strong>' + esc(__('AI Writer', 'smart-content-seo')) + '</strong>' +
			'<button type="button" class="smc-pk-x" aria-label="' + esc(__('Close', 'smart-content-seo')) + '">×</button></div><div class="smc-wd-body"></div></div>';
		drawer.querySelector('.smc-wd-body').appendChild(document.querySelector('#smc-write-box .smc-w'));
		document.body.appendChild(drawer);
		document.getElementById('smc-write-box').style.display = 'none';
		drawer.querySelector('.smc-pk-x').addEventListener('click', closeDrawer);
		drawer.addEventListener('click', function (e) { if (e.target === drawer) closeDrawer(); });
		document.addEventListener('keydown', function (e) {
			if (e.key === 'Escape' && !drawer.hidden && !document.querySelector('.smc-pk:not([hidden])')) closeDrawer();
		});

		var top = document.createElement('button');
		top.type = 'button';
		top.className = 'components-button is-secondary is-compact smc-write-top';
		top.textContent = __('AI Writer', 'smart-content-seo');
		top.addEventListener('click', openDrawer);
		var mount = function () {
			var bar = document.querySelector('.editor-header__settings, .edit-post-header__settings');
			if (bar && top.parentNode !== bar) bar.insertBefore(top, bar.firstChild);
		};
		wp.data.subscribe(mount);
		mount();
	}

	/* ---------- Khởi động ---------- */

	renderPlan();
	// Trình soạn thảo khối nạp nội dung sau khi trang tải xong.
	window.addEventListener('load', function () {
		setTimeout(function () {
			renderImages();
			el.insertBox.hidden = !(saved.html && editor.isEmpty() && !W.job);
		}, 800);
	});
	if (W.job) {
		watch(W.job, W.job.status === 'queued');
		if (drawer) openDrawer();
	}
})();

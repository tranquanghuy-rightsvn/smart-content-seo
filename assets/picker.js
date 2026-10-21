/* Hộp chọn ảnh: tìm ảnh miễn phí (Pexels/Openverse) hoặc chọn từ Thư viện ảnh. Dùng: SMCPicker.open({ query, onPick(att) }). */
(function () {
	'use strict';
	var P = window.SMC_PICK;
	if (!P) return;
	var __ = wp.i18n.__, sprintf = wp.i18n.sprintf;

	var box = null, el = {}, opts = null, page = 1, items = [], loading = false;

	function api(method, path, data, query) {
		var url = P.rest + path;
		// Site không bật đường dẫn đẹp: địa chỉ REST đã có sẵn dấu ?.
		if (query) url += (url.indexOf('?') === -1 ? '?' : '&') + query;
		return fetch(url, {
			method: method,
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': P.nonce, 'Content-Type': 'application/json' },
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

	function build() {
		box = document.createElement('div');
		box.className = 'smc-pk';
		box.hidden = true;
		var sources = Object.keys(P.sources).map(function (k) {
			return '<option value="' + esc(k) + '">' + esc(P.sources[k]) + '</option>';
		}).join('');
		box.innerHTML =
			'<div class="smc-pk-box" role="dialog" aria-modal="true" aria-label="' + esc(__('Replace image', 'smart-content-seo')) + '">' +
				'<div class="smc-pk-head"><strong>' + esc(__('Replace image', 'smart-content-seo')) + '</strong><button type="button" class="smc-pk-x" aria-label="' + esc(__('Close', 'smart-content-seo')) + '">×</button></div>' +
				'<form class="smc-pk-search"><input type="search" placeholder="' + esc(__('Search photos, e.g. modern house facade', 'smart-content-seo')) + '">' +
					'<select' + (Object.keys(P.sources).length < 2 ? ' hidden' : '') + '>' + sources + '</select>' +
					'<button type="submit" class="button button-primary">' + esc(__('Search', 'smart-content-seo')) + '</button></form>' +
				'<p class="smc-pk-tip">' + esc(__('Free photos, fine for commercial use. Searching in English usually finds more photos.', 'smart-content-seo')) + '</p>' +
				'<div class="smc-pk-grid"></div>' +
				'<div class="smc-pk-foot"><span class="smc-pk-msg" role="status"></span>' +
					'<button type="button" class="button smc-pk-more" hidden>' + esc(__('Load more', 'smart-content-seo')) + '</button>' +
					'<button type="button" class="button smc-pk-lib">' + esc(__('Choose from Media Library / upload', 'smart-content-seo')) + '</button></div>' +
			'</div>';
		document.body.appendChild(box);
		el = {
			form: box.querySelector('form'), q: box.querySelector('input'), source: box.querySelector('select'),
			grid: box.querySelector('.smc-pk-grid'), msg: box.querySelector('.smc-pk-msg'),
			more: box.querySelector('.smc-pk-more'), lib: box.querySelector('.smc-pk-lib')
		};
		box.querySelector('.smc-pk-x').addEventListener('click', close);
		box.addEventListener('click', function (e) { if (e.target === box) close(); });
		document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !box.hidden) close(); });
		el.form.addEventListener('submit', function (e) { e.preventDefault(); search(1); });
		el.source.addEventListener('change', function () { search(1); });
		el.more.addEventListener('click', function () { search(page + 1); });
		el.lib.addEventListener('click', library);
		el.grid.addEventListener('click', function (e) {
			var b = e.target.closest('button[data-i]');
			if (b) pick(items[+b.getAttribute('data-i')]);
		});
	}

	function say(t) { el.msg.textContent = t || ''; }

	function search(p) {
		var q = el.q.value.trim();
		if (!q || loading) return;
		loading = true;
		page = p;
		if (p === 1) { items = []; el.grid.innerHTML = ''; }
		say(__('Searching…', 'smart-content-seo'));
		el.more.hidden = true;
		api('GET', '/images', null, 'q=' + encodeURIComponent(q) + '&source=' + encodeURIComponent(el.source.value) + '&page=' + p)
			.then(function (r) {
				var start = items.length;
				items = items.concat(r.items || []);
				el.grid.insertAdjacentHTML('beforeend', (r.items || []).map(function (it, i) {
					return '<button type="button" class="smc-pk-item" data-i="' + (start + i) + '" title="' + esc(it.alt) + '">' +
						'<img src="' + esc(it.thumb) + '" alt="" loading="lazy"><span>' + esc(it.author) + '</span></button>';
				}).join(''));
				say(items.length ? '' : __('No photos found. Try other keywords (English usually finds more).', 'smart-content-seo'));
				el.more.hidden = !(r.items && r.items.length);
			})
			.catch(function (e) { say(e.message); })
			.then(function () { loading = false; });
	}

	function done(att) {
		var cb = opts && opts.onPick;
		close();
		if (cb) cb(att);
	}

	function pick(it) {
		if (!it || loading) return;
		loading = true;
		box.classList.add('is-busy');
		say(__('Downloading the photo to the Media Library…', 'smart-content-seo'));
		api('POST', '/images/import', { post_id: P.postId, provider: it.provider, id: it.id, query: el.q.value.trim() })
			.then(done)
			.catch(function (e) { say(e.message); })
			.then(function () { loading = false; box.classList.remove('is-busy'); });
	}

	function library() {
		if (!window.wp || !wp.media) { say(__('Could not open the Media Library.', 'smart-content-seo')); return; }
		var frame = wp.media({ title: __('Choose an image', 'smart-content-seo'), library: { type: 'image' }, multiple: false, button: { text: __('Use this image', 'smart-content-seo') } });
		frame.on('select', function () {
			var a = frame.state().get('selection').first().toJSON();
			var s = (a.sizes && a.sizes.large) || a;
			done({ id: a.id, url: s.url, width: s.width, height: s.height, alt: a.alt || '', query: '' });
		});
		box.hidden = true;
		frame.on('close', function () { if (opts) box.hidden = false; });
		frame.open();
		// Bài mới chưa có ảnh thì WordPress mở tab Tải lên; ở đây cần chọn ảnh có sẵn nên mở tab Thư viện.
		frame.content.mode('browse');
	}

	function close() {
		if (box) box.hidden = true;
		opts = null;
	}

	window.SMCPicker = {
		open: function (o) {
			if (!box) build();
			opts = o || {};
			box.hidden = false;
			say('');
			el.q.value = opts.query || '';
			el.grid.innerHTML = '';
			items = [];
			el.more.hidden = true;
			el.q.focus();
			if (el.q.value) search(1);
		}
	};
})();

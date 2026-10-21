/* Trang Cài đặt: dò màu và font từ một bài thật của site, và hiện ô màu xem trước. */
(function () {
	'use strict';
	var __ = wp.i18n.__, _n = wp.i18n._n, sprintf = wp.i18n.sprintf;
	var btn = document.getElementById('smc-probe');
	var msg = document.getElementById('smc-probe-msg');

	function input(name) { return document.querySelector('[data-token="' + name + '"]'); }

	function swatches() {
		Array.prototype.forEach.call(document.querySelectorAll('.smc-swatch'), function (sw) {
			var field = sw.parentNode.querySelector('input');
			var v = field.value.trim();
			// Giá trị dạng var(--smc-...) cần các biến khác để hiện đúng màu.
			['primary', 'accent', 'text', 'bg'].forEach(function (n) {
				var f = input(n);
				if (f) sw.style.setProperty('--smc-' + n, f.value.trim());
			});
			sw.style.background = v;
		});
	}
	document.addEventListener('input', function (e) {
		if (e.target.hasAttribute && e.target.hasAttribute('data-token')) swatches();
	});
	swatches();

	function visible(color) {
		return color && color !== 'transparent' && !/rgba\(\s*\d+,\s*\d+,\s*\d+,\s*0\s*\)/.test(color);
	}

	function probe(doc, win) {
		var content = doc.querySelector('.entry-content, .wp-block-post-content, .post-content, .single-content, article .content, article, main') || doc.body;
		var cs = function (el) { return win.getComputedStyle(el); };
		var out = {};

		var p = content.querySelector('p') || content;
		out.text = cs(p).color;
		out['font-body'] = cs(p).fontFamily;

		var h = content.querySelector('h2, h3') || doc.querySelector('h1, h2');
		if (h) out['font-heading'] = cs(h).fontFamily;

		var bgEl = content;
		while (bgEl && !visible(cs(bgEl).backgroundColor)) bgEl = bgEl.parentElement;
		out.bg = bgEl ? cs(bgEl).backgroundColor : 'rgb(255, 255, 255)';

		var link = content.querySelector('p a, li a') || doc.querySelector('main a, article a');
		var button = doc.querySelector('.wp-block-button__link, .wp-element-button, button[type="submit"], input[type="submit"], .button, .btn');
		if (button && visible(cs(button).backgroundColor)) {
			out.accent = cs(button).backgroundColor;
			out.radius = cs(button).borderRadius.split(' ')[0];
		}
		if (link && cs(link).color !== out.text) out.primary = cs(link).color;
		else if (out.accent) out.primary = out.accent;

		// Đo theo đoạn văn: ở theme khối, khung nội dung rộng hết trang còn chữ mới nằm trong cột.
		// Lấy đoạn rộng nhất, vì đoạn đầu tiên có thể nằm trong một khối hẹp (cột, chú thích...).
		// Kết quả quá hẹp so với khung 1280px thì không tin, giữ giá trị đang có.
		var widest = 0;
		Array.prototype.slice.call(content.querySelectorAll('p'), 0, 20).forEach(function (el) {
			widest = Math.max(widest, el.getBoundingClientRect().width);
		});
		if (widest >= 400) out.width = Math.round(widest);
		return out;
	}

	// Chỉ hiện ô key và ô model của nhà cung cấp AI đang chọn.
	var provider = document.getElementById('smc-provider');
	function showProvider() {
		Array.prototype.forEach.call(document.querySelectorAll('.smc-provider-rows'), function (t) {
			t.hidden = t.getAttribute('data-provider') !== provider.value;
		});
	}
	if (provider) {
		provider.addEventListener('change', showProvider);
		showProvider();
	}

	if (!btn) return;
	btn.addEventListener('click', function () {
		msg.textContent = __('Reading the site design…', 'smart-content-seo');
		var frame = document.createElement('iframe');
		frame.style.cssText = 'position:absolute;left:-9999px;top:0;width:1280px;height:900px;visibility:hidden';
		frame.onload = function () {
			try {
				var found = probe(frame.contentDocument, frame.contentWindow);
				var n = 0;
				Object.keys(found).forEach(function (k) {
					var field = k === 'width' ? document.getElementById('smc-width') : input(k);
					if (field && found[k]) { field.value = found[k]; n++; }
				});
				swatches();
				/* translators: %d: number of fields filled in. */
				msg.textContent = sprintf(_n('Filled in %d value. Check it, then click Save Changes.', 'Filled in %d values. Check them, then click Save Changes.', n, 'smart-content-seo'), n);
			} catch (e) {
				msg.textContent = __('Could not read the site design. Please enter the values manually.', 'smart-content-seo');
			}
			frame.remove();
		};
		frame.src = window.SMC_PROBE.url;
		document.body.appendChild(frame);
	});

})();

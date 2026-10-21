/* Chạy trong khung xem trước của trình soạn: cho admin bấm chọn chỗ cần góp ý. */
(function () {
	'use strict';
	if (window.parent === window) return;
	var root = document.querySelector('.smc-root');
	if (!root) return;

	var mode = 'comment';
	var hovered = null;
	var selected = null;
	var pins = {};   // chỗ đã có góp ý chờ gửi: id góp ý => { el, badge }

	var style = document.createElement('style');
	style.textContent =
		'.smc-pv-hover{outline:2px dashed #2271b1 !important;outline-offset:2px;cursor:pointer !important}' +
		'.smc-pv-sel{outline:3px solid #d63638 !important;outline-offset:2px}' +
		'.smc-pv-pin{outline:2px solid #dba617 !important;outline-offset:2px}' +
		'.smc-pv-badge{position:absolute;z-index:99999;min-width:22px;height:22px;padding:0 6px;box-sizing:border-box;border-radius:11px;' +
		'background:#dba617;color:#1d2327;font:700 12px/22px sans-serif;text-align:center;pointer-events:none}';
	document.head.appendChild(style);

	function inRoot(el) {
		return el && el !== root && root.contains(el);
	}
	function setHover(el) {
		if (hovered) hovered.classList.remove('smc-pv-hover');
		hovered = el;
		if (el) el.classList.add('smc-pv-hover');
	}
	function clearSelected() {
		if (selected) selected.classList.remove('smc-pv-sel');
		selected = null;
	}
	function cleanHtml(el) {
		var c = el.cloneNode(true);
		c.classList.remove('smc-pv-hover', 'smc-pv-sel', 'smc-pv-pin', 'is-active', 'is-visible');
		Array.prototype.forEach.call(c.querySelectorAll('.smc-pv-pin'), function (x) {
			x.classList.remove('smc-pv-pin');
			if (!x.getAttribute('class')) x.removeAttribute('class');
		});
		if (!c.getAttribute('class')) c.removeAttribute('class');
		return c.outerHTML;
	}
	function pick(el, quote) {
		clearSelected();
		selected = el;
		el.classList.add('smc-pv-sel');
		var text = (quote || el.innerText || el.getAttribute('alt') || '').replace(/\s+/g, ' ').trim();
		// Chọn một ảnh (hoặc khối chỉ chứa một ảnh, như lớp phủ trên ảnh): gửi kèm địa chỉ ảnh để đổi ảnh được.
		var imgs = el.tagName === 'IMG' ? [el] : el.querySelectorAll('img');
		var img = !quote && imgs.length === 1 ? imgs[0] : null;
		window.parent.postMessage({
			type: 'smc-select',
			quote: text.slice(0, 400),
			html: cleanHtml(el).slice(0, 1800),
			tag: el.tagName.toLowerCase(),
			src: img ? img.getAttribute('src') : '',
			alt: img ? img.getAttribute('alt') || '' : ''
		}, window.location.origin);
	}

	/* Đặt số của từng góp ý ở góc trên bên trái của chỗ được đánh dấu. */
	function placeBadges() {
		Object.keys(pins).forEach(function (id) {
			var r = pins[id].el.getBoundingClientRect();
			pins[id].badge.style.left = Math.max(0, r.left + window.pageXOffset - 8) + 'px';
			pins[id].badge.style.top = Math.max(0, r.top + window.pageYOffset - 12) + 'px';
		});
	}
	function pin(id) {
		if (!selected) return;
		var badge = document.createElement('div');
		badge.className = 'smc-pv-badge';
		document.body.appendChild(badge);
		pins[id] = { el: selected, badge: badge };
		selected.classList.add('smc-pv-pin');
		clearSelected();
		var sel = window.getSelection();
		if (sel) sel.removeAllRanges();
	}
	function setPins(list) {
		var keep = {};
		list.forEach(function (p) { keep[p.id] = p.n; });
		Object.keys(pins).forEach(function (id) {
			if (keep[id]) { pins[id].badge.textContent = keep[id]; return; }
			var el = pins[id].el;
			pins[id].badge.remove();
			delete pins[id];
			var shared = Object.keys(pins).some(function (k) { return pins[k].el === el; });
			if (!shared) el.classList.remove('smc-pv-pin');
		});
		placeBadges();
	}
	window.addEventListener('resize', placeBadges);
	if (window.ResizeObserver) new ResizeObserver(placeBadges).observe(root);

	document.addEventListener('mouseover', function (e) {
		if (mode === 'comment') setHover(inRoot(e.target) ? e.target : null);
	});
	document.addEventListener('mouseleave', function () { setHover(null); });

	// Bắt ở pha capture để slider, tab... không chạy khi đang ở chế độ góp ý.
	document.addEventListener('click', function (e) {
		var link = e.target.closest('a[href]');
		if (link && (link.getAttribute('href') || '').charAt(0) !== '#') e.preventDefault();
		if (mode !== 'comment' || !inRoot(e.target)) return;
		e.preventDefault();
		e.stopPropagation();
		var sel = window.getSelection();
		var quote = sel && !sel.isCollapsed ? sel.toString().trim() : '';
		var el = e.target;
		if (quote) {
			var node = sel.getRangeAt(0).commonAncestorContainer;
			el = node.nodeType === 1 ? node : node.parentElement;
			if (!inRoot(el)) el = e.target;
		}
		setHover(null);
		pick(el, quote);
	}, true);

	// Bôi đen chữ trải qua nhiều phần tử thì không có sự kiện click, nên bắt thêm lúc nhả chuột.
	document.addEventListener('mouseup', function () {
		if (mode !== 'comment') return;
		var sel = window.getSelection();
		if (!sel || sel.isCollapsed || !sel.toString().trim()) return;
		var node = sel.getRangeAt(0).commonAncestorContainer;
		var el = node.nodeType === 1 ? node : node.parentElement;
		if (inRoot(el)) pick(el, sel.toString().trim());
	});

	window.addEventListener('message', function (e) {
		if (e.origin !== window.location.origin || !e.data) return;
		if (e.data.type === 'smc-mode') {
			mode = e.data.mode;
			setHover(null);
			if (mode !== 'comment') clearSelected();
		} else if (e.data.type === 'smc-clear') {
			clearSelected();
		} else if (e.data.type === 'smc-pin') {
			pin(e.data.id);
		} else if (e.data.type === 'smc-pins') {
			setPins(e.data.pins || []);
		} else if (e.data.type === 'smc-colors') {
			root.style.setProperty('--smc-primary', e.data.primary);
			root.style.setProperty('--smc-accent', e.data.accent);
		}
	});

	window.parent.postMessage({ type: 'smc-ready' }, window.location.origin);
})();

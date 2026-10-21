/* Trình soạn Smart Content trong trang quản trị. */
(function () {
	'use strict';
	var C = window.SMC;
	if (!C) return;
	var __ = wp.i18n.__, _n = wp.i18n._n, sprintf = wp.i18n.sprintf;

	function $(sel) { return document.querySelector(sel); }
	function all(sel) { return Array.prototype.slice.call(document.querySelectorAll(sel)); }

	var state = C.state;      // trạng thái bài từ máy chủ
	var pending = [];         // góp ý chưa gửi: [{id, note, quote, html, pinned}]
	var nextId = 1;
	var selection = null;     // chỗ đang chọn trong khung xem trước
	var mode = 'comment';
	var pollTimer = null;
	var colors = { primary: state.colors.primary, accent: state.colors.accent }; // rỗng là dùng màu của site
	var colorTimer = null;

	var el = {
		title: $('#smc-title'), status: $('#smc-status'), publish: $('#smc-publish'), view: $('#smc-view'), meta: $('#smc-meta'),
		schedule: $('#smc-schedule'), schedPop: $('#smc-sched-pop'), date: $('#smc-date'), schedOk: $('#smc-sched-ok'),
		notice: $('#smc-notice'), frame: $('#smc-frame'), busy: $('#smc-busy'), busyText: $('#smc-busy-text'),
		textmode: $('#smc-textmode'), instructions: $('#smc-instructions'), designHint: $('#smc-design-hint'),
		primary: $('#smc-primary'), accent: $('#smc-accent'), colorsReset: $('#smc-colors-reset'),
		convert: $('#smc-convert'), revert: $('#smc-revert'),
		selected: $('#smc-selected'), selectedText: $('#smc-selected-text'), swap: $('#smc-swap'), note: $('#smc-note'),
		add: $('#smc-add'), pending: $('#smc-pending'), send: $('#smc-send'), count: $('#smc-count'), versions: $('#smc-versions')
	};

	/* ---------- Gọi máy chủ ---------- */

	function api(method, path, data, query) {
		var url = C.rest + path;
		if (query) url += (url.indexOf('?') === -1 ? '?' : '&') + query;
		return fetch(url, {
			method: method,
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': C.nonce, 'Content-Type': 'application/json' },
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

	function notice(msg, ok) {
		el.notice.hidden = !msg;
		el.notice.textContent = msg || '';
		el.notice.classList.toggle('is-ok', !!ok);
	}

	/* ---------- Hiển thị ---------- */

	function esc(s) {
		var d = document.createElement('div');
		d.textContent = s;
		return d.innerHTML;
	}

	function showTab(name) {
		all('.smc-tabs button').forEach(function (b) { b.classList.toggle('is-on', b.getAttribute('data-tab') === name); });
		all('.smc-side section').forEach(function (s) { s.hidden = s.getAttribute('data-pane') !== name; });
	}

	function renderColors() {
		['primary', 'accent'].forEach(function (k) {
			el[k].value = colors[k] || C.site[k];
			el[k].parentNode.querySelector('code').textContent = el[k].value;
		});
		el.colorsReset.hidden = !colors.primary && !colors.accent;
	}

	/* Bài dùng biến màu, nên đổi màu là thấy ngay trong khung xem trước, không phải gọi AI. */
	function saveColors(reload) {
		renderColors();
		clearTimeout(colorTimer);
		if (!state.has_draft) return;
		if (!reload) post({ type: 'smc-colors', primary: el.primary.value, accent: el.accent.value });
		colorTimer = setTimeout(function () {
			api('POST', '/colors', { post_id: state.post_id, primary: colors.primary, accent: colors.accent })
				.then(function (s) { setState(s, reload); })
				.catch(function (e) { notice(e.message); });
		}, 500);
	}

	/* Số thứ tự của góp ý, cũng là số hiện trên chỗ được đánh dấu trong bài. */
	function syncPins() {
		post({ type: 'smc-pins', pins: pending.map(function (p, i) { return { id: p.id, n: i + 1 }; }) });
	}

	function renderPending() {
		el.pending.innerHTML = pending.map(function (p, i) {
			return '<li>' + (p.quote ? '<q>' + esc(p.quote.slice(0, 90)) + '</q>' : '<q>' + esc(__('Whole post', 'smart-content-seo')) + '</q>') + esc(p.note) +
				' <button type="button" class="button-link" data-i="' + i + '">' + esc(__('Remove', 'smart-content-seo')) + '</button></li>';
		}).join('');
		var n = pending.length + (el.note.value.trim() ? 1 : 0);
		el.count.textContent = pending.length ? '(' + pending.length + ')' : '';
		/* translators: %d: number of comments. */
		el.send.textContent = n > 1 ? sprintf(__('Ask AI to revise all (%d)', 'smart-content-seo'), n) : __('Ask AI to revise', 'smart-content-seo');
		el.send.disabled = !state.has_draft || !n;
		el.add.disabled = !state.has_draft || !el.note.value.trim();
	}

	function renderSelection() {
		el.selected.hidden = !selection;
		var what = '';
		if (selection) {
			/* translators: %s: alt text of the selected image. */
			what = selection.src ? (selection.alt ? sprintf(__('image "%s"', 'smart-content-seo'), selection.alt) : __('image', 'smart-content-seo')) : selection.quote || '<' + selection.tag + '>';
		}
		/* translators: %s: the selected text or element. */
		el.selectedText.textContent = selection ? sprintf(__('Selected: %s', 'smart-content-seo'), what) : '';
		el.swap.hidden = !(selection && selection.src && window.SMCPicker);
	}

	/* Đổi ảnh đang chọn sang ảnh miễn phí khác hoặc ảnh trong Thư viện; không cần gọi AI. */
	function swapImage() {
		if (!selection || !selection.src || isBusy()) return;
		var old = selection.src;
		window.SMCPicker.open({
			query: (state.img_queries || {})[old] || selection.alt,
			onPick: function (att) {
				busy(__('Replacing image…', 'smart-content-seo'));
				api('POST', '/swap', { post_id: state.post_id, old: old, attachment: att.id })
					.then(function (s) {
						busy('');
						setState(s, true);
						notice(s.live ? __('Image replaced. Click Update post to put it on the site.', 'smart-content-seo') : __('Image replaced.', 'smart-content-seo'), true);
					})
					.catch(function (e) { busy(''); notice(e.message); });
			}
		});
	}

	function render() {
		var has = state.has_draft;
		el.title.textContent = state.title || __('(No title)', 'smart-content-seo');
		el.meta.href = state.edit_url;
		el.view.hidden = !state.live;
		el.view.href = state.permalink;

		// Đăng và lên lịch: giống bài viết thường.
		el.schedule.hidden = !has || state.live;
		el.schedule.textContent = state.scheduled ? __('Reschedule', 'smart-content-seo') : __('Schedule', 'smart-content-seo');
		if (state.live) {
			el.status.textContent = !has ? __('Showing the original post', 'smart-content-seo') : state.dirty ? __('Unpublished changes', 'smart-content-seo') : __('Published', 'smart-content-seo');
			el.publish.textContent = __('Update post', 'smart-content-seo');
			el.publish.disabled = !has || !state.dirty;
		} else {
			/* translators: %s: scheduled date and time. */
			el.status.textContent = state.scheduled ? sprintf(__('Scheduled: %s', 'smart-content-seo'), state.date_label) : __('Draft', 'smart-content-seo');
			el.publish.textContent = state.scheduled ? __('Publish now', 'smart-content-seo') : __('Publish', 'smart-content-seo');
			el.publish.disabled = !has;
		}

		el.convert.textContent = has ? __('Redesign', 'smart-content-seo') : __('Convert to Smart Content', 'smart-content-seo');
		el.convert.classList.toggle('button-primary', !has);
		el.revert.hidden = !state.can_revert;
		el.designHint.textContent = has
			? __('Color changes apply instantly. Redesign makes the AI start again from your original post, so changes made through comments are lost (the old version stays in the Versions tab).', 'smart-content-seo')
			: __('The AI reads the post and images you wrote, then presents them as an article with its own design. Your original post is kept, so you can go back at any time.', 'smart-content-seo');

		el.versions.innerHTML = state.versions.map(function (v, i) {
			return '<li><div><time>' + esc(v.time) + '</time>' + esc(v.note || '') + '</div>' +
				(i === 0 ? '<span>' + esc(__('Current', 'smart-content-seo')) + '</span>' : '<button type="button" class="button button-small" data-v="' + esc(v.id) + '">' + esc(__('Restore', 'smart-content-seo')) + '</button>') + '</li>';
		}).join('') || '<li>' + esc(__('No versions yet.', 'smart-content-seo')) + '</li>';

		renderPending();
		renderSelection();
	}

	function reloadFrame() {
		selection = null;
		renderSelection();
		pending.forEach(function (p) { p.pinned = false; });
		el.frame.src = state.preview_url + '&_=' + Date.now();
	}

	function setState(s, reload) {
		state = s;
		render();
		if (reload) reloadFrame();
	}

	function busy(text) {
		el.busy.hidden = !text;
		el.busyText.textContent = text || '';
		[el.convert, el.send, el.add, el.publish, el.schedule].forEach(function (b) { b.classList.toggle('disabled', !!text); });
	}
	function isBusy() { return !el.busy.hidden; }

	/* ---------- Tác vụ AI ---------- */

	function refresh(reload) {
		return api('GET', '/state', null, 'post=' + state.post_id).then(function (s) { setState(s, reload); });
	}

	function watch(jobId, runIt) {
		var runFailed = null;
		busy(__('AI is working…', 'smart-content-seo'));
		if (runIt) {
			// Yêu cầu này giữ kết nối tới khi AI xong; nếu bị cắt giữa chừng thì tác vụ vẫn chạy, ta theo dõi qua poll().
			api('POST', '/jobs/' + jobId + '/run').catch(function (e) { runFailed = e; });
		}
		clearInterval(pollTimer);
		var queuedSince = Date.now();
		pollTimer = setInterval(poll, 2000);

		function finish() { clearInterval(pollTimer); busy(''); }
		function poll() {
			api('GET', '/jobs/' + jobId, null, '_=' + Date.now()).then(function (job) {
				if (job.status === 'queued') {
					if (runFailed && Date.now() - queuedSince > 6000) {
						finish();
						/* translators: %s: error message. */
						notice(sprintf(__('The server could not run the task: %s', 'smart-content-seo'), runFailed.message));
					}
					return;
				}
				if (job.status === 'running') {
					/* translators: %s: number of characters written so far. */
					busy(job.chars ? sprintf(__('AI is writing… %s characters', 'smart-content-seo'), job.chars.toLocaleString(document.documentElement.lang || undefined)) : __('AI is reading the post and planning the layout…', 'smart-content-seo'));
					return;
				}
				finish();
				if (job.status === 'error') {
					notice(job.message || __('Something went wrong while processing.', 'smart-content-seo'));
					refresh(false).then(function () { if (!state.has_draft) showTab('design'); });
					return;
				}
				if (job.type === 'edit') {
					/* translators: %s: summary of the changes the AI made. */
					notice(sprintf(__('Revised: %s', 'smart-content-seo'), job.message), true);
				} else {
					notice(__('The post is now Smart Content. Highlight anything you want changed to leave a comment, or publish the post.', 'smart-content-seo'), true);
				}
				pending = [];
				el.note.value = '';
				showTab('edit');
				refresh(true);
			}).catch(function () { /* lỗi mạng tạm thời: thử lại ở lượt sau */ });
		}
	}

	function convert(skipConfirm) {
		if (isBusy()) return;
		if (!C.hasKey) { notice(__('No API key yet. Go to Smart Content > Settings to add one.', 'smart-content-seo')); showTab('design'); return; }
		if (state.has_draft && !skipConfirm && !window.confirm(__('The AI will redesign from the original post. Changes made through comments will be lost; the current version stays in the Versions tab. Continue?', 'smart-content-seo'))) return;
		notice('');
		busy(__('Sending…', 'smart-content-seo'));
		api('POST', '/convert', {
			post_id: state.post_id,
			mode: el.textmode.value,
			instructions: el.instructions.value,
			primary: colors.primary,
			accent: colors.accent
		}).then(function (r) { watch(r.job_id, true); })
			.catch(function (e) { busy(''); notice(e.message); showTab('design'); });
	}

	function revert() {
		if (isBusy()) return;
		if (!window.confirm(__('Revert to original post: the post content goes back to exactly what was written in the editor before converting; the AI design and its versions are deleted. Continue?', 'smart-content-seo'))) return;
		busy(__('Reverting to the original post…', 'smart-content-seo'));
		api('POST', '/revert', { post_id: state.post_id })
			.then(function (r) { pending = []; busy(''); window.location.href = r.edit_url; })
			.catch(function (e) { busy(''); notice(e.message); });
	}

	function addComment() {
		var note = el.note.value.trim();
		if (!note || !state.has_draft) return false;
		var item = { id: nextId++, note: note, quote: selection ? selection.quote : '', html: selection ? selection.html : '' };
		pending.push(item);
		if (selection) post({ type: 'smc-pin', id: item.id });
		selection = null;
		el.note.value = '';
		syncPins();
		renderPending();
		renderSelection();
		return true;
	}

	function send() {
		if (isBusy()) return;
		addComment();
		if (!pending.length) return;
		notice('');
		busy(__('Sending…', 'smart-content-seo'));
		api('POST', '/edit', {
			post_id: state.post_id,
			comments: pending.map(function (p) { return { note: p.note, quote: p.quote, html: p.html }; })
		}).then(function (r) { watch(r.job_id, true); })
			.catch(function (e) { busy(''); notice(e.message); });
	}

	function publish(date) {
		if (isBusy()) return;
		/* translators: %d: number of comments not yet sent. */
		if (pending.length && !window.confirm(sprintf(_n('%d comment has not been sent to the AI yet. Continue anyway?', '%d comments have not been sent to the AI yet. Continue anyway?', pending.length, 'smart-content-seo'), pending.length))) return;
		el.schedPop.hidden = true;
		busy(date ? __('Scheduling…', 'smart-content-seo') : __('Publishing…', 'smart-content-seo'));
		var wasLive = state.live;
		api('POST', '/publish', { post_id: state.post_id, date: date || '' })
			.then(function (s) {
				busy('');
				setState(s, false);
				/* translators: %s: scheduled date and time. */
				notice(s.scheduled ? sprintf(__('The post will be published automatically at %s.', 'smart-content-seo'), s.date_label) : wasLive ? __('The post on the site has been updated.', 'smart-content-seo') : __('The post has been published.', 'smart-content-seo'), true);
			})
			.catch(function (e) { busy(''); notice(e.message); });
	}

	/* ---------- Khung xem trước ---------- */

	function post(msg) {
		if (el.frame.contentWindow) el.frame.contentWindow.postMessage(msg, window.location.origin);
	}
	function clearSelection() {
		selection = null;
		post({ type: 'smc-clear' });
		renderSelection();
	}

	window.addEventListener('message', function (e) {
		if (e.source !== el.frame.contentWindow || e.origin !== window.location.origin || !e.data) return;
		if (e.data.type === 'smc-ready') {
			post({ type: 'smc-mode', mode: mode });
		} else if (e.data.type === 'smc-select') {
			selection = e.data;
			showTab('edit');
			renderSelection();
			el.note.focus();
		}
	});

	/* ---------- Sự kiện ---------- */

	all('.smc-tabs button').forEach(function (b) {
		b.addEventListener('click', function () { showTab(b.getAttribute('data-tab')); });
	});
	all('#smc-device button').forEach(function (b) {
		b.addEventListener('click', function () {
			all('#smc-device button').forEach(function (x) { x.classList.toggle('is-on', x === b); });
			el.frame.style.width = b.getAttribute('data-w');
		});
	});
	all('#smc-mode button').forEach(function (b) {
		b.addEventListener('click', function () {
			all('#smc-mode button').forEach(function (x) { x.classList.toggle('is-on', x === b); });
			mode = b.getAttribute('data-mode');
			post({ type: 'smc-mode', mode: mode });
			if (mode !== 'comment') { selection = null; renderSelection(); }
		});
	});

	el.pending.addEventListener('click', function (e) {
		if (e.target.tagName !== 'BUTTON') return;
		pending.splice(+e.target.getAttribute('data-i'), 1);
		syncPins();
		renderPending();
	});
	el.versions.addEventListener('click', function (e) {
		var id = e.target.getAttribute('data-v');
		if (!id || isBusy()) return;
		api('POST', '/restore', { post_id: state.post_id, version: id })
			.then(function (s) {
				pending = [];
				setState(s, true);
				notice(s.live ? __('Version restored. Click Update post to put it on the site.', 'smart-content-seo') : __('Version restored.', 'smart-content-seo'), true);
			})
			.catch(function (err) { notice(err.message); });
	});

	['primary', 'accent'].forEach(function (k) {
		el[k].addEventListener('input', function () { colors[k] = el[k].value; saveColors(false); });
	});
	// Màu của site có thể không phải mã hex, nên trở về màu site thì tải lại khung xem trước cho đúng.
	el.colorsReset.addEventListener('click', function () { colors = { primary: '', accent: '' }; saveColors(true); });

	el.convert.addEventListener('click', function () { convert(false); });
	el.revert.addEventListener('click', revert);
	el.add.addEventListener('click', addComment);
	el.send.addEventListener('click', send);
	el.publish.addEventListener('click', function () { publish(''); });
	el.schedule.addEventListener('click', function () {
		if (isBusy()) return;
		el.date.value = state.date_input;
		el.schedPop.hidden = !el.schedPop.hidden;
	});
	el.schedOk.addEventListener('click', function () {
		if (!el.date.value) { el.date.focus(); return; }
		publish(el.date.value);
	});
	document.addEventListener('click', function (e) {
		if (!e.target.closest('.smc-sched')) el.schedPop.hidden = true;
	});
	$('#smc-unselect').addEventListener('click', clearSelection);
	el.swap.addEventListener('click', swapImage);
	el.note.addEventListener('input', renderPending);
	el.note.addEventListener('keydown', function (e) {
		if (e.key === 'Enter' && (e.metaKey || e.ctrlKey)) { e.preventDefault(); addComment(); }
	});

	window.addEventListener('beforeunload', function (e) {
		if (isBusy() || pending.length) { e.preventDefault(); e.returnValue = ''; }
	});

	/* ---------- Khởi động ---------- */

	el.instructions.value = state.instructions;
	el.textmode.value = state.mode;
	renderColors();
	render();
	reloadFrame();
	showTab(state.has_draft ? 'edit' : 'design');
	if (state.job) {
		watch(state.job.id, state.job.status === 'queued');
	} else if (C.autostart) {
		// Bỏ mã một lần khỏi địa chỉ để tải lại trang không chuyển bài lần nữa.
		history.replaceState(null, '', C.composer);
		convert(true);
	}
})();

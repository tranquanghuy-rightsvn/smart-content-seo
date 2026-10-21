/* Nút "Chuyển sang Smart Content" trong trình soạn thảo: lưu bài rồi mở trình soạn Smart Content. */
(function () {
	'use strict';
	var E = window.SMC_EDITOR;
	var btn = document.getElementById('smc-convert');
	var msg = document.getElementById('smc-convert-msg');
	if (!E) return;
	var __ = wp.i18n.__;

	var isBlock = document.body.classList.contains('block-editor-page') && window.wp && wp.data && wp.data.select('core/editor');
	// Trình soạn thảo khối: thêm nút lên thanh trên cùng, cạnh nút Lưu và Đăng, vì hộp ở cột bên dễ bị khuất.
	var top = null;
	if (isBlock) {
		top = document.createElement('button');
		top.type = 'button';
		top.className = 'components-button is-secondary is-compact smc-convert-top';
		top.textContent = __('Convert to Smart Content', 'smart-content-seo');
		var mount = function () {
			var bar = document.querySelector('.editor-header__settings, .edit-post-header__settings');
			if (bar && top.parentNode !== bar) bar.insertBefore(top, bar.firstChild);
		};
		wp.data.subscribe(mount);
		mount();
	}

	var ASK = __('This post is live on the site and has unsaved changes. Save (update the post) and then convert to Smart Content?', 'smart-content-seo');

	function say(text) {
		if (msg) msg.textContent = text;
		if (isBlock) wp.data.dispatch('core/notices').createNotice('info', text, { id: 'smc-convert', type: 'snackbar' });
	}
	function lock(on) {
		[btn, top].forEach(function (b) { if (b) b.disabled = on; });
	}
	function go() { window.location.href = E.url; }

	function fromBlockEditor() {
		var select = wp.data.select('core/editor');
		if (!String(select.getEditedPostContent() || '').trim()) { say(__('The post has no content yet.', 'smart-content-seo')); return; }
		var unsaved = select.isEditedPostDirty() || select.getCurrentPostAttribute('status') === 'auto-draft';
		if (!unsaved) { go(); return; }
		if (E.live && !window.confirm(ASK)) return;
		lock(true);
		say(__('Saving the post…', 'smart-content-seo'));
		wp.data.dispatch('core/editor').savePost().then(function () {
			if (select.didPostSaveRequestSucceed()) { go(); return; }
			lock(false);
			say(__('Could not save the post. Save it, then click again.', 'smart-content-seo'));
		});
	}

	function fromClassicEditor() {
		var form = document.getElementById('post');
		var area = document.getElementById('content');
		var mce = window.tinymce && window.tinymce.get('content');
		var content = mce && !mce.isHidden() ? mce.getContent() : (area ? area.value : '');
		if (!String(content || '').trim()) { say(__('The post has no content yet.', 'smart-content-seo')); return; }
		if (E.live) {
			var changed = !(window.wp && wp.autosave && wp.autosave.server) || wp.autosave.server.postChanged();
			if (!changed) { go(); return; }
			if (!window.confirm(ASK)) return;
		}
		var flag = document.createElement('input');
		flag.type = 'hidden';
		flag.name = 'smc_convert';
		flag.value = '1';
		form.appendChild(flag);
		// Lưu bằng đúng nút của WordPress; máy chủ chuyển hướng sang trình soạn Smart Content sau khi lưu.
		(document.getElementById(E.live ? 'publish' : 'save-post') || document.getElementById('publish')).click();
	}

	function start() {
		if (isBlock) fromBlockEditor();
		else if (document.getElementById('post')) fromClassicEditor();
	}
	if (btn) btn.addEventListener('click', start);
	if (top) top.addEventListener('click', start);
})();

/* Hilo Chat — settings screen behaviour. */
(function () {
	'use strict';

	var L = window.HiloAdmin || {};
	var form = document.getElementById('hilo-settings-form');
	if (!form) return;

	/* Feedback dismiss ------------------------------------------------ */
	document.querySelectorAll('.hilo-feedback__dismiss').forEach(function (b) {
		b.addEventListener('click', function () { b.closest('.hilo-feedback').remove(); });
	});

	/* App icon picker ------------------------------------------------- */
	var pick = document.getElementById('hilo-icon-pick');
	var reset = document.getElementById('hilo-icon-reset');
	var iconId = document.getElementById('hilo-icon-id');
	var iconImgs = document.querySelectorAll('[data-hilo-icon]');
	var frame = null;
	function setIcon(url) { iconImgs.forEach(function (img) { img.src = url; }); }
	if (pick) {
		pick.addEventListener('click', function () {
			if (!window.wp || !wp.media) return;
			if (!frame) {
				frame = wp.media({ title: L.iconTitle || 'App icon', library: { type: 'image' }, multiple: false });
				frame.on('select', function () {
					var a = frame.state().get('selection').first().toJSON();
					iconId.value = a.id;
					setIcon(a.sizes && a.sizes.thumbnail ? a.sizes.thumbnail.url : a.url);
					markDirty();
				});
			}
			frame.open();
		});
	}
	if (reset) {
		reset.addEventListener('click', function () { iconId.value = 0; setIcon(L.defaultIcon); markDirty(); });
	}

	/* Business hours -------------------------------------------------- */
	var always = document.getElementById('hilo-always');
	var week = document.getElementById('hilo-week');
	function syncHours() {
		if (!week) return;
		week.classList.toggle('is-disabled', !!(always && always.checked));
		week.querySelectorAll('.hilo-day').forEach(function (row) {
			var open = row.querySelector('.hilo-day__open');
			var allday = row.querySelector('.hilo-day__allday input');
			row.classList.toggle('is-closed', !(open && open.checked));
			row.classList.toggle('is-allday', !!(allday && allday.checked));
		});
	}
	if (always) always.addEventListener('change', syncHours);
	if (week) week.addEventListener('change', syncHours);
	syncHours();

	/* Live preview ---------------------------------------------------- */
	var preview = document.getElementById('hilo-preview');
	function val(name) { var f = form.elements[name]; return f ? f.value : ''; }
	function syncPreview() {
		if (!preview) return;
		preview.style.setProperty('--pv-primary', val('color_primary'));
		preview.style.setProperty('--pv-accent', val('color_accent'));
		preview.style.setProperty('--pv-soft', val('color_soft'));
		preview.style.setProperty('--pv-font-heading', val('font_heading') || 'inherit');
		preview.style.setProperty('--pv-font-body', val('font_body') || 'inherit');
		var pos = form.querySelector('input[name="position"]:checked');
		preview.classList.toggle('is-left', !!(pos && pos.value === 'left'));
		var title = preview.querySelector('.hilo-preview__title');
		var titleField = form.elements.title;
		if (title && titleField) title.textContent = titleField.value || titleField.placeholder;
		var greet = preview.querySelector('.hilo-preview__greeting');
		var greetField = form.elements.greeting;
		if (greet && greetField) greet.textContent = greetField.value || greetField.placeholder;
		var brand = form.elements.brand_name;
		var av = preview.querySelector('.hilo-preview__avatar');
		if (av && brand) av.textContent = (brand.value || 'C').trim().charAt(0).toUpperCase();
		var wa = form.elements.whatsapp_enabled;
		preview.classList.toggle('has-wa', !!(wa && wa.checked));
		var waSection = document.getElementById('hilo-whatsapp');
		if (waSection && wa) waSection.classList.toggle('is-off', !wa.checked);
		var waLabel = preview.querySelector('.hilo-preview__wa-label');
		var waField = form.elements.whatsapp_label;
		if (waLabel && waField) waLabel.textContent = waField.value || waField.placeholder;
		document.querySelectorAll('[data-hilo-hex]').forEach(function (code) {
			var f = form.elements[code.getAttribute('data-hilo-hex')];
			if (f) code.textContent = f.value;
		});
	}
	form.addEventListener('input', syncPreview);
	form.addEventListener('change', syncPreview);
	syncPreview();

	/* Unsaved changes ------------------------------------------------- */
	var bar = document.querySelector('.hilo-savebar');
	var note = document.querySelector('.hilo-savebar__note');
	var dirty = false;
	function markDirty() {
		if (dirty) return;
		dirty = true;
		if (bar) bar.classList.add('is-dirty');
		if (note && L.unsaved) note.textContent = L.unsaved;
	}
	form.addEventListener('input', markDirty);
	form.addEventListener('change', markDirty);
	form.addEventListener('submit', function () { dirty = false; });
	window.addEventListener('beforeunload', function (e) {
		if (dirty) { e.preventDefault(); e.returnValue = ''; }
	});

	/* Copy app URL ---------------------------------------------------- */
	var copyBtn = document.getElementById('hilo-copy-url');
	if (copyBtn) {
		copyBtn.addEventListener('click', function () {
			var url = copyBtn.getAttribute('data-url');
			var label = copyBtn.querySelector('.hilo-copy-label');
			var done = function () {
				if (!label) return;
				var old = label.textContent;
				label.textContent = L.copied || 'Copied';
				setTimeout(function () { label.textContent = old; }, 1600);
			};
			if (navigator.clipboard) navigator.clipboard.writeText(url).then(done).catch(function () { window.prompt('', url); });
			else window.prompt('', url);
		});
	}

	/* Destructive forms confirm before submitting --------------------- */
	document.querySelectorAll('form[data-hilo-confirm]').forEach(function (f) {
		f.addEventListener('submit', function (e) {
			if (!window.confirm(f.getAttribute('data-hilo-confirm'))) e.preventDefault();
		});
	});
})();

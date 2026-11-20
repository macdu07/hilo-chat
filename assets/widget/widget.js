/*! Hilo Chat — storefront widget */
(function () {
	'use strict';

	var CFG = window.HiloChatConfig;
	if (!CFG || window.__hiloChatLoaded) return;
	window.__hiloChatLoaded = true;

	/* i18n: strings are English; translations come from PHP (CFG.i18n). */
	var I18N = CFG.i18n || {};
	var LOCALE = CFG.locale || undefined;
	function __(s) { return Object.prototype.hasOwnProperty.call(I18N, s) ? I18N[s] : s; }
	function fmt(s) {
		var args = arguments, i = 0;
		return String(s).replace(/%(\d+)\$[sd]|%[sd]/g, function (m, pos) { return String(pos ? args[Number(pos)] : args[++i]); });
	}

	var LS_KEY = 'hilo_chat_v1';
	var POLL_OPEN = 3000, POLL_CLOSED = 20000, POLL_HIDDEN = 60000, CTX_EVERY = 60000;

	/* ------------------------------------------------------------------ *
	 * Small helpers
	 * ------------------------------------------------------------------ */
	function store(get, val) {
		try {
			if (get) return JSON.parse(localStorage.getItem(LS_KEY) || '{}');
			localStorage.setItem(LS_KEY, JSON.stringify(val));
		} catch (e) { return {}; }
	}
	function el(tag, attrs, children) {
		var n = document.createElement(tag);
		if (attrs) for (var k in attrs) {
			if (!attrs.hasOwnProperty(k) || attrs[k] == null || attrs[k] === false) continue;
			if (k === 'class') n.className = attrs[k];
			else if (k === 'text') n.textContent = attrs[k];
			else if (k === 'html') n.innerHTML = attrs[k]; // only used with static SVG strings
			else if (k.indexOf('on') === 0) n.addEventListener(k.slice(2), attrs[k]);
			else n.setAttribute(k, attrs[k] === true ? '' : attrs[k]);
		}
		(children || []).forEach(function (c) { if (c != null) n.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); });
		return n;
	}
	/** Renders text with clickable links, never as HTML. */
	function richText(text) {
		var frag = document.createDocumentFragment();
		var re = /(https?:\/\/[^\s<]+[^\s<.,;:!?)\]'"])/g, last = 0, m;
		text = String(text || '');
		while ((m = re.exec(text))) {
			if (m.index > last) frag.appendChild(document.createTextNode(text.slice(last, m.index)));
			frag.appendChild(el('a', { href: m[0], target: '_blank', rel: 'noopener noreferrer nofollow', text: m[0] }));
			last = m.index + m[0].length;
		}
		if (last < text.length) frag.appendChild(document.createTextNode(text.slice(last)));
		return frag;
	}
	function timeLabel(ts) {
		var d = new Date(ts * 1000), now = new Date();
		var hm = d.toLocaleTimeString(LOCALE, { hour: '2-digit', minute: '2-digit' });
		if (d.toDateString() === now.toDateString()) return hm;
		return d.toLocaleDateString(LOCALE, { day: 'numeric', month: 'short' }) + ' · ' + hm;
	}
	function api(method, path, data, isForm) {
		var url = CFG.rest + path;
		var opts = { method: method, credentials: 'same-origin', headers: {} };
		if (CFG.nonce) opts.headers['X-WP-Nonce'] = CFG.nonce;
		if (method === 'GET' && data) {
			var q = Object.keys(data).filter(function (k) { return data[k] !== undefined && data[k] !== null && data[k] !== ''; })
				.map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(data[k]); }).join('&');
			if (q) url += (url.indexOf('?') > -1 ? '&' : '?') + q;
		} else if (data) {
			if (isForm) opts.body = data;
			else { opts.headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(data); }
		}
		return fetch(url, opts).then(function (r) {
			return r.json().catch(function () { return {}; }).then(function (j) {
				if (!r.ok) { var e = new Error(j && j.message ? j.message : __('Connection error', 'hilo-chat')); e.status = r.status; throw e; }
				return j;
			});
		});
	}

	var ICON = {
		chat: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5.5A2.5 2.5 0 0 1 6.5 3h11A2.5 2.5 0 0 1 20 5.5v8a2.5 2.5 0 0 1-2.5 2.5H10l-4.2 3.6c-.5.4-1.3.1-1.3-.6V16A2.5 2.5 0 0 1 4 13.5z" fill="currentColor"/></svg>',
		close: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>',
		down: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 9l6 6 6-6" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"/></svg>',
		send: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4.4 11.2 18.7 4.6c.8-.4 1.6.4 1.2 1.2l-6.6 14.3c-.4.8-1.5.7-1.7-.1l-1.3-5.2a1 1 0 0 0-.7-.7l-5.2-1.3c-.8-.2-.9-1.3-.1-1.7z" fill="currentColor"/></svg>',
		clip: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 11.5 12.4 19a5 5 0 0 1-7.1-7.1l7.8-7.8a3.3 3.3 0 0 1 4.7 4.7l-7.6 7.6a1.7 1.7 0 0 1-2.4-2.4l7-7" stroke="currentColor" stroke-width="1.8" fill="none" stroke-linecap="round" stroke-linejoin="round"/></svg>',
		file: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8zM14 3v5h5" stroke="currentColor" stroke-width="1.8" fill="none" stroke-linejoin="round"/></svg>'
	};

	/* ------------------------------------------------------------------ *
	 * State
	 * ------------------------------------------------------------------ */
	var S = {
		open: false,
		conv: null,
		messages: [],
		lastId: 0,
		status: null,
		unread: 0,
		agentTyping: null,
		sending: false,
		pollTimer: null,
		lastCtx: 0,
		saved: store(true),
		seen: {},
		seenReady: false,
		renderSig: ''
	};

	/* ------------------------------------------------------------------ *
	 * DOM
	 * ------------------------------------------------------------------ */
	var host = document.createElement('hilo-chat');
	host.setAttribute('data-position', CFG.position);
	var t = CFG.theme || {};
	host.style.setProperty('--hc-primary', t.primary || '#1f2937');
	host.style.setProperty('--hc-accent', t.accent || '#2563eb');
	host.style.setProperty('--hc-soft', t.soft || '#f3f4f6');
	host.style.setProperty('--hc-font-heading', t.fontHeading || 'inherit');
	host.style.setProperty('--hc-font-body', t.fontBody || 'inherit');
	var root = host.attachShadow ? host.attachShadow({ mode: 'open' }) : host;

	var ui = {};
	ui.css = el('link', { rel: 'stylesheet', href: CFG.css });
	ui.badge = el('span', { class: 'hc-badge', 'aria-hidden': 'true' });
	ui.launcher = el('button', {
		class: 'hc-launcher', type: 'button', 'aria-label': __('Open chat', 'hilo-chat'), 'aria-expanded': 'false',
		onclick: function () { toggle(); }
	}, [el('span', { class: 'hc-launcher-icon hc-i-chat', html: ICON.chat }), el('span', { class: 'hc-launcher-icon hc-i-close', html: ICON.down }), ui.badge]);

	ui.statusDot = el('span', { class: 'hc-dot' });
	ui.statusText = el('span', { class: 'hc-status-text', text: '' });
	ui.agentAvatar = el('span', { class: 'hc-avatar', 'aria-hidden': 'true' });
	ui.headerName = el('div', { class: 'hc-title', text: CFG.title || 'Chat' });
	ui.header = el('header', { class: 'hc-header' }, [
		ui.agentAvatar,
		el('div', { class: 'hc-header-text' }, [ui.headerName, el('div', { class: 'hc-status' }, [ui.statusDot, ui.statusText])]),
		el('button', { class: 'hc-icon-btn hc-close', type: 'button', 'aria-label': __('Close chat', 'hilo-chat'), html: ICON.close, onclick: function () { toggle(false); } })
	]);

	ui.list = el('div', { class: 'hc-list', role: 'log', 'aria-live': 'polite', 'aria-relevant': 'additions' });
	ui.typing = el('div', { class: 'hc-typing', 'aria-hidden': 'true' }, [el('span'), el('span'), el('span')]);
	ui.scroller = el('div', { class: 'hc-scroll' }, [ui.list, ui.typing]);

	// Intro form (first message).
	ui.introMsg = el('textarea', { class: 'hc-input hc-textarea', rows: '3', placeholder: __('Type your question…', 'hilo-chat'), 'aria-label': __('Your message', 'hilo-chat'), maxlength: '2000', required: true });
	ui.introName = el('input', { class: 'hc-input', type: 'text', autocomplete: 'name', placeholder: __('Your name', 'hilo-chat'), 'aria-label': __('Your name', 'hilo-chat'), maxlength: '120', required: true });
	ui.introEmail = el('input', { class: 'hc-input', type: 'email', autocomplete: 'email', placeholder: __('Your email', 'hilo-chat'), 'aria-label': __('Your email', 'hilo-chat'), maxlength: '190', required: true });
	ui.hp = el('input', { class: 'hc-hp', type: 'text', name: 'website', tabindex: '-1', autocomplete: 'off', 'aria-hidden': 'true' });
	ui.introError = el('p', { class: 'hc-error', role: 'alert' });
	ui.introBtn = el('button', { class: 'hc-btn', type: 'submit' }, [el('span', { text: __('Send message', 'hilo-chat') }), el('span', { class: 'hc-btn-icon', html: ICON.send })]);
	ui.offlineNote = el('p', { class: 'hc-note' });
	ui.intro = el('form', { class: 'hc-intro', novalidate: true, onsubmit: onIntroSubmit }, [
		ui.offlineNote,
		ui.introMsg,
		CFG.user ? null : el('div', { class: 'hc-row' }, [ui.introName, ui.introEmail]),
		CFG.user ? el('p', { class: 'hc-signed', text: fmt(__('Writing as %1$s (%2$s)', 'hilo-chat'), CFG.user.name, CFG.user.email) }) : el('p', { class: 'hc-privacy', text: __('We will only use your email to reply if you leave before we answer.', 'hilo-chat') }),
		ui.hp,
		ui.introError,
		ui.introBtn
	]);

	// Composer.
	ui.file = el('input', { type: 'file', accept: 'image/jpeg,image/png,image/webp,image/gif,application/pdf', class: 'hc-file', onchange: onFile });
	ui.text = el('textarea', { class: 'hc-compose-input', rows: '1', placeholder: __('Type a message…', 'hilo-chat'), 'aria-label': __('Type a message', 'hilo-chat'), maxlength: '2000', oninput: onComposeInput, onkeydown: onComposeKey });
	ui.sendBtn = el('button', { class: 'hc-icon-btn hc-send', type: 'submit', 'aria-label': __('Send', 'hilo-chat'), html: ICON.send });
	ui.composer = el('form', { class: 'hc-composer', onsubmit: onComposeSubmit }, [
		el('button', { class: 'hc-icon-btn hc-attach', type: 'button', 'aria-label': __('Attach an image or PDF', 'hilo-chat'), html: ICON.clip, onclick: function () { ui.file.click(); } }),
		ui.file, ui.text, ui.sendBtn
	]);
	ui.composeError = el('p', { class: 'hc-error hc-compose-error', role: 'alert' });

	ui.footer = el('div', { class: 'hc-footer' }, [ui.composeError, ui.composer]);
	ui.panel = el('section', { class: 'hc-panel', role: 'dialog', 'aria-modal': 'false', 'aria-label': CFG.title || 'Chat', hidden: true }, [ui.header, ui.scroller, ui.intro, ui.footer]);
	// Greeting teaser next to the launcher.
	ui.teaserText = el('span', { class: 'hc-teaser-text' });
	ui.teaser = el('div', { class: 'hc-teaser', hidden: true, role: 'status' }, [
		el('button', { class: 'hc-teaser-body', type: 'button', onclick: function () { hideTeaser(); toggle(true); } }, [
			el('span', { class: 'hc-teaser-avatar', 'aria-hidden': 'true', text: (CFG.brand || 'C').trim().charAt(0).toUpperCase() }),
			el('span', { class: 'hc-teaser-content' }, [el('span', { class: 'hc-teaser-from', text: CFG.team || CFG.brand || '' }), ui.teaserText])
		]),
		el('button', { class: 'hc-teaser-close', type: 'button', 'aria-label': __('Close message', 'hilo-chat'), html: ICON.close, onclick: function (e) { e.stopPropagation(); dismissTeaser(); } })
	]);
	ui.wrap = el('div', { class: 'hc-root' + (CFG.position === 'left' ? ' hc-left' : '') }, [ui.panel, ui.teaser, ui.launcher]);

	root.appendChild(ui.css);
	root.appendChild(ui.wrap);

	function mount() {
		// Lock page scroll behind the full-screen sheet on phones.
		var lock = document.createElement('style');
		lock.textContent = '@media (max-width:520px){html.hilo-chat-open,html.hilo-chat-open body{overflow:hidden!important}}';
		document.head.appendChild(lock);
		document.body.appendChild(host);
		boot();
	}
	if (document.body) mount(); else document.addEventListener('DOMContentLoaded', mount);

	/* ------------------------------------------------------------------ *
	 * Rendering
	 * ------------------------------------------------------------------ */
	function renderHeader() {
		var st = S.status;
		var agent = S.conv && S.conv.agent;
		ui.headerName.textContent = agent ? agent.name : (CFG.title || 'Chat');
		ui.agentAvatar.textContent = '';
		if (agent && agent.avatar) {
			ui.agentAvatar.appendChild(el('img', { src: agent.avatar, alt: '' }));
		} else {
			ui.agentAvatar.textContent = (CFG.brand || 'C').trim().charAt(0).toUpperCase();
		}
		if (st) {
			ui.statusDot.className = 'hc-dot' + (st.online ? ' is-on' : '');
			ui.statusText.textContent = agent ? (st.online ? __('Online', 'hilo-chat') : CFG.team) : (st.online ? st.statusText : __('Offline · we will reply by email', 'hilo-chat'));
		} else {
			ui.statusText.textContent = CFG.team || '';
		}
	}

	function renderIntro() {
		var st = S.status;
		var hasConv = !!S.conv;
		ui.intro.hidden = hasConv;
		ui.footer.hidden = !hasConv;
		if (!hasConv) {
			var note = '';
			if (st && !st.online) {
				note = st.statusText + (st.nextOpening ? ' ' + st.nextOpening : '');
			}
			ui.offlineNote.textContent = note;
			ui.offlineNote.hidden = !note;
			ui.introBtn.firstChild.textContent = st && !st.online ? __('Leave a message', 'hilo-chat') : __('Send message', 'hilo-chat');
			if (CFG.user) { ui.introName.value = CFG.user.name; ui.introEmail.value = CFG.user.email; }
			else if (S.saved.name) { ui.introName.value = ui.introName.value || S.saved.name; ui.introEmail.value = ui.introEmail.value || S.saved.email || ''; }
		}
	}

	function greetingNode() {
		return el('div', { class: 'hc-msg hc-from-agent hc-greeting' }, [
			el('div', { class: 'hc-bubble' }, [richText(CFG.greeting || __('Hi! How can we help you?', 'hilo-chat'))])
		]);
	}

	function messageNode(m) {
		if (m.from === 'system') {
			return el('div', { class: 'hc-system', 'data-id': m.id }, [m.body]);
		}
		var mine = m.from === 'visitor';
		var bubble = el('div', { class: 'hc-bubble' });
		if (m.attachment) {
			var a = m.attachment;
			if (a.image) {
				var img = el('img', { src: a.url, alt: a.name || __('Image', 'hilo-chat'), loading: 'lazy' });
				if (a.w && a.h) { img.width = a.w; img.height = a.h; }
				bubble.appendChild(el('a', { class: 'hc-att-img', href: a.url, target: '_blank', rel: 'noopener' }, [img]));
			} else {
				bubble.appendChild(el('a', { class: 'hc-att-file', href: a.url, target: '_blank', rel: 'noopener' }, [el('span', { html: ICON.file }), el('span', { text: a.name || __('File', 'hilo-chat') })]));
			}
			if (m.local) bubble.appendChild(el('div', { class: 'hc-uploading', text: __('Uploading…', 'hilo-chat') }));
		}
		if (m.body) bubble.appendChild(el('div', { class: 'hc-text' }, [richText(m.body)]));
		var meta = el('div', { class: 'hc-meta' }, [m.local ? (m.failed ? __('Not sent', 'hilo-chat') : __('Sending…', 'hilo-chat')) : timeLabel(m.at)]);
		return el('div', { class: 'hc-msg ' + (mine ? 'hc-from-me' : 'hc-from-agent') + (m.failed ? ' is-failed' : ''), 'data-id': m.id }, [bubble, meta]);
	}

	function renderSignature() {
		return S.messages.map(function (m) { return m.id + (m.local ? 'l' : '') + (m.failed ? 'f' : ''); }).join(',') +
			'|' + (S.conv ? S.conv.agentReadId + S.conv.status : '-') + '|' + (S.agentTyping ? 1 : 0);
	}

	function renderMessages() {
		// Skip the rebuild when nothing changed (polling runs every few seconds).
		var sig = renderSignature();
		if (sig === S.renderSig && !S.forceScroll) return;
		S.renderSig = sig;
		var nearBottom = ui.scroller.scrollHeight - ui.scroller.scrollTop - ui.scroller.clientHeight < 80;
		ui.list.textContent = '';
		ui.list.appendChild(greetingNode());
		var lastAgentSender = null;
		S.messages.forEach(function (m, i) {
			var node = messageNode(m);
			// Group consecutive agent messages; show name on the first one.
			if (m.from === 'agent' && m.agent && lastAgentSender !== m.agent.name) {
				node.insertBefore(el('div', { class: 'hc-sender', text: m.agent.name }), node.firstChild);
			}
			lastAgentSender = m.from === 'agent' && m.agent ? m.agent.name : null;
			// Animate only messages that appear for the first time.
			if (S.seenReady && !S.seen[m.id]) node.classList.add('is-new');
			S.seen[m.id] = true;
			ui.list.appendChild(node);
		});
		// Read receipt under the last visitor message.
		if (S.conv) {
			for (var i = S.messages.length - 1; i >= 0; i--) {
				var m = S.messages[i];
				if (m.from === 'visitor' && !m.local) {
					if (S.conv.agentReadId >= m.id) {
						var n = ui.list.querySelector('[data-id="' + m.id + '"] .hc-meta');
						if (n) n.appendChild(el('span', { class: 'hc-seen', text: ' · ' + __('Seen', 'hilo-chat') }));
					}
					break;
				}
				if (m.from === 'agent') break;
			}
		}
		if (S.conv && S.conv.status === 'closed') {
			ui.list.appendChild(el('div', { class: 'hc-system', text: __('Conversation resolved. If you write again, we will reopen it.', 'hilo-chat') }));
		}
		S.seenReady = true;
		ui.typing.classList.toggle('is-on', !!S.agentTyping);
		if (nearBottom || S.forceScroll) { scrollBottom(); S.forceScroll = false; }
	}

	function renderBadge() {
		var n = S.open ? 0 : S.unread;
		ui.badge.textContent = n > 9 ? '9+' : (n ? String(n) : '');
		ui.badge.classList.toggle('is-on', n > 0);
		ui.launcher.setAttribute('aria-label', n ? fmt(__('Open chat, %d new messages', 'hilo-chat'), n) : __('Open chat', 'hilo-chat'));
	}

	function render() {
		renderHeader();
		renderIntro();
		renderMessages();
		renderBadge();
	}

	function scrollBottom() {
		requestAnimationFrame(function () { ui.scroller.scrollTop = ui.scroller.scrollHeight; });
	}

	/* ------------------------------------------------------------------ *
	 * Open / close
	 * ------------------------------------------------------------------ */
	function toggle(force) {
		var open = typeof force === 'boolean' ? force : !S.open;
		if (open === S.open) return;
		S.open = open;
		ui.wrap.classList.toggle('is-open', open);
		ui.launcher.setAttribute('aria-expanded', String(open));
		ui.launcher.setAttribute('aria-label', open ? __('Minimize chat', 'hilo-chat') : __('Open chat', 'hilo-chat'));
		if (open) {
			hideTeaser();
			ui.panel.hidden = false;
			requestAnimationFrame(function () { ui.panel.classList.add('is-visible'); });
			S.unread = 0;
			S.forceScroll = true;
			render();
			if (!S.status) refreshStatus();
			setTimeout(function () { (S.conv ? ui.text : ui.introMsg).focus({ preventScroll: true }); }, 180);
			document.documentElement.classList.add('hilo-chat-open');
			poll(true);
		} else {
			ui.panel.classList.remove('is-visible');
			setTimeout(function () { if (!S.open) ui.panel.hidden = true; }, 220);
			document.documentElement.classList.remove('hilo-chat-open');
			ui.launcher.focus({ preventScroll: true });
			schedule();
		}
		S.saved.open = open;
		store(false, S.saved);
		renderBadge();
	}

	root.addEventListener('keydown', function (e) {
		if (e.key === 'Escape' && S.open) { e.stopPropagation(); toggle(false); }
	});

	/* ------------------------------------------------------------------ *
	 * Networking
	 * ------------------------------------------------------------------ */
	function refreshStatus() {
		return api('GET', 'status').then(function (st) { S.status = st; render(); }).catch(function () {});
	}

	function applyConversation(conv) {
		S.conv = conv;
		if (conv && !S.saved.has) { S.saved.has = true; store(false, S.saved); }
	}

	function mergeMessages(list) {
		if (!list || !list.length) return false;
		var known = {};
		S.messages.forEach(function (m) { known[m.id] = true; });
		var added = false;
		list.forEach(function (m) {
			if (known[m.id]) return;
			// Replace an optimistic copy if present.
			var idx = -1;
			for (var i = 0; i < S.messages.length; i++) {
				if (S.messages[i].local && S.messages[i].from === m.from && S.messages[i].body === m.body && !S.messages[i].failed) { idx = i; break; }
			}
			if (idx > -1) { S.seen[m.id] = true; S.messages.splice(idx, 1, m); } else S.messages.push(m);
			if (m.id > S.lastId) S.lastId = m.id;
			added = true;
		});
		S.messages.sort(function (a, b) { return (a.local ? 1e15 : a.id) - (b.local ? 1e15 : b.id); });
		return added;
	}

	function poll(withStatus) {
		clearTimeout(S.pollTimer);
		var params = { after: S.lastId || '', open: S.open && !document.hidden ? 1 : '' };
		if (withStatus || !S.status) params.withStatus = 1;
		var now = Date.now();
		if (now - S.lastCtx > CTX_EVERY) {
			params.ctx = 1; params.page = location.href; params.title = document.title;
			if (document.referrer) params.ref = document.referrer;
		}
		return api('GET', 'visitor/poll', params).then(function (r) {
			if (params.ctx) S.lastCtx = now;
			if (r.status) S.status = r.status;
			if (!r.conversation) {
				if (S.saved.has) { S.saved.has = false; store(false, S.saved); }
				// The conversation no longer exists (e.g. deleted): start fresh.
				if (S.conv) { S.messages = []; S.lastId = 0; S.seen = {}; S.renderSig = ''; }
				S.conv = null;
				render();
				return;
			}
			applyConversation(r.conversation);
			var before = S.lastId;
			var added = mergeMessages(r.messages);
			S.agentTyping = r.typing;
			if (!S.open) {
				S.unread = r.unread || 0;
				if (added && before && hasNewAgentMessage(before)) notifyIncoming();
			} else if (added && document.hidden && hasNewAgentMessage(before)) {
				notifyIncoming();
			}
			render();
		}).catch(function () {}).then(schedule);
	}

	function hasNewAgentMessage(afterId) {
		return S.messages.some(function (m) { return m.id > afterId && m.from === 'agent'; });
	}

	function schedule() {
		clearTimeout(S.pollTimer);
		if (!S.conv && !S.open) return; // Nothing to watch.
		var delay = document.hidden ? POLL_HIDDEN : (S.open ? POLL_OPEN : POLL_CLOSED);
		S.pollTimer = setTimeout(function () { poll(false); }, delay);
	}

	document.addEventListener('visibilitychange', function () {
		if (!document.hidden && (S.conv || S.open)) { poll(false); restoreTitle(); }
	});

	/* ------------------------------------------------------------------ *
	 * Notifications (sound + tab title)
	 * ------------------------------------------------------------------ */
	var originalTitle = document.title, titleTimer = null, audioCtx = null;
	function notifyIncoming() {
		try {
			audioCtx = audioCtx || new (window.AudioContext || window.webkitAudioContext)();
			var o = audioCtx.createOscillator(), g = audioCtx.createGain();
			o.type = 'sine'; o.frequency.value = 880;
			g.gain.setValueAtTime(0.0001, audioCtx.currentTime);
			g.gain.exponentialRampToValueAtTime(0.08, audioCtx.currentTime + 0.02);
			g.gain.exponentialRampToValueAtTime(0.0001, audioCtx.currentTime + 0.35);
			o.connect(g); g.connect(audioCtx.destination); o.start(); o.stop(audioCtx.currentTime + 0.4);
		} catch (e) {}
		if (document.hidden && !titleTimer) {
			originalTitle = document.title;
			var flip = false;
			titleTimer = setInterval(function () { flip = !flip; document.title = flip ? '💬 ' + __('New message', 'hilo-chat') : originalTitle; }, 1200);
		}
	}
	function restoreTitle() {
		if (titleTimer) { clearInterval(titleTimer); titleTimer = null; document.title = originalTitle; }
	}

	/* ------------------------------------------------------------------ *
	 * Intro submit (starts the conversation)
	 * ------------------------------------------------------------------ */
	function onIntroSubmit(e) {
		e.preventDefault();
		if (S.sending) return;
		var msg = ui.introMsg.value.trim();
		var name = (CFG.user ? CFG.user.name : ui.introName.value).trim();
		var email = (CFG.user ? CFG.user.email : ui.introEmail.value).trim();
		ui.introError.textContent = '';
		if (!msg) { ui.introError.textContent = __('Please write your message.', 'hilo-chat'); ui.introMsg.focus(); return; }
		if (!name) { ui.introError.textContent = __('Please enter your name.', 'hilo-chat'); ui.introName.focus(); return; }
		if (!/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(email)) { ui.introError.textContent = __('Please enter a valid email so we can reply.', 'hilo-chat'); ui.introEmail.focus(); return; }

		S.sending = true;
		ui.introBtn.disabled = true;
		api('POST', 'visitor/start', {
			message: msg, name: name, email: email, website: ui.hp.value,
			page: location.href, title: document.title, ref: document.referrer
		}).then(function (r) {
			S.saved.name = name; S.saved.email = email;
			applyConversation(r.conversation);
			if (r.status) S.status = r.status;
			S.lastCtx = Date.now();
			mergeMessages(r.messages);
			ui.introMsg.value = '';
			if (S.status && !S.status.online) {
				S.messages.push({ id: (S.lastId || 0) + 0.5, local: false, from: 'system', body: fmt(__('We got your message. We will reply to %s and you will also see it here.', 'hilo-chat'), email), at: Date.now() / 1000 });
			}
			S.forceScroll = true;
			render();
			ui.text.focus({ preventScroll: true });
			schedule();
		}).catch(function (err) {
			ui.introError.textContent = err.message || __('We could not send your message. Please try again.', 'hilo-chat');
		}).then(function () { S.sending = false; ui.introBtn.disabled = false; });
	}

	/* ------------------------------------------------------------------ *
	 * Composer
	 * ------------------------------------------------------------------ */
	var lastTypingPing = 0;
	function autosize() {
		ui.text.style.height = 'auto';
		ui.text.style.height = Math.min(ui.text.scrollHeight, 120) + 'px';
	}
	function onComposeInput() {
		autosize();
		var now = Date.now();
		if (ui.text.value.trim() && now - lastTypingPing > 3000) {
			lastTypingPing = now;
			api('POST', 'visitor/typing', {}).catch(function () {});
		}
	}
	function onComposeKey(e) {
		if (e.key === 'Enter' && !e.shiftKey && !e.isComposing && window.matchMedia('(pointer:fine)').matches) {
			e.preventDefault();
			onComposeSubmit(e);
		}
	}
	function onComposeSubmit(e) {
		e.preventDefault();
		var body = ui.text.value.trim();
		if (!body) return;
		ui.text.value = '';
		autosize();
		ui.composeError.textContent = '';
		var local = { id: 'l' + Date.now(), local: true, from: 'visitor', body: body, at: Date.now() / 1000 };
		S.messages.push(local);
		S.forceScroll = true;
		renderMessages();
		lastTypingPing = 0;
		api('POST', 'visitor/message', { body: body }).then(function (r) {
			mergeMessages([r.message]);
			renderMessages();
		}).catch(function (err) {
			local.failed = true;
			ui.composeError.textContent = err.message || __('Could not send.', 'hilo-chat');
			ui.text.value = body;
			S.messages = S.messages.filter(function (m) { return m !== local; });
			renderMessages();
		});
	}

	/** Shrinks large photos client-side before upload. */
	function prepareFile(file) {
		return new Promise(function (resolve) {
			if (!/^image\/(jpeg|png|webp)$/.test(file.type) || file.size < 600 * 1024 || !window.createImageBitmap) return resolve(file);
			createImageBitmap(file).then(function (bmp) {
				var max = 1600, scale = Math.min(1, max / Math.max(bmp.width, bmp.height));
				var c = document.createElement('canvas');
				c.width = Math.round(bmp.width * scale); c.height = Math.round(bmp.height * scale);
				c.getContext('2d').drawImage(bmp, 0, 0, c.width, c.height);
				c.toBlob(function (blob) {
					if (!blob) return resolve(file);
					resolve(new File([blob], file.name.replace(/\.\w+$/, '') + '.jpg', { type: 'image/jpeg' }));
				}, 'image/jpeg', 0.85);
			}).catch(function () { resolve(file); });
		});
	}

	function onFile() {
		var file = ui.file.files && ui.file.files[0];
		ui.file.value = '';
		if (!file) return;
		ui.composeError.textContent = '';
		if (file.size > 15 * 1024 * 1024) { ui.composeError.textContent = __('The file is too large.', 'hilo-chat'); return; }
		var isImg = /^image\//.test(file.type);
		var preview = isImg ? URL.createObjectURL(file) : '';
		var local = { id: 'l' + Date.now(), local: true, from: 'visitor', body: '', at: Date.now() / 1000, attachment: { image: isImg, url: preview, name: file.name } };
		S.messages.push(local);
		S.forceScroll = true;
		renderMessages();
		prepareFile(file).then(function (f) {
			var fd = new FormData();
			fd.append('file', f, f.name);
			return api('POST', 'visitor/upload', fd, true);
		}).then(function (r) {
			S.messages = S.messages.filter(function (m) { return m !== local; });
			S.seen[r.message.id] = true;
			mergeMessages([r.message]);
			renderMessages();
		}).catch(function (err) {
			S.messages = S.messages.filter(function (m) { return m !== local; });
			ui.composeError.textContent = err.message || __('The file could not be uploaded.', 'hilo-chat');
			renderMessages();
		}).then(function () { if (preview) setTimeout(function () { URL.revokeObjectURL(preview); }, 5000); });
	}

	/* ------------------------------------------------------------------ *
	 * Boot
	 * ------------------------------------------------------------------ */
	/* ------------------------------------------------------------------ *
	 * Greeting teaser (once per visit)
	 * ------------------------------------------------------------------ */
	var teaserTimer = null, teaserHideTimer = null;
	function sessionFlag(key, val) {
		try {
			if (val === undefined) return sessionStorage.getItem(key);
			sessionStorage.setItem(key, val);
		} catch (e) { return '1'; }
	}
	function scheduleTeaser() {
		var t = CFG.teaser;
		if (!t || S.conv || S.saved.has || S.open) return;
		if (sessionFlag('hilo_teaser')) return;
		if (S.saved.teaserOff && Date.now() - S.saved.teaserOff < 3 * 864e5) return;
		var wait = Math.max(2, t.delay || 8) * 1000;
		teaserTimer = setTimeout(function tryShow() {
			if (document.hidden) { document.addEventListener('visibilitychange', function once() { document.removeEventListener('visibilitychange', once); teaserTimer = setTimeout(tryShow, 1500); }); return; }
			if (S.open || S.conv) return;
			showTeaser();
		}, wait);
	}
	function showTeaser() {
		sessionFlag('hilo_teaser', '1');
		ui.teaserText.textContent = CFG.teaser.text;
		ui.teaser.hidden = false;
		requestAnimationFrame(function () { requestAnimationFrame(function () { ui.teaser.classList.add('is-visible'); ui.launcher.classList.add('is-calling'); }); });
		teaserHideTimer = setTimeout(hideTeaser, 15000);
	}
	function hideTeaser() {
		clearTimeout(teaserTimer);
		clearTimeout(teaserHideTimer);
		ui.launcher.classList.remove('is-calling');
		if (ui.teaser.hidden) return;
		ui.teaser.classList.remove('is-visible');
		setTimeout(function () { ui.teaser.hidden = true; }, 300);
	}
	function dismissTeaser() {
		S.saved.teaserOff = Date.now();
		store(false, S.saved);
		hideTeaser();
	}

	function boot() {
		render();
		var wantsOpen = location.hash === '#hilo-open';
		if (wantsOpen) {
			history.replaceState(null, '', location.pathname + location.search);
		}
		// Only poll on page load if this browser already has a conversation (or a logged-in user might).
		if (S.saved.has || CFG.user || wantsOpen) {
			poll(true).then(function () {
				if (wantsOpen || (S.saved.open && S.conv)) toggle(true);
				else scheduleTeaser();
			});
		} else {
			scheduleTeaser();
		}
		window.addEventListener('hashchange', function () {
			if (location.hash === '#hilo-open') { history.replaceState(null, '', location.pathname + location.search); toggle(true); }
		});
		// Public API: HiloChat.open() for custom buttons ("¿Necesitas ayuda?").
		window.HiloChat = { open: function () { toggle(true); }, close: function () { toggle(false); }, toggle: function () { toggle(); } };
		document.addEventListener('click', function (e) {
			var trigger = e.target && e.target.closest && e.target.closest('[data-hilo-chat-open], a[href="#hilo-open"]');
			if (trigger) { e.preventDefault(); toggle(true); }
		});
	}
})();

/*! Hilo Chat — agent app */
(function () {
	'use strict';

	var CFG = window.HiloAgentConfig;
	var SYNC_VISIBLE = 4000, SYNC_HIDDEN = 20000, META_EVERY = 30000;

	/* i18n: strings are English; translations come from PHP (CFG.i18n). */
	var I18N = CFG.i18n || {};
	var LOCALE = CFG.locale || undefined;
	function __(s) { return Object.prototype.hasOwnProperty.call(I18N, s) ? I18N[s] : s; }
	function fmt(s) {
		var args = arguments, i = 0;
		return String(s).replace(/%(\d+)\$[sd]|%[sd]/g, function (m, pos) { return String(pos ? args[Number(pos)] : args[++i]); });
	}

	/* ================================================================== *
	 * Helpers
	 * ================================================================== */
	function $(sel, ctx) { return (ctx || document).querySelector(sel); }
	function el(tag, attrs, children) {
		var n = document.createElement(tag);
		if (attrs) for (var k in attrs) {
			if (!Object.prototype.hasOwnProperty.call(attrs, k)) continue;
			var v = attrs[k];
			if (v == null || v === false) continue;
			if (k === 'class') n.className = v;
			else if (k === 'text') n.textContent = v;
			else if (k === 'html') n.innerHTML = v; // static SVG only
			else if (k === 'dataset') for (var d in v) n.dataset[d] = v[d];
			else if (k.indexOf('on') === 0 && typeof v === 'function') n.addEventListener(k.slice(2), v);
			else n.setAttribute(k, v === true ? '' : v);
		}
		(children || []).forEach(function (c) {
			if (c == null || c === false) return;
			n.appendChild(typeof c === 'string' || typeof c === 'number' ? document.createTextNode(String(c)) : c);
		});
		return n;
	}
	function richText(text) {
		var frag = document.createDocumentFragment();
		var re = /(https?:\/\/[^\s<]+[^\s<.,;:!?)\]'"])/g, last = 0, m;
		text = String(text || '');
		while ((m = re.exec(text))) {
			if (m.index > last) frag.appendChild(document.createTextNode(text.slice(last, m.index)));
			frag.appendChild(el('a', { href: m[0], target: '_blank', rel: 'noopener noreferrer', text: m[0] }));
			last = m.index + m[0].length;
		}
		if (last < text.length) frag.appendChild(document.createTextNode(text.slice(last)));
		return frag;
	}
	function initials(name) {
		var p = String(name || '?').trim().split(/\s+/);
		return ((p[0] || '?').charAt(0) + (p.length > 1 ? p[p.length - 1].charAt(0) : '')).toUpperCase();
	}
	function hue(str) {
		var h = 0; str = String(str || '');
		for (var i = 0; i < str.length; i++) h = (h * 31 + str.charCodeAt(i)) % 360;
		return h;
	}
	function avatar(name, url, cls) {
		var a = el('span', { class: 'avatar ' + (cls || ''), 'aria-hidden': 'true' });
		if (url) a.appendChild(el('img', { src: url, alt: '', loading: 'lazy' }));
		else { a.textContent = initials(name); a.style.setProperty('--h', hue(name)); }
		return a;
	}
	function nowSec() { return Math.floor(Date.now() / 1000) + (S.clockSkew || 0); }
	function relTime(ts) {
		if (!ts) return '';
		var diff = nowSec() - ts;
		if (diff < 45) return __('now', 'hilo-chat');
		if (diff < 3600) return fmt(__('%d min', 'hilo-chat'), Math.floor(diff / 60));
		var d = new Date(ts * 1000), today = new Date();
		if (d.toDateString() === today.toDateString()) return d.toLocaleTimeString(LOCALE, { hour: '2-digit', minute: '2-digit' });
		var y = new Date(); y.setDate(y.getDate() - 1);
		if (d.toDateString() === y.toDateString()) return __('yesterday', 'hilo-chat');
		if (diff < 6 * 86400) return d.toLocaleDateString(LOCALE, { weekday: 'short' });
		return d.toLocaleDateString(LOCALE, { day: 'numeric', month: 'short' });
	}
	function clock(ts) { return new Date(ts * 1000).toLocaleTimeString(LOCALE, { hour: '2-digit', minute: '2-digit' }); }
	function dayLabel(ts) {
		var d = new Date(ts * 1000), today = new Date(), y = new Date(); y.setDate(y.getDate() - 1);
		if (d.toDateString() === today.toDateString()) return __('Today', 'hilo-chat');
		if (d.toDateString() === y.toDateString()) return __('Yesterday', 'hilo-chat');
		return d.toLocaleDateString(LOCALE, { weekday: 'long', day: 'numeric', month: 'long' });
	}
	function seenLabel(ts) {
		if (!ts) return __('No recent activity', 'hilo-chat');
		var diff = nowSec() - ts;
		if (diff < 40) return __('In the store now', 'hilo-chat');
		if (diff < 3600) return fmt(__('Seen %d min ago', 'hilo-chat'), Math.max(1, Math.floor(diff / 60)));
		return fmt(__('Seen %s', 'hilo-chat'), relTime(ts));
	}
	function isMobile() { return window.matchMedia('(max-width: 759px)').matches; }

	var ICON = {
		back: '<svg viewBox="0 0 24 24"><path d="M15 5l-7 7 7 7" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
		send: '<svg viewBox="0 0 24 24"><path d="M4.4 11.2 18.7 4.6c.8-.4 1.6.4 1.2 1.2l-6.6 14.3c-.4.8-1.5.7-1.7-.1l-1.3-5.2a1 1 0 0 0-.7-.7l-5.2-1.3c-.8-.2-.9-1.3-.1-1.7z" fill="currentColor"/></svg>',
		clip: '<svg viewBox="0 0 24 24"><path d="M20 11.5 12.4 19a5 5 0 0 1-7.1-7.1l7.8-7.8a3.3 3.3 0 0 1 4.7 4.7l-7.6 7.6a1.7 1.7 0 0 1-2.4-2.4l7-7" stroke="currentColor" stroke-width="1.8" fill="none" stroke-linecap="round" stroke-linejoin="round"/></svg>',
		bolt: '<svg viewBox="0 0 24 24"><path d="M13 3 5 13.5h6L10 21l8-10.5h-6z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>',
		info: '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M12 11v5M12 8h.01" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>',
		check: '<svg viewBox="0 0 24 24"><path d="m5 12.5 4.5 4.5L19 7.5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
		more: '<svg viewBox="0 0 24 24"><circle cx="5" cy="12" r="1.8" fill="currentColor"/><circle cx="12" cy="12" r="1.8" fill="currentColor"/><circle cx="19" cy="12" r="1.8" fill="currentColor"/></svg>',
		bell: '<svg viewBox="0 0 24 24"><path d="M6 16V11a6 6 0 1 1 12 0v5l1.5 2h-15zM10 20a2 2 0 0 0 4 0" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>',
		search: '<svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="6.5" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="m16 16 4 4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>',
		close: '<svg viewBox="0 0 24 24"><path d="M6 6l12 12M18 6L6 18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>',
		file: '<svg viewBox="0 0 24 24"><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8zM14 3v5h5" stroke="currentColor" stroke-width="1.8" fill="none" stroke-linejoin="round"/></svg>',
		chat: '<svg viewBox="0 0 24 24"><path d="M4 5.5A2.5 2.5 0 0 1 6.5 3h11A2.5 2.5 0 0 1 20 5.5v8a2.5 2.5 0 0 1-2.5 2.5H10l-4.2 3.6c-.5.4-1.3.1-1.3-.6V16A2.5 2.5 0 0 1 4 13.5z" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/></svg>',
		trash: '<svg viewBox="0 0 24 24"><path d="M4 7h16M10 11v6M14 11v6M6 7l1 12a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2l1-12M9 7V4h6v3" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>',
		ext: '<svg viewBox="0 0 24 24"><path d="M14 4h6v6M20 4l-9 9M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>'
	};
	function icon(name, cls) { return el('span', { class: 'ico ' + (cls || ''), html: ICON[name], 'aria-hidden': 'true' }); }

	/* ================================================================== *
	 * API
	 * ================================================================== */
	var nonce = CFG.nonce;
	function refreshNonce() {
		return fetch(CFG.ajax + '?action=hilo_refresh_nonce', { credentials: 'same-origin' })
			.then(function (r) { if (r.status === 403 || r.status === 400) { location.href = CFG.appUrl; } return r.json(); })
			.then(function (j) { if (j && j.success) nonce = j.data.nonce; });
	}
	function api(method, path, data, isForm, retried) {
		var url = CFG.rest + path + (path.indexOf('?') > -1 ? '&' : '?') + '_locale=user', opts = { method: method, credentials: 'same-origin', headers: { 'X-WP-Nonce': nonce } };
		if (method === 'GET' && data) {
			var q = Object.keys(data).filter(function (k) { return data[k] !== '' && data[k] != null; })
				.map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(data[k]); }).join('&');
			if (q) url += '&' + q;
		} else if (data) {
			if (isForm) opts.body = data;
			else { opts.headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(data); }
		}
		return fetch(url, opts).then(function (r) {
			return r.json().catch(function () { return {}; }).then(function (j) {
				if (r.status === 403 && j && j.code === 'rest_cookie_invalid_nonce' && !retried) {
					return refreshNonce().then(function () { return api(method, path, data, isForm, true); });
				}
				if (r.status === 401 || (r.status === 403 && j && j.code === 'rest_forbidden')) {
					// Session expired: back to login.
					location.href = CFG.appUrl;
				}
				if (!r.ok) { var e = new Error(j && j.message ? j.message : __('Connection error', 'hilo-chat')); e.status = r.status; throw e; }
				return j;
			});
		});
	}

	/* ================================================================== *
	 * State
	 * ================================================================== */
	var S = {
		convs: {},              // id => conversation
		since: 0,
		lastMeta: 0,
		filter: localStorage.getItem('hilo_filter') || 'mine',
		search: '',
		openId: 0,
		thread: { id: 0, messages: [], lastId: 0, typing: false, visitorSeen: 0, visitorRead: 0 },
		details: {},            // id => {context, customer}
		agents: [],
		me: { id: CFG.me.id, available: true },
		store: null,
		quickReplies: null,
		showInfo: localStorage.getItem('hilo_info') !== '0',
		syncTimer: null,
		syncing: false,
		online: navigator.onLine,
		clockSkew: 0,
		installPrompt: null,
		sound: localStorage.getItem('hilo_sound') !== '0',
		knownLastIds: {}
	};

	/* ================================================================== *
	 * Layout skeleton
	 * ================================================================== */
	var app = $('#app');
	var R = {}; // references

	function buildLayout() {
		app.textContent = '';

		// ----- Sidebar -----
		R.availBtn = el('button', { class: 'avail', type: 'button', onclick: toggleAvailability, 'aria-pressed': 'true' }, [el('span', { class: 'avail-dot' }), el('span', { class: 'avail-text', text: __('Available', 'hilo-chat') })]);
		R.menuBtn = el('button', { class: 'icon-btn', type: 'button', 'aria-label': __('Menu', 'hilo-chat'), onclick: openMenu }, [icon('more')]);
		R.netBanner = el('div', { class: 'net-banner', hidden: true, text: __('Offline. Retrying…', 'hilo-chat') });
		R.notifBanner = el('div', { class: 'notif-banner', hidden: true });
		R.tabs = el('div', { class: 'tabs', role: 'tablist' });
		R.searchInput = el('input', { type: 'search', class: 'search-input', placeholder: __('Search by name or email', 'hilo-chat'), 'aria-label': __('Search', 'hilo-chat'), oninput: function (e) { S.search = e.target.value.trim().toLowerCase(); renderList(); } });
		R.list = el('div', { class: 'conv-list', role: 'list' });

		R.sidebar = el('aside', { class: 'sidebar' }, [
			el('header', { class: 'side-head' }, [
				avatar(CFG.me.name, CFG.me.avatar, 'me'),
				el('div', { class: 'side-title' }, [el('strong', { text: CFG.brand }), R.availBtn]),
				R.menuBtn
			]),
			R.netBanner,
			R.notifBanner,
			el('div', { class: 'search' }, [icon('search'), R.searchInput]),
			R.tabs,
			R.list
		]);

		// ----- Thread -----
		R.thread = el('main', { class: 'thread' });
		// ----- Info -----
		R.info = el('aside', { class: 'info', 'aria-label': __('Customer details', 'hilo-chat') });

		app.appendChild(R.sidebar);
		app.appendChild(R.thread);
		app.appendChild(R.info);
		renderEmptyThread();
	}

	/* ================================================================== *
	 * Sidebar: tabs + list
	 * ================================================================== */
	var FILTERS = [
		{ key: 'mine', label: __('Mine', 'hilo-chat') },
		{ key: 'unassigned', label: __('Unassigned', 'hilo-chat') },
		{ key: 'all', label: __('Open', 'hilo-chat') },
		{ key: 'closed', label: __('Resolved', 'hilo-chat') }
	];
	function matchesFilter(c, f) {
		if (f === 'closed') return c.status === 'closed';
		if (c.status === 'closed') return false;
		if (f === 'mine') return c.assignedTo === S.me.id;
		if (f === 'unassigned') return !c.assignedTo;
		return true;
	}
	function sortedConvs() {
		return Object.keys(S.convs).map(function (k) { return S.convs[k]; })
			.sort(function (a, b) { return b.updatedAt - a.updatedAt || b.id - a.id; });
	}
	function renderTabs() {
		var all = sortedConvs();
		R.tabs.textContent = '';
		FILTERS.forEach(function (f) {
			var unread = 0, count = 0;
			all.forEach(function (c) { if (matchesFilter(c, f.key)) { count++; unread += c.unread; } });
			var badge = f.key === 'closed' ? null : (unread ? el('span', { class: 'tab-badge', text: unread > 99 ? '99+' : unread }) : (count ? el('span', { class: 'tab-count', text: count }) : null));
			R.tabs.appendChild(el('button', {
				class: 'tab' + (S.filter === f.key ? ' is-active' : ''), role: 'tab', type: 'button',
				'aria-selected': String(S.filter === f.key),
				onclick: function () { S.filter = f.key; localStorage.setItem('hilo_filter', f.key); renderTabs(); renderList(); }
			}, [f.label, badge]));
		});
	}
	function agentName(id) {
		if (!id) return '';
		if (id === S.me.id) return __('You', 'hilo-chat');
		for (var i = 0; i < S.agents.length; i++) if (S.agents[i].id === id) return S.agents[i].name;
		return __('Another agent', 'hilo-chat');
	}
	function renderList() {
		var list = sortedConvs().filter(function (c) {
			if (!matchesFilter(c, S.filter)) return false;
			if (!S.search) return true;
			return (c.name + ' ' + c.email).toLowerCase().indexOf(S.search) > -1;
		});
		R.list.textContent = '';
		if (S.filter === 'closed' && list.length && CFG.canPurge) {
			R.list.appendChild(el('div', { class: 'list-tools' }, [
				el('button', { class: 'btn btn-ghost btn-sm danger-text', type: 'button', onclick: deleteAllResolved }, [icon('trash'), el('span', { text: __('Delete all resolved', 'hilo-chat') })])
			]));
		}
		if (!list.length) {
			var msg = { mine: __('You have no assigned chats.', 'hilo-chat'), unassigned: __('No chats waiting. ✨', 'hilo-chat'), all: __('No open chats.', 'hilo-chat'), closed: __('No recently resolved chats.', 'hilo-chat') }[S.filter];
			R.list.appendChild(el('div', { class: 'list-empty' }, [icon('chat', 'big'), el('p', { text: S.search ? __('No results.', 'hilo-chat') : msg })]));
			return;
		}
		list.forEach(function (c) {
			var online = c.visitorSeen && nowSec() - c.visitorSeen < 40;
			var preview = c.lastSender === 'agent' ? fmt(__('You: %s', 'hilo-chat'), c.lastBody || '') : (c.lastBody || '');
			var chips = [];
			if (!c.assignedTo && c.status !== 'closed') chips.push(el('span', { class: 'chip chip-warn', text: __('Unassigned', 'hilo-chat') }));
			else if (c.assignedTo && c.assignedTo !== S.me.id && S.filter !== 'mine') chips.push(el('span', { class: 'chip', text: agentName(c.assignedTo) }));
			var item = el('button', {
				class: 'conv' + (c.id === S.openId ? ' is-active' : '') + (c.unread ? ' is-unread' : ''), type: 'button', role: 'listitem',
				onclick: function () { openConversation(c.id); }
			}, [
				el('span', { class: 'conv-avatar' }, [avatar(c.name), online ? el('span', { class: 'presence', title: __('In the store now', 'hilo-chat') }) : null]),
				el('span', { class: 'conv-body' }, [
					el('span', { class: 'conv-top' }, [el('span', { class: 'conv-name', text: c.name }), el('span', { class: 'conv-time', text: relTime(c.updatedAt) })]),
					el('span', { class: 'conv-bottom' }, [
						el('span', { class: 'conv-preview', text: preview }),
						c.unread ? el('span', { class: 'unread', text: c.unread > 9 ? '9+' : c.unread }) : null
					]),
					chips.length ? el('span', { class: 'conv-chips' }, chips) : null
				])
			]);
			R.list.appendChild(item);
		});
	}

	/* ================================================================== *
	 * Thread
	 * ================================================================== */
	function renderEmptyThread() {
		R.thread.textContent = '';
		R.thread.appendChild(el('div', { class: 'thread-empty' }, [
			el('div', { class: 'empty-art' }, [icon('chat', 'huge')]),
			el('h2', { text: __('Choose a conversation', 'hilo-chat') }),
			el('p', { text: __('New chats show up in “Unassigned”. Reply to one to take it.', 'hilo-chat') })
		]));
		R.info.textContent = '';
	}

	function openConversation(id, opts) {
		opts = opts || {};
		id = Number(id);
		if (!S.convs[id] && !opts.force) {
			// Might not be loaded yet (e.g. from a notification): fetch it.
			return loadDetails(id).then(function () { if (S.convs[id]) openConversation(id); });
		}
		S.openId = id;
		S.thread = { id: id, messages: [], lastId: 0, typing: false, visitorSeen: 0, visitorRead: 0 };
		if (location.hash !== '#c' + id) history.replaceState(null, '', '#c' + id);
		app.dataset.view = 'thread';
		buildThread();
		renderList();
		loadDetails(id).then(function () {
			if (S.openId !== id) return;
			api('POST', 'agent/read', { conversation: id }).catch(function () {});
			if (S.convs[id]) { S.convs[id].unread = 0; renderTabs(); renderList(); updateAppBadge(); }
		});
		if (!isMobile()) setTimeout(function () { R.text && R.text.focus({ preventScroll: true }); }, 50);
	}

	function closeThreadMobile() {
		app.dataset.view = 'list';
		S.openId = 0;
		history.replaceState(null, '', location.pathname);
		renderList();
	}

	function loadDetails(id) {
		return api('GET', 'agent/conversation/' + id).then(function (r) {
			S.convs[id] = Object.assign(S.convs[id] || {}, r.conversation);
			S.details[id] = { context: r.context, customer: r.customer };
			if (S.openId === id) {
				S.thread.messages = r.messages;
				S.thread.lastId = r.messages.length ? r.messages[r.messages.length - 1].id : 0;
				S.thread.visitorRead = r.conversation.visitorReadId;
				S.thread.visitorSeen = r.conversation.visitorSeen;
				renderThreadHeader();
				renderMessages(true);
				renderInfo();
			}
		}).catch(function (e) { toast(e.message); });
	}

	function buildThread() {
		var c = S.convs[S.openId];
		R.thread.textContent = '';
		R.threadHead = el('header', { class: 'thread-head' });
		R.assignBar = el('div', { class: 'assign-bar', hidden: true });
		R.messages = el('div', { class: 'messages', role: 'log', 'aria-live': 'polite' });
		R.typing = el('div', { class: 'typing', hidden: true }, [el('span'), el('span'), el('span')]);
		R.scroller = el('div', { class: 'scroller' }, [R.messages, R.typing]);
		R.qr = el('div', { class: 'qr-pop', hidden: true, role: 'listbox' });
		R.file = el('input', { type: 'file', accept: 'image/*,application/pdf', hidden: true, onchange: onAgentFile });
		R.text = el('textarea', { class: 'compose-input', rows: '1', placeholder: isMobile() ? __('Write a reply…', 'hilo-chat') : __('Write a reply…  ( / for quick replies)', 'hilo-chat'), 'aria-label': __('Reply', 'hilo-chat'), oninput: onComposeInput, onkeydown: onComposeKey, maxlength: '2000' });
		R.composer = el('form', { class: 'composer', onsubmit: onSend }, [
			R.qr,
			el('button', { class: 'icon-btn', type: 'button', 'aria-label': __('Quick replies', 'hilo-chat'), title: __('Quick replies', 'hilo-chat'), onclick: function () { toggleQuickReplies(true, ''); } }, [icon('bolt')]),
			el('button', { class: 'icon-btn', type: 'button', 'aria-label': __('Attach file', 'hilo-chat'), title: __('Attach', 'hilo-chat'), onclick: function () { R.file.click(); } }, [icon('clip')]),
			R.file,
			R.text,
			el('button', { class: 'send-btn', type: 'submit', 'aria-label': __('Send', 'hilo-chat') }, [icon('send')])
		]);
		R.thread.appendChild(R.threadHead);
		R.thread.appendChild(R.assignBar);
		R.thread.appendChild(R.scroller);
		R.thread.appendChild(R.composer);
		renderThreadHeader();
		renderMessages(true);
		renderInfo();
		if (c) R.messages.appendChild(el('div', { class: 'loading-row', text: __('Loading…', 'hilo-chat') }));
	}

	function renderThreadHeader() {
		var c = S.convs[S.openId];
		if (!c || !R.threadHead) return;
		var seen = S.thread.visitorSeen || c.visitorSeen;
		var online = seen && nowSec() - seen < 40;
		R.threadHead.textContent = '';
		R.threadHead.appendChild(el('button', { class: 'icon-btn back-btn', type: 'button', 'aria-label': __('Back', 'hilo-chat'), onclick: closeThreadMobile }, [icon('back')]));
		R.threadHead.appendChild(el('button', { class: 'head-person', type: 'button', onclick: function () { toggleInfo(); } }, [
			el('span', { class: 'conv-avatar' }, [avatar(c.name), online ? el('span', { class: 'presence' }) : null]),
			el('span', { class: 'head-text' }, [
				el('strong', { text: c.name }),
				el('small', { class: online ? 'is-online' : '', text: S.thread.typing ? __('Typing…', 'hilo-chat') : seenLabel(seen) })
			])
		]));

		var actions = el('div', { class: 'head-actions' });
		if (c.status === 'closed') {
			actions.appendChild(el('button', { class: 'btn btn-ghost', type: 'button', onclick: function () { act('reopen'); } }, [__('Reopen', 'hilo-chat')]));
			actions.appendChild(el('button', { class: 'icon-btn danger', type: 'button', 'aria-label': __('Delete conversation', 'hilo-chat'), title: __('Delete conversation', 'hilo-chat'), onclick: function () { deleteConversation(c.id); } }, [icon('trash')]));
		} else {
			if (c.assignedTo !== S.me.id) {
				actions.appendChild(el('button', { class: 'btn btn-primary', type: 'button', onclick: function () { assign(S.me.id); } }, [el('span', { class: 'btn-long', text: __('Take chat', 'hilo-chat') }), el('span', { class: 'btn-short', text: __('Take', 'hilo-chat') })]));
			}
			actions.appendChild(el('button', { class: 'btn btn-ghost btn-resolve', type: 'button', title: __('Mark as resolved', 'hilo-chat'), onclick: function () { act('close'); } }, [icon('check'), el('span', { class: 'btn-label', text: __('Resolve', 'hilo-chat') })]));
		}
		actions.appendChild(el('button', { class: 'icon-btn', type: 'button', 'aria-label': __('More options', 'hilo-chat'), onclick: openThreadMenu }, [icon('more')]));
		actions.appendChild(el('button', { class: 'icon-btn info-btn' + (S.showInfo ? ' is-active' : ''), type: 'button', 'aria-label': __('Customer details', 'hilo-chat'), onclick: function () { toggleInfo(); } }, [icon('info')]));
		R.threadHead.appendChild(actions);

		// Assignment banner.
		R.assignBar.textContent = '';
		if (c.status !== 'closed' && !c.assignedTo) {
			R.assignBar.hidden = false;
			R.assignBar.appendChild(el('span', { text: __('This chat has no agent yet. If you reply, it will be assigned to you.', 'hilo-chat') }));
		} else if (c.status !== 'closed' && c.assignedTo !== S.me.id) {
			R.assignBar.hidden = false;
			R.assignBar.appendChild(el('span', { text: fmt(__('%s is handling this chat.', 'hilo-chat'), agentName(c.assignedTo)) }));
		} else {
			R.assignBar.hidden = true;
		}
	}

	function messageNode(m) {
		if (m.from === 'system') return el('div', { class: 'sys', dataset: { id: m.id } }, [m.body]);
		var mine = m.from === 'agent';
		var bubble = el('div', { class: 'bubble' });
		if (m.attachment) {
			var a = m.attachment;
			if (a.image) {
				var img = el('img', { src: a.url, alt: a.name || __('Image', 'hilo-chat'), loading: 'lazy' });
				if (a.w && a.h) { img.width = a.w; img.height = a.h; }
				bubble.appendChild(el('a', { class: 'att-img', href: a.url, target: '_blank', rel: 'noopener' }, [img]));
			} else {
				bubble.appendChild(el('a', { class: 'att-file', href: a.url, target: '_blank', rel: 'noopener' }, [icon('file'), el('span', { text: a.name || __('File', 'hilo-chat') })]));
			}
		}
		if (m.body) bubble.appendChild(el('div', { class: 'text' }, [richText(m.body)]));
		var meta = [m.local ? (m.failed ? __('Not sent', 'hilo-chat') : __('Sending…', 'hilo-chat')) : clock(m.at)];
		if (mine && m.agent && m.agent.name && !m.local) meta.unshift(m.agent.name + ' · ');
		return el('div', { class: 'msg ' + (mine ? 'out' : 'in') + (m.local ? ' is-local' : ''), dataset: { id: m.id } }, [bubble, el('div', { class: 'meta' }, meta)]);
	}

	function renderMessages(forceBottom) {
		if (!R.messages) return;
		var T = S.thread;
		var sig = T.id + ':' + T.messages.map(function (m) { return m.id + (m.local ? 'l' : ''); }).join(',') + '|' + T.visitorRead + '|' + (T.typing ? 1 : 0);
		if (sig === T.renderSig && !forceBottom) return;
		T.renderSig = sig;
		T.seen = T.seen || {};
		var sc = R.scroller;
		var near = sc.scrollHeight - sc.scrollTop - sc.clientHeight < 120;
		R.messages.textContent = '';
		var lastDay = '', lastOutId = 0;
		S.thread.messages.forEach(function (m) {
			var dl = dayLabel(m.at);
			if (dl !== lastDay) { R.messages.appendChild(el('div', { class: 'day', text: dl })); lastDay = dl; }
			var node = messageNode(m);
			if (T.seenReady && !T.seen[m.id]) node.classList.add('is-new');
			T.seen[m.id] = true;
			R.messages.appendChild(node);
			if (m.from === 'agent' && !m.local) lastOutId = m.id;
		});
		if (lastOutId && S.thread.visitorRead >= lastOutId) {
			var n = R.messages.querySelector('[data-id="' + lastOutId + '"] .meta');
			if (n) n.appendChild(el('span', { class: 'seen', text: ' · ' + __('Seen', 'hilo-chat') }));
		}
		if (T.messages.length) T.seenReady = true;
		R.typing.hidden = !S.thread.typing;
		if (forceBottom || near) requestAnimationFrame(function () { sc.scrollTop = sc.scrollHeight; });
	}

	function mergeThread(list) {
		if (!list || !list.length) return false;
		var known = {};
		S.thread.messages.forEach(function (m) { known[m.id] = true; });
		var added = false;
		list.forEach(function (m) {
			if (known[m.id]) return;
			var idx = -1;
			for (var i = 0; i < S.thread.messages.length; i++) {
				var x = S.thread.messages[i];
				if (x.local && x.from === m.from && x.body === m.body) { idx = i; break; }
			}
			if (idx > -1) { (S.thread.seen = S.thread.seen || {})[m.id] = true; S.thread.messages.splice(idx, 1, m); } else S.thread.messages.push(m);
			if (m.id > S.thread.lastId) S.thread.lastId = m.id;
			added = true;
		});
		S.thread.messages.sort(function (a, b) { return (a.local ? 1e15 : a.id) - (b.local ? 1e15 : b.id); });
		return added;
	}

	/* ---------- Composer ---------- */
	var lastTyping = 0;
	function autosize() { R.text.style.height = 'auto'; R.text.style.height = Math.min(R.text.scrollHeight, 180) + 'px'; }
	function onComposeInput() {
		autosize();
		var v = R.text.value;
		var m = /(^|\s)\/([\w-]*)$/.exec(v.slice(0, R.text.selectionStart));
		if (m) toggleQuickReplies(true, m[2]); else toggleQuickReplies(false);
		var now = Date.now();
		if (v.trim() && now - lastTyping > 3000 && S.openId) {
			lastTyping = now;
			api('POST', 'agent/typing', { conversation: S.openId }).catch(function () {});
		}
	}
	function onComposeKey(e) {
		if (!R.qr.hidden) {
			var items = R.qr.querySelectorAll('.qr-item');
			var cur = R.qr.querySelector('.qr-item.is-active');
			var idx = Array.prototype.indexOf.call(items, cur);
			if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
				e.preventDefault();
				if (cur) cur.classList.remove('is-active');
				idx = e.key === 'ArrowDown' ? Math.min(items.length - 1, idx + 1) : Math.max(0, idx - 1);
				if (items[idx]) { items[idx].classList.add('is-active'); items[idx].scrollIntoView({ block: 'nearest' }); }
				return;
			}
			if ((e.key === 'Enter' || e.key === 'Tab') && cur) { e.preventDefault(); cur.click(); return; }
			if (e.key === 'Escape') { e.preventDefault(); toggleQuickReplies(false); return; }
		}
		if (e.key === 'Enter' && !e.shiftKey && !e.isComposing && !isMobile()) { e.preventDefault(); onSend(e); }
	}
	function onSend(e) {
		e.preventDefault();
		var body = R.text.value.trim();
		var id = S.openId;
		if (!body || !id) return;
		R.text.value = ''; autosize(); toggleQuickReplies(false);
		var local = { id: 'l' + Date.now(), local: true, from: 'agent', body: body, at: nowSec(), agent: CFG.me };
		S.thread.messages.push(local);
		renderMessages(true);
		lastTyping = 0;
		api('POST', 'agent/message', { conversation: id, body: body }).then(function (r) {
			if (S.openId !== id) return;
			mergeThread([r.message]);
			var c = S.convs[id];
			if (c) {
				if (!c.assignedTo) c.assignedTo = S.me.id;
				if (c.status === 'closed') c.status = 'open';
				c.lastBody = body; c.lastSender = 'agent'; c.updatedAt = nowSec();
			}
			renderMessages(true); renderThreadHeader(); renderTabs(); renderList();
		}).catch(function (err) {
			local.failed = true;
			S.thread.messages = S.thread.messages.filter(function (m) { return m !== local; });
			if (!R.text.value) R.text.value = body;
			renderMessages();
			toast(err.message || __('Could not send', 'hilo-chat'));
		});
	}
	function onAgentFile() {
		var file = R.file.files && R.file.files[0];
		R.file.value = '';
		if (!file || !S.openId) return;
		var id = S.openId;
		var fd = new FormData();
		fd.append('file', file, file.name);
		fd.append('conversation', id);
		var local = { id: 'l' + Date.now(), local: true, from: 'agent', body: '', at: nowSec(), attachment: { image: /^image\//.test(file.type), url: /^image\//.test(file.type) ? URL.createObjectURL(file) : '', name: file.name } };
		S.thread.messages.push(local);
		renderMessages(true);
		api('POST', 'agent/upload', fd, true).then(function (r) {
			S.thread.messages = S.thread.messages.filter(function (m) { return m !== local; });
			if (S.openId === id) { mergeThread([r.message]); renderMessages(true); }
		}).catch(function (err) {
			S.thread.messages = S.thread.messages.filter(function (m) { return m !== local; });
			renderMessages();
			toast(err.message || __('The file could not be uploaded', 'hilo-chat'));
		});
	}

	/* ---------- Quick replies ---------- */
	function loadQuickReplies() {
		return api('GET', 'agent/quick-replies').then(function (list) { S.quickReplies = list; return list; });
	}
	function toggleQuickReplies(show, query) {
		if (!show) { R.qr.hidden = true; return; }
		var go = function (list) {
			query = (query || '').toLowerCase();
			var items = list.filter(function (q) { return !query || q.shortcut.indexOf(query) > -1 || q.title.toLowerCase().indexOf(query) > -1; });
			R.qr.textContent = '';
			if (!items.length) {
				R.qr.appendChild(el('div', { class: 'qr-empty' }, [
					(query ? fmt(__('No quick replies for “/%s”.', 'hilo-chat'), query) : __('No quick replies yet.', 'hilo-chat')) + ' ',
					el('button', { type: 'button', class: 'link', onclick: openQuickRepliesManager, text: __('Create one', 'hilo-chat') })
				]));
			}
			items.forEach(function (q, i) {
				R.qr.appendChild(el('button', {
					type: 'button', class: 'qr-item' + (i === 0 ? ' is-active' : ''), role: 'option',
					onmousedown: function (e) { e.preventDefault(); },
					onclick: function () { insertQuickReply(q); }
				}, [el('span', { class: 'qr-key', text: '/' + q.shortcut }), el('span', { class: 'qr-title', text: q.title }), el('span', { class: 'qr-body', text: q.body })]));
			});
			R.qr.hidden = false;
		};
		if (S.quickReplies) go(S.quickReplies); else loadQuickReplies().then(go).catch(function () {});
	}
	function insertQuickReply(q) {
		var pos = R.text.selectionStart, v = R.text.value;
		var before = v.slice(0, pos).replace(/(^|\s)\/[\w-]*$/, '$1');
		var body = q.body.replace(/\{(name|nombre)\}/gi, (S.convs[S.openId] && S.convs[S.openId].name.split(' ')[0]) || '');
		R.text.value = before + body + v.slice(pos);
		R.text.focus();
		R.text.selectionStart = R.text.selectionEnd = (before + body).length;
		autosize();
		toggleQuickReplies(false);
	}
	function openQuickRepliesManager() {
		toggleQuickReplies(false);
		var listBox = el('div', { class: 'qr-manage-list' });
		var form = el('form', { class: 'qr-form' });
		var fId = el('input', { type: 'hidden' });
		var fKey = el('input', { class: 'field', placeholder: __('shortcut (e.g. shipping)', 'hilo-chat'), required: true, maxlength: '60' });
		var fTitle = el('input', { class: 'field', placeholder: __('Title', 'hilo-chat'), maxlength: '120' });
		var fBody = el('textarea', { class: 'field', rows: '4', placeholder: __('Reply text. Use {name} for the customer name.', 'hilo-chat'), required: true });
		var saveBtn = el('button', { class: 'btn btn-primary', type: 'submit', text: __('Save', 'hilo-chat') });
		var cancel = el('button', { class: 'btn btn-ghost', type: 'button', text: __('New', 'hilo-chat'), onclick: function () { fId.value = ''; form.reset(); fKey.focus(); } });
		[fId, el('div', { class: 'qr-form-row' }, [fKey, fTitle]), fBody, el('div', { class: 'qr-form-actions' }, [cancel, saveBtn])].forEach(function (n) { form.appendChild(n); });
		function draw(list) {
			listBox.textContent = '';
			list.forEach(function (q) {
				listBox.appendChild(el('div', { class: 'qr-row' }, [
					el('button', { type: 'button', class: 'qr-row-main', onclick: function () { fId.value = q.id; fKey.value = q.shortcut; fTitle.value = q.title; fBody.value = q.body; fBody.focus(); } }, [
						el('span', { class: 'qr-key', text: '/' + q.shortcut }), el('span', { class: 'qr-title', text: q.title || '' }), el('span', { class: 'qr-body', text: q.body })
					]),
					el('button', { type: 'button', class: 'icon-btn danger', 'aria-label': __('Delete', 'hilo-chat'), onclick: function () {
						if (!confirm(fmt(__('Delete /%s?', 'hilo-chat'), q.shortcut))) return;
						api('DELETE', 'agent/quick-replies/' + q.id).then(function (l) { S.quickReplies = l; draw(l); });
					} }, [icon('close')])
				]));
			});
		}
		form.addEventListener('submit', function (e) {
			e.preventDefault();
			api('POST', 'agent/quick-replies', { id: fId.value, shortcut: fKey.value, title: fTitle.value || fKey.value, body: fBody.value })
				.then(function (l) { S.quickReplies = l; draw(l); form.reset(); fId.value = ''; toast(__('Saved', 'hilo-chat')); })
				.catch(function (err) { toast(err.message); });
		});
		modal(__('Quick replies', 'hilo-chat'), [el('p', { class: 'muted', text: __('Type / in the chat to insert them.', 'hilo-chat') }), listBox, form]);
		(S.quickReplies ? Promise.resolve(S.quickReplies) : loadQuickReplies()).then(draw);
	}

	/* ---------- Actions ---------- */
	function assign(agentId) {
		var id = S.openId;
		api('POST', 'agent/assign', { conversation: id, agent: agentId }).then(function () {
			if (S.convs[id]) S.convs[id].assignedTo = agentId;
			renderThreadHeader(); renderTabs(); renderList();
			sync();
		}).catch(function (e) { toast(e.message); });
	}
	function act(action) {
		var id = S.openId;
		api('POST', 'agent/' + action, { conversation: id }).then(function () {
			if (S.convs[id]) S.convs[id].status = action === 'close' ? 'closed' : 'open';
			if (action === 'close') { S.convs[id].unread = 0; toast(__('Chat resolved', 'hilo-chat')); }
			renderThreadHeader(); renderTabs(); renderList();
			sync();
			if (action === 'close' && isMobile()) closeThreadMobile();
		}).catch(function (e) { toast(e.message); });
	}
	function forgetConversations(ids) {
		ids.forEach(function (id) {
			delete S.convs[id];
			delete S.details[id];
			if (S.openId === id) {
				S.openId = 0;
				history.replaceState(null, '', location.pathname);
				app.dataset.view = 'list';
				renderEmptyThread();
			}
		});
		renderTabs(); renderList(); updateAppBadge();
	}
	function deleteConversation(id) {
		var c = S.convs[id];
		if (!c || c.status !== 'closed') return;
		if (!confirm(fmt(__('Delete the conversation with %s? This cannot be undone.', 'hilo-chat'), c.name))) return;
		api('POST', 'agent/delete', { conversation: id }).then(function () {
			forgetConversations([id]);
			toast(__('Conversation deleted', 'hilo-chat'));
		}).catch(function (e) { toast(e.message); });
	}
	function deleteAllResolved() {
		if (!confirm(__('Delete all resolved conversations and their attachments? This cannot be undone.', 'hilo-chat'))) return;
		api('POST', 'agent/delete-resolved', {}).then(function (r) {
			forgetConversations(Object.keys(S.convs).map(Number).filter(function (id) { return S.convs[id].status === 'closed'; }));
			toast(fmt(r.count === 1 ? __('%d conversation deleted', 'hilo-chat') : __('%d conversations deleted', 'hilo-chat'), r.count));
		}).catch(function (e) { toast(e.message); });
	}

	function openThreadMenu(e) {
		var c = S.convs[S.openId];
		if (!c) return;
		var items = [];
		var others = S.agents.filter(function (a) { return a.id !== c.assignedTo; });
		if (c.status !== 'closed') {
			others.forEach(function (a) {
				items.push({ label: (a.id === S.me.id ? __('Assign to me', 'hilo-chat') : fmt(__('Transfer to %s', 'hilo-chat'), a.name)), hint: a.online && a.available ? __('online', 'hilo-chat') : '', onClick: function () { assign(a.id); } });
			});
			if (c.assignedTo) items.push({ label: __('Release (unassigned)', 'hilo-chat'), onClick: function () { assign(0); } });
		}
		if (c.email) items.push({ label: __('Copy email', 'hilo-chat'), onClick: function () { copy(c.email); } });
		if (c.status === 'closed') items.push({ label: __('Delete conversation', 'hilo-chat'), danger: true, onClick: function () { deleteConversation(c.id); } });
		popover(e.currentTarget, items);
	}
	function copy(text) {
		(navigator.clipboard ? navigator.clipboard.writeText(text) : Promise.reject()).then(function () { toast(__('Copied', 'hilo-chat')); }).catch(function () { prompt(__('Copy:', 'hilo-chat'), text); });
	}

	/* ================================================================== *
	 * Info panel
	 * ================================================================== */
	function toggleInfo(force) {
		S.showInfo = typeof force === 'boolean' ? force : !S.showInfo;
		localStorage.setItem('hilo_info', S.showInfo ? '1' : '0');
		app.classList.toggle('show-info', S.showInfo);
		if (isMobile() && S.showInfo) app.dataset.view = 'info';
		else if (isMobile() && app.dataset.view === 'info') app.dataset.view = 'thread';
		renderThreadHeader();
	}
	function section(title, children) {
		return el('section', { class: 'info-sec' }, [el('h3', { text: title })].concat(children));
	}
	function renderInfo() {
		var c = S.convs[S.openId];
		var d = S.details[S.openId];
		R.info.textContent = '';
		if (!c) return;
		R.info.appendChild(el('header', { class: 'info-head' }, [
			el('button', { class: 'icon-btn info-close', type: 'button', 'aria-label': __('Close', 'hilo-chat'), onclick: function () { toggleInfo(false); } }, [icon(isMobile() ? 'back' : 'close')]),
			el('strong', { text: __('Customer', 'hilo-chat') })
		]));
		var cust = d && d.customer;
		var card = el('div', { class: 'cust-card' }, [
			avatar(c.name, null, 'xl'),
			el('div', { class: 'cust-name', text: c.name }),
			c.email ? el('a', { class: 'cust-line', href: 'mailto:' + c.email, text: c.email }) : null,
			cust && cust.phone ? el('a', { class: 'cust-line', href: 'tel:' + cust.phone.replace(/[^\d+]/g, ''), text: cust.phone }) : null,
			cust && cust.city ? el('div', { class: 'cust-line muted', text: cust.city }) : null,
			el('div', { class: 'cust-tags' }, [
				el('span', { class: 'chip ' + (cust && cust.registered ? 'chip-ok' : ''), text: cust && cust.registered ? __('Registered customer', 'hilo-chat') : __('Guest', 'hilo-chat') }),
				cust && cust.orderCount ? el('span', { class: 'chip', text: fmt(cust.orderCount === 1 ? __('%d order', 'hilo-chat') : __('%d orders', 'hilo-chat'), cust.orderCount) + ' · ' + cust.totalSpent }) : null
			]),
			cust && cust.profileUrl && CFG.canManage ? el('a', { class: 'link small', href: cust.profileUrl, target: '_blank', rel: 'noopener', text: __('View profile in WordPress', 'hilo-chat') }) : null
		]);
		R.info.appendChild(card);

		if (!d) { R.info.appendChild(el('div', { class: 'loading-row', text: __('Loading details…', 'hilo-chat') })); return; }
		var ctx = d.context || {};

		if (ctx.page) {
			R.info.appendChild(section(__('Viewing now', 'hilo-chat'), [
				el('a', { class: 'page-link', href: ctx.page.url, target: '_blank', rel: 'noopener' }, [el('span', { text: ctx.page.title || ctx.page.url }), icon('ext')]),
				el('div', { class: 'muted small', text: relTime(ctx.page.at) })
			]));
		}

		if (ctx.cart && ctx.cart.items && ctx.cart.items.length) {
			R.info.appendChild(section(__('Cart', 'hilo-chat') + ' · ' + ctx.cart.total, ctx.cart.items.map(function (it) {
				return el('a', { class: 'cart-item', href: it.url, target: '_blank', rel: 'noopener' }, [
					it.image ? el('img', { src: it.image, alt: '', loading: 'lazy' }) : el('span', { class: 'cart-ph' }),
					el('span', { class: 'cart-text' }, [el('span', { class: 'cart-name', text: it.name }), el('span', { class: 'muted small', text: it.qty + ' × · ' + it.total })])
				]);
			})));
		} else if (ctx.cart) {
			R.info.appendChild(section(__('Cart', 'hilo-chat'), [el('p', { class: 'muted small', text: __('Empty', 'hilo-chat') })]));
		}

		if (cust && cust.orders && cust.orders.length) {
			R.info.appendChild(section(__('Recent orders', 'hilo-chat'), cust.orders.map(function (o) {
				return el('a', { class: 'order', href: o.editUrl, target: '_blank', rel: 'noopener' }, [
					el('span', { class: 'order-top' }, [el('strong', { text: '#' + o.number }), el('span', { class: 'status status-' + o.slug, text: o.status })]),
					el('span', { class: 'order-items muted small', text: o.items.join(', ') }),
					el('span', { class: 'order-bottom small' }, [el('span', { text: o.total }), el('span', { class: 'muted', text: new Date(o.date * 1000).toLocaleDateString(LOCALE, { day: 'numeric', month: 'short', year: 'numeric' }) })])
				]);
			})));
		} else if (cust) {
			R.info.appendChild(section(__('Orders', 'hilo-chat'), [el('p', { class: 'muted small', text: __('No orders with this email.', 'hilo-chat') })]));
		}

		if (ctx.pages && ctx.pages.length > 1) {
			R.info.appendChild(section(__('Journey', 'hilo-chat'), ctx.pages.slice().reverse().map(function (p) {
				return el('a', { class: 'trail', href: p.url, target: '_blank', rel: 'noopener' }, [el('span', { text: p.title || p.url }), el('span', { class: 'muted small', text: relTime(p.at) })]);
			})));
		}

		var tech = [];
		if (ctx.referrer) tech.push(el('div', { class: 'small' }, [el('span', { class: 'muted', text: __('Came from:', 'hilo-chat') + ' ' }), ctx.referrer]));
		if (ctx.userAgent) tech.push(el('div', { class: 'small muted', text: describeUA(ctx.userAgent) }));
		tech.push(el('div', { class: 'small muted', text: fmt(__('Started: %s', 'hilo-chat'), relTime(c.createdAt)) }));
		R.info.appendChild(section(__('Details', 'hilo-chat'), tech));
	}
	function describeUA(ua) {
		var os = /iPhone|iPad/.test(ua) ? 'iOS' : /Android/.test(ua) ? 'Android' : /Mac OS/.test(ua) ? 'macOS' : /Windows/.test(ua) ? 'Windows' : /Linux/.test(ua) ? 'Linux' : '';
		var br = /Edg\//.test(ua) ? 'Edge' : /OPR\//.test(ua) ? 'Opera' : /Chrome\//.test(ua) ? 'Chrome' : /Firefox\//.test(ua) ? 'Firefox' : /Safari\//.test(ua) ? 'Safari' : '';
		var mobile = /Mobi|iPhone|Android/.test(ua) ? __('mobile', 'hilo-chat') : __('desktop', 'hilo-chat');
		return [br, os, mobile].filter(Boolean).join(' · ');
	}

	/* ================================================================== *
	 * Sync loop
	 * ================================================================== */
	function sync() {
		if (S.syncing) return Promise.resolve();
		S.syncing = true;
		clearTimeout(S.syncTimer);
		var now = Date.now();
		var params = {
			since: S.since,
			open: S.openId || '',
			after: S.openId ? S.thread.lastId : '',
			visible: document.hidden ? '' : 1,
			meta: now - S.lastMeta > META_EVERY ? 1 : ''
		};
		var openAtStart = S.openId;
		return api('GET', 'agent/sync', params).then(function (r) {
			setOnline(true);
			S.clockSkew = r.now - Math.floor(Date.now() / 1000);
			if (params.meta || r.full) {
				S.lastMeta = now;
				if (r.agents) S.agents = r.agents;
				if (r.store) S.store = r.store;
				if (r.me) { S.me.available = r.me.available; renderAvailability(); }
			}
			var fresh = [];
			if (r.full) {
				var keep = {};
				r.conversations.forEach(function (c) { keep[c.id] = true; });
				Object.keys(S.convs).forEach(function (id) { if (!keep[id] && Number(id) !== S.openId) delete S.convs[id]; });
			}
			r.conversations.forEach(function (c) {
				var prev = S.convs[c.id];
				if (S.since && (!prev || c.lastMessageId > prev.lastMessageId) && c.lastSender === 'visitor') fresh.push(c);
				if (c.id === S.openId && !document.hidden) c.unread = 0;
				S.convs[c.id] = Object.assign(prev || {}, c);
			});
			S.since = r.now;
			if (r.deleted && r.deleted.length) forgetConversations(r.deleted.filter(function (id) { return S.convs[id]; }));

			if (r.open && r.open.id === S.openId && openAtStart === S.openId) {
				var added = mergeThread(r.open.messages);
				var changed = S.thread.typing !== r.open.typing || S.thread.visitorRead !== r.open.visitorRead || S.thread.visitorSeen !== r.open.visitorSeen;
				S.thread.typing = r.open.typing;
				S.thread.visitorRead = r.open.visitorRead;
				S.thread.visitorSeen = r.open.visitorSeen;
				if (added || changed) renderMessages();
				if (changed) renderThreadHeader();
				if (added && S.details[S.openId] && r.open.messages.some(function (m) { return m.from === 'visitor'; })) {
					// Visitor moved/added to cart? refresh context quietly.
					refreshContextSoon(S.openId);
				}
			}

			renderTabs();
			renderList();
			if (S.openId) renderThreadHeader();
			updateAppBadge();
			if (fresh.length) onIncoming(fresh);
		}).catch(function (e) {
			if (!e.status) setOnline(false);
		}).then(function () {
			S.syncing = false;
			S.syncTimer = setTimeout(sync, document.hidden ? SYNC_HIDDEN : SYNC_VISIBLE);
		});
	}
	var ctxTimer = null;
	function refreshContextSoon(id) {
		clearTimeout(ctxTimer);
		ctxTimer = setTimeout(function () {
			if (S.openId !== id) return;
			api('GET', 'agent/conversation/' + id).then(function (r) {
				S.details[id] = { context: r.context, customer: r.customer };
				if (S.openId === id) renderInfo();
			}).catch(function () {});
		}, 1500);
	}

	function onIncoming(list) {
		var relevant = list.filter(function (c) { return c.status !== 'closed' && (!c.assignedTo || c.assignedTo === S.me.id); });
		if (!relevant.length) return;
		var viewingIt = relevant.length === 1 && relevant[0].id === S.openId && !document.hidden;
		if (!viewingIt) playSound();
	}

	function updateAppBadge() {
		var total = 0;
		Object.keys(S.convs).forEach(function (k) {
			var c = S.convs[k];
			if (c.status !== 'closed' && (!c.assignedTo || c.assignedTo === S.me.id)) total += c.unread;
		});
		document.title = (total ? '(' + total + ') ' : '') + CFG.brand + ' · ' + __('Chats', 'hilo-chat');
		if ('setAppBadge' in navigator) {
			try { total ? navigator.setAppBadge(total) : navigator.clearAppBadge(); } catch (e) {}
		}
	}

	function setOnline(on) {
		if (S.online === on) return;
		S.online = on;
		R.netBanner.hidden = on;
	}

	document.addEventListener('visibilitychange', function () {
		if (document.hidden) {
			navigator.sendBeacon && fetch(CFG.rest + 'agent/presence', { method: 'POST', credentials: 'same-origin', keepalive: true, headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' }, body: JSON.stringify({ hidden: 1 }) }).catch(function () {});
		} else {
			S.syncing = false;
			sync();
			if (S.openId) api('POST', 'agent/read', { conversation: S.openId }).catch(function () {});
		}
	});
	window.addEventListener('online', function () { sync(); });

	/* ---------- Sound ---------- */
	var audioCtx = null;
	function playSound() {
		if (!S.sound) return;
		try {
			audioCtx = audioCtx || new (window.AudioContext || window.webkitAudioContext)();
			[0, 0.13].forEach(function (offset, i) {
				var o = audioCtx.createOscillator(), g = audioCtx.createGain(), t0 = audioCtx.currentTime + offset;
				o.type = 'sine'; o.frequency.value = i ? 1175 : 880;
				g.gain.setValueAtTime(0.0001, t0);
				g.gain.exponentialRampToValueAtTime(0.12, t0 + 0.02);
				g.gain.exponentialRampToValueAtTime(0.0001, t0 + 0.3);
				o.connect(g); g.connect(audioCtx.destination); o.start(t0); o.stop(t0 + 0.32);
			});
		} catch (e) {}
	}
	// Unlock audio on first interaction (autoplay policies).
	document.addEventListener('pointerdown', function unlock() {
		try { audioCtx = audioCtx || new (window.AudioContext || window.webkitAudioContext)(); audioCtx.resume(); } catch (e) {}
		document.removeEventListener('pointerdown', unlock);
	});

	/* ================================================================== *
	 * Availability
	 * ================================================================== */
	function renderAvailability() {
		R.availBtn.classList.toggle('is-off', !S.me.available);
		R.availBtn.setAttribute('aria-pressed', String(S.me.available));
		$('.avail-text', R.availBtn).textContent = S.me.available ? __('Available', 'hilo-chat') : __('Away', 'hilo-chat');
		R.availBtn.title = S.me.available ? __('You receive chats and notifications. Tap to go away.', 'hilo-chat') : __('You are not notified about new chats. Tap to become available.', 'hilo-chat');
	}
	function toggleAvailability() {
		S.me.available = !S.me.available;
		renderAvailability();
		api('POST', 'agent/presence', { available: S.me.available ? 1 : 0 }).then(function (r) {
			S.store = r.store;
			toast(S.me.available ? __('You are available', 'hilo-chat') : __('You are now away', 'hilo-chat'));
		}).catch(function (e) { S.me.available = !S.me.available; renderAvailability(); toast(e.message); });
	}

	/* ================================================================== *
	 * Push notifications
	 * ================================================================== */
	var swReg = null;
	function urlB64ToUint8(base64) {
		var pad = '='.repeat((4 - base64.length % 4) % 4);
		var raw = atob((base64 + pad).replace(/-/g, '+').replace(/_/g, '/'));
		var out = new Uint8Array(raw.length);
		for (var i = 0; i < raw.length; i++) out[i] = raw.charCodeAt(i);
		return out;
	}
	function isIOS() { return /iPhone|iPad|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1); }
	function isStandalone() { return window.matchMedia('(display-mode: standalone)').matches || navigator.standalone === true; }
	function pushSupported() { return 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window; }

	function registerSW() {
		if (!('serviceWorker' in navigator)) return Promise.resolve(null);
		return navigator.serviceWorker.register(CFG.swUrl, { scope: CFG.appUrl }).then(function (reg) {
			swReg = reg;
			navigator.serviceWorker.addEventListener('message', function (e) {
				if (e.data && e.data.type === 'open' && e.data.conv) openConversation(e.data.conv);
			});
			return reg;
		}).catch(function () { return null; });
	}

	function pushState() {
		if (!pushSupported()) return Promise.resolve(isIOS() && !isStandalone() ? 'ios-install' : 'unsupported');
		if (Notification.permission === 'denied') return Promise.resolve('denied');
		if (!swReg) return Promise.resolve('unsupported');
		return swReg.pushManager.getSubscription().then(function (sub) {
			if (sub && Notification.permission === 'granted') {
				// Re-send to server occasionally (keys can rotate / DB may be new).
				saveSubscription(sub);
				return 'on';
			}
			return 'off';
		});
	}
	function saveSubscription(sub) {
		var j = sub.toJSON();
		return api('POST', 'agent/push/subscribe', { endpoint: j.endpoint, keys: j.keys });
	}
	function enablePush() {
		if (!pushSupported()) {
			if (isIOS() && !isStandalone()) return showIOSInstall();
			return toast(__('This browser does not support push notifications.', 'hilo-chat'));
		}
		Notification.requestPermission().then(function (perm) {
			if (perm !== 'granted') { toast(__('Notification permission denied', 'hilo-chat')); renderNotifBanner(); return; }
			return (swReg ? Promise.resolve(swReg) : registerSW()).then(function (reg) {
				return reg.pushManager.getSubscription().then(function (sub) {
					return sub || reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: urlB64ToUint8(CFG.vapid) });
				});
			}).then(saveSubscription).then(function () {
				toast(__('Notifications enabled', 'hilo-chat'));
				api('POST', 'agent/push/test', {}).catch(function () {});
				renderNotifBanner();
			});
		}).catch(function (e) { toast(fmt(__('Could not enable them: %s', 'hilo-chat'), e.message || e)); });
	}
	function disablePush() {
		if (!swReg) return;
		swReg.pushManager.getSubscription().then(function (sub) {
			if (!sub) return;
			var ep = sub.endpoint;
			return sub.unsubscribe().then(function () { return api('POST', 'agent/push/unsubscribe', { endpoint: ep }); });
		}).then(function () { toast(__('Notifications disabled on this device', 'hilo-chat')); renderNotifBanner(); });
	}
	function renderNotifBanner() {
		pushState().then(function (st) {
			S.pushState = st;
			R.notifBanner.textContent = '';
			if (st === 'off') {
				R.notifBanner.hidden = false;
				R.notifBanner.appendChild(icon('bell'));
				R.notifBanner.appendChild(el('span', { text: __('Turn on notifications to hear about every chat, even when the app is closed.', 'hilo-chat') }));
				R.notifBanner.appendChild(el('button', { class: 'btn btn-primary btn-sm', type: 'button', onclick: enablePush, text: __('Turn on', 'hilo-chat') }));
			} else if (st === 'ios-install') {
				R.notifBanner.hidden = false;
				R.notifBanner.appendChild(icon('bell'));
				R.notifBanner.appendChild(el('span', { text: __('On iPhone, add the app to your home screen to receive notifications.', 'hilo-chat') }));
				R.notifBanner.appendChild(el('button', { class: 'btn btn-primary btn-sm', type: 'button', onclick: showIOSInstall, text: __('How', 'hilo-chat') }));
			} else if (st === 'denied') {
				R.notifBanner.hidden = false;
				R.notifBanner.appendChild(icon('bell'));
				R.notifBanner.appendChild(el('span', { text: __('Notifications are blocked. Allow them for this site in your browser settings.', 'hilo-chat') }));
			} else {
				R.notifBanner.hidden = true;
			}
		});
	}
	function showIOSInstall() {
		modal(__('Install on iPhone / iPad', 'hilo-chat'), [
			el('ol', { class: 'steps' }, [
				el('li', { text: __('Open this page in Safari.', 'hilo-chat') }),
				el('li', { text: __('Tap the Share button (the square with an arrow pointing up).', 'hilo-chat') }),
				el('li', { text: __('Choose “Add to Home Screen”.', 'hilo-chat') }),
				el('li', { text: __('Open the app from the new icon and turn on notifications.', 'hilo-chat') })
			]),
			el('p', { class: 'muted small', text: __('Requires iOS 16.4 or later.', 'hilo-chat') })
		]);
	}

	window.addEventListener('beforeinstallprompt', function (e) { e.preventDefault(); S.installPrompt = e; });
	window.addEventListener('appinstalled', function () { S.installPrompt = null; toast(__('App installed', 'hilo-chat')); });
	function install() {
		if (S.installPrompt) {
			S.installPrompt.prompt();
			S.installPrompt.userChoice.finally(function () { S.installPrompt = null; });
		} else if (isIOS()) showIOSInstall();
		else modal(__('Install the app', 'hilo-chat'), [el('p', { text: __('Open the browser menu (⋮) and choose “Install app” or “Add to Home screen”. In desktop Chrome there is also an install icon in the address bar.', 'hilo-chat') })]);
	}

	/* ================================================================== *
	 * Menus, modals, toasts
	 * ================================================================== */
	function openMenu(e) {
		var items = [];
		if (!isStandalone()) items.push({ label: __('Install app', 'hilo-chat'), onClick: install });
		if (S.pushState === 'on') {
			items.push({ label: __('Send a test notification', 'hilo-chat'), onClick: function () { api('POST', 'agent/push/test', {}).then(function () { toast(__('Sent', 'hilo-chat')); }); } });
			items.push({ label: __('Turn off notifications here', 'hilo-chat'), onClick: disablePush });
		} else {
			items.push({ label: __('Turn on notifications', 'hilo-chat'), onClick: enablePush });
		}
		items.push({ label: S.sound ? __('Mute sound', 'hilo-chat') : __('Turn on sound', 'hilo-chat'), onClick: function () { S.sound = !S.sound; localStorage.setItem('hilo_sound', S.sound ? '1' : '0'); if (S.sound) playSound(); } });
		items.push({ label: __('Quick replies', 'hilo-chat'), onClick: openQuickRepliesManager });
		items.push({ label: __('Team', 'hilo-chat'), onClick: showTeam });
		if (CFG.adminUrl) items.push({ label: __('Chat settings', 'hilo-chat'), href: CFG.adminUrl });
		items.push({ label: __('Log out', 'hilo-chat'), href: CFG.logout, danger: true });
		popover(e.currentTarget, items);
	}
	function showTeam() {
		var st = S.store;
		modal(__('Team', 'hilo-chat'), [
			st ? el('p', { class: 'store-status ' + (st.online ? 'on' : '') }, [el('span', { class: 'dot' }), st.online ? __('Customers see the chat as Online.', 'hilo-chat') : (st.inHours ? __('Offline: no agents available.', 'hilo-chat') : __('Outside business hours.', 'hilo-chat'))]) : null,
			el('div', { class: 'team' }, S.agents.map(function (a) {
				return el('div', { class: 'team-row' }, [avatar(a.name, a.avatar), el('span', { class: 'team-name', text: a.name + (a.id === S.me.id ? ' (' + __('you', 'hilo-chat') + ')' : '') }),
					el('span', { class: 'chip ' + (a.online && a.available ? 'chip-ok' : ''), text: a.online ? (a.available ? __('Available', 'hilo-chat') : __('Away', 'hilo-chat')) : __('Offline', 'hilo-chat') })]);
			}))
		]);
	}

	var openPop = null;
	function popover(anchor, items) {
		closePopover();
		var pop = el('div', { class: 'popover', role: 'menu' }, items.map(function (it) {
			var node = it.href
				? el('a', { class: 'pop-item' + (it.danger ? ' danger' : ''), role: 'menuitem', href: it.href, target: it.href.indexOf(CFG.appUrl) === 0 ? null : '_blank' }, [it.label])
				: el('button', { class: 'pop-item' + (it.danger ? ' danger' : ''), role: 'menuitem', type: 'button', onclick: function () { closePopover(); it.onClick(); } }, [it.label, it.hint ? el('span', { class: 'pop-hint', text: it.hint }) : null]);
			return node;
		}));
		document.body.appendChild(pop);
		var r = anchor.getBoundingClientRect();
		var w = pop.offsetWidth, h = pop.offsetHeight;
		var left = Math.min(window.innerWidth - w - 8, Math.max(8, r.right - w));
		var top = r.bottom + 6 + h > window.innerHeight ? Math.max(8, r.top - h - 6) : r.bottom + 6;
		pop.style.left = left + 'px'; pop.style.top = top + 'px';
		openPop = pop;
		setTimeout(function () { document.addEventListener('click', outside, true); }, 0);
		function outside(ev) { if (!pop.contains(ev.target)) { closePopover(); } }
		pop._outside = outside;
		var first = pop.querySelector('.pop-item'); if (first) first.focus();
	}
	function closePopover() {
		if (!openPop) return;
		document.removeEventListener('click', openPop._outside, true);
		openPop.remove();
		openPop = null;
	}

	function modal(title, children) {
		var close = function () { overlay.remove(); document.removeEventListener('keydown', esc); };
		var esc = function (e) { if (e.key === 'Escape') close(); };
		var overlay = el('div', { class: 'overlay', onclick: function (e) { if (e.target === overlay) close(); } }, [
			el('div', { class: 'modal', role: 'dialog', 'aria-modal': 'true', 'aria-label': title }, [
				el('header', { class: 'modal-head' }, [el('h2', { text: title }), el('button', { class: 'icon-btn', type: 'button', 'aria-label': __('Close', 'hilo-chat'), onclick: function () { close(); } }, [icon('close')])]),
				el('div', { class: 'modal-body' }, children)
			])
		]);
		document.body.appendChild(overlay);
		document.addEventListener('keydown', esc);
		var f = overlay.querySelector('input, textarea, button.btn'); if (f) setTimeout(function () { f.focus(); }, 30);
		return close;
	}

	var toastTimer = null, toastEl = null;
	function toast(msg) {
		if (!toastEl) { toastEl = el('div', { class: 'toast', role: 'status' }); document.body.appendChild(toastEl); }
		toastEl.textContent = msg;
		toastEl.classList.add('is-on');
		clearTimeout(toastTimer);
		toastTimer = setTimeout(function () { toastEl.classList.remove('is-on'); }, 2600);
	}

	/* ================================================================== *
	 * Routing & boot
	 * ================================================================== */
	function routeFromHash() {
		var m = /^#c(\d+)$/.exec(location.hash);
		if (m) openConversation(Number(m[1]));
	}
	window.addEventListener('hashchange', routeFromHash);
	window.addEventListener('popstate', function () { if (!location.hash && isMobile()) closeThreadMobile(); });
	window.addEventListener('resize', function () {
		if (!isMobile() && app.dataset.view !== 'list') app.dataset.view = 'list';
		if (!isMobile()) app.classList.toggle('show-info', S.showInfo);
	});
	document.addEventListener('keydown', function (e) {
		if (e.key === 'Escape' && openPop) closePopover();
	});

	function boot() {
		buildLayout();
		app.classList.toggle('show-info', S.showInfo && !isMobile());
		if (isMobile()) S.showInfo = false;
		renderAvailability();
		renderTabs();
		sync().then(function () {
			// Pick a sensible tab on first load.
			var hasMine = Object.keys(S.convs).some(function (k) { return matchesFilter(S.convs[k], 'mine'); });
			var hasUnassigned = Object.keys(S.convs).some(function (k) { return matchesFilter(S.convs[k], 'unassigned'); });
			var hasOpen = Object.keys(S.convs).some(function (k) { return matchesFilter(S.convs[k], 'all'); });
			if (hasUnassigned && (!hasMine || S.filter === 'closed')) S.filter = 'unassigned';
			else if (!hasMine && !hasUnassigned && hasOpen && S.filter !== 'all') S.filter = 'all';
			renderTabs(); renderList();
			routeFromHash();
		});
		registerSW().then(renderNotifBanner);
		// Relative times refresh.
		setInterval(function () { renderList(); if (S.openId) renderThreadHeader(); }, 30000);
	}
	boot();
})();

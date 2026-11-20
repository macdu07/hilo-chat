/*! Hilo Chat — agent app */
(function () {
	'use strict';

	var CFG = window.HiloAgentConfig;
	var SYNC_VISIBLE = 4000, SYNC_HIDDEN = 20000, META_EVERY = 30000;

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
		if (diff < 45) return 'ahora';
		if (diff < 3600) return Math.floor(diff / 60) + ' min';
		var d = new Date(ts * 1000), today = new Date();
		if (d.toDateString() === today.toDateString()) return d.toLocaleTimeString('es', { hour: '2-digit', minute: '2-digit' });
		var y = new Date(); y.setDate(y.getDate() - 1);
		if (d.toDateString() === y.toDateString()) return 'ayer';
		if (diff < 6 * 86400) return d.toLocaleDateString('es', { weekday: 'short' });
		return d.toLocaleDateString('es', { day: 'numeric', month: 'short' });
	}
	function clock(ts) { return new Date(ts * 1000).toLocaleTimeString('es', { hour: '2-digit', minute: '2-digit' }); }
	function dayLabel(ts) {
		var d = new Date(ts * 1000), today = new Date(), y = new Date(); y.setDate(y.getDate() - 1);
		if (d.toDateString() === today.toDateString()) return 'Hoy';
		if (d.toDateString() === y.toDateString()) return 'Ayer';
		return d.toLocaleDateString('es', { weekday: 'long', day: 'numeric', month: 'long' });
	}
	function seenLabel(ts) {
		if (!ts) return 'Sin actividad reciente';
		var diff = nowSec() - ts;
		if (diff < 40) return 'En la tienda ahora';
		if (diff < 3600) return 'Visto hace ' + Math.max(1, Math.floor(diff / 60)) + ' min';
		return 'Visto ' + relTime(ts);
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
		var url = CFG.rest + path, opts = { method: method, credentials: 'same-origin', headers: { 'X-WP-Nonce': nonce } };
		if (method === 'GET' && data) {
			var q = Object.keys(data).filter(function (k) { return data[k] !== '' && data[k] != null; })
				.map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(data[k]); }).join('&');
			if (q) url += '?' + q;
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
				if (!r.ok) { var e = new Error(j && j.message ? j.message : 'Error de conexión'); e.status = r.status; throw e; }
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
		R.availBtn = el('button', { class: 'avail', type: 'button', onclick: toggleAvailability, 'aria-pressed': 'true' }, [el('span', { class: 'avail-dot' }), el('span', { class: 'avail-text', text: 'Disponible' })]);
		R.menuBtn = el('button', { class: 'icon-btn', type: 'button', 'aria-label': 'Menú', onclick: openMenu }, [icon('more')]);
		R.netBanner = el('div', { class: 'net-banner', hidden: true, text: 'Sin conexión. Reintentando…' });
		R.notifBanner = el('div', { class: 'notif-banner', hidden: true });
		R.tabs = el('div', { class: 'tabs', role: 'tablist' });
		R.searchInput = el('input', { type: 'search', class: 'search-input', placeholder: 'Buscar por nombre o email', 'aria-label': 'Buscar', oninput: function (e) { S.search = e.target.value.trim().toLowerCase(); renderList(); } });
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
		R.info = el('aside', { class: 'info', 'aria-label': 'Datos del cliente' });

		app.appendChild(R.sidebar);
		app.appendChild(R.thread);
		app.appendChild(R.info);
		renderEmptyThread();
	}

	/* ================================================================== *
	 * Sidebar: tabs + list
	 * ================================================================== */
	var FILTERS = [
		{ key: 'mine', label: 'Míos' },
		{ key: 'unassigned', label: 'Sin asignar' },
		{ key: 'all', label: 'Abiertos' },
		{ key: 'closed', label: 'Resueltos' }
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
		if (id === S.me.id) return 'Tú';
		for (var i = 0; i < S.agents.length; i++) if (S.agents[i].id === id) return S.agents[i].name;
		return 'Otro agente';
	}
	function renderList() {
		var list = sortedConvs().filter(function (c) {
			if (!matchesFilter(c, S.filter)) return false;
			if (!S.search) return true;
			return (c.name + ' ' + c.email).toLowerCase().indexOf(S.search) > -1;
		});
		R.list.textContent = '';
		if (!list.length) {
			var msg = { mine: 'No tienes chats asignados.', unassigned: 'No hay chats esperando. ✨', all: 'No hay chats abiertos.', closed: 'Sin chats resueltos recientes.' }[S.filter];
			R.list.appendChild(el('div', { class: 'list-empty' }, [icon('chat', 'big'), el('p', { text: S.search ? 'Sin resultados.' : msg })]));
			return;
		}
		list.forEach(function (c) {
			var online = c.visitorSeen && nowSec() - c.visitorSeen < 40;
			var preview = (c.lastSender === 'agent' ? 'Tú: ' : (c.lastSender === 'system' ? '' : '')) + (c.lastBody || '');
			var chips = [];
			if (!c.assignedTo && c.status !== 'closed') chips.push(el('span', { class: 'chip chip-warn', text: 'Sin asignar' }));
			else if (c.assignedTo && c.assignedTo !== S.me.id && S.filter !== 'mine') chips.push(el('span', { class: 'chip', text: agentName(c.assignedTo) }));
			var item = el('button', {
				class: 'conv' + (c.id === S.openId ? ' is-active' : '') + (c.unread ? ' is-unread' : ''), type: 'button', role: 'listitem',
				onclick: function () { openConversation(c.id); }
			}, [
				el('span', { class: 'conv-avatar' }, [avatar(c.name), online ? el('span', { class: 'presence', title: 'En la tienda ahora' }) : null]),
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
			el('h2', { text: 'Elige una conversación' }),
			el('p', { text: 'Los chats nuevos aparecen en “Sin asignar”. Responde uno para tomarlo.' })
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
		R.text = el('textarea', { class: 'compose-input', rows: '1', placeholder: isMobile() ? 'Escribe una respuesta…' : 'Escribe una respuesta…  ( / para respuestas rápidas)', 'aria-label': 'Respuesta', oninput: onComposeInput, onkeydown: onComposeKey, maxlength: '2000' });
		R.composer = el('form', { class: 'composer', onsubmit: onSend }, [
			R.qr,
			el('button', { class: 'icon-btn', type: 'button', 'aria-label': 'Respuestas rápidas', title: 'Respuestas rápidas', onclick: function () { toggleQuickReplies(true, ''); } }, [icon('bolt')]),
			el('button', { class: 'icon-btn', type: 'button', 'aria-label': 'Adjuntar archivo', title: 'Adjuntar', onclick: function () { R.file.click(); } }, [icon('clip')]),
			R.file,
			R.text,
			el('button', { class: 'send-btn', type: 'submit', 'aria-label': 'Enviar' }, [icon('send')])
		]);
		R.thread.appendChild(R.threadHead);
		R.thread.appendChild(R.assignBar);
		R.thread.appendChild(R.scroller);
		R.thread.appendChild(R.composer);
		renderThreadHeader();
		renderMessages(true);
		renderInfo();
		if (c) R.messages.appendChild(el('div', { class: 'loading-row', text: 'Cargando…' }));
	}

	function renderThreadHeader() {
		var c = S.convs[S.openId];
		if (!c || !R.threadHead) return;
		var seen = S.thread.visitorSeen || c.visitorSeen;
		var online = seen && nowSec() - seen < 40;
		R.threadHead.textContent = '';
		R.threadHead.appendChild(el('button', { class: 'icon-btn back-btn', type: 'button', 'aria-label': 'Volver', onclick: closeThreadMobile }, [icon('back')]));
		R.threadHead.appendChild(el('button', { class: 'head-person', type: 'button', onclick: function () { toggleInfo(); } }, [
			el('span', { class: 'conv-avatar' }, [avatar(c.name), online ? el('span', { class: 'presence' }) : null]),
			el('span', { class: 'head-text' }, [
				el('strong', { text: c.name }),
				el('small', { class: online ? 'is-online' : '', text: S.thread.typing ? 'Escribiendo…' : seenLabel(seen) })
			])
		]));

		var actions = el('div', { class: 'head-actions' });
		if (c.status === 'closed') {
			actions.appendChild(el('button', { class: 'btn btn-ghost', type: 'button', onclick: function () { act('reopen'); } }, ['Reabrir']));
		} else {
			if (c.assignedTo !== S.me.id) {
				actions.appendChild(el('button', { class: 'btn btn-primary', type: 'button', onclick: function () { assign(S.me.id); } }, [el('span', { class: 'btn-long', text: 'Tomar chat' }), el('span', { class: 'btn-short', text: 'Tomar' })]));
			}
			actions.appendChild(el('button', { class: 'btn btn-ghost btn-resolve', type: 'button', title: 'Marcar como resuelto', onclick: function () { act('close'); } }, [icon('check'), el('span', { class: 'btn-label', text: 'Resolver' })]));
		}
		actions.appendChild(el('button', { class: 'icon-btn', type: 'button', 'aria-label': 'Más opciones', onclick: openThreadMenu }, [icon('more')]));
		actions.appendChild(el('button', { class: 'icon-btn info-btn' + (S.showInfo ? ' is-active' : ''), type: 'button', 'aria-label': 'Datos del cliente', onclick: function () { toggleInfo(); } }, [icon('info')]));
		R.threadHead.appendChild(actions);

		// Assignment banner.
		R.assignBar.textContent = '';
		if (c.status !== 'closed' && !c.assignedTo) {
			R.assignBar.hidden = false;
			R.assignBar.appendChild(el('span', { text: 'Este chat no tiene agente asignado. Al responder, quedará asignado a ti.' }));
		} else if (c.status !== 'closed' && c.assignedTo !== S.me.id) {
			R.assignBar.hidden = false;
			R.assignBar.appendChild(el('span', { text: 'Lo está atendiendo ' + agentName(c.assignedTo) + '.' }));
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
				var img = el('img', { src: a.url, alt: a.name || 'Imagen', loading: 'lazy' });
				if (a.w && a.h) { img.width = a.w; img.height = a.h; }
				bubble.appendChild(el('a', { class: 'att-img', href: a.url, target: '_blank', rel: 'noopener' }, [img]));
			} else {
				bubble.appendChild(el('a', { class: 'att-file', href: a.url, target: '_blank', rel: 'noopener' }, [icon('file'), el('span', { text: a.name || 'Archivo' })]));
			}
		}
		if (m.body) bubble.appendChild(el('div', { class: 'text' }, [richText(m.body)]));
		var meta = [m.local ? (m.failed ? 'No enviado' : 'Enviando…') : clock(m.at)];
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
			if (n) n.appendChild(el('span', { class: 'seen', text: ' · Visto' }));
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
			toast(err.message || 'No se pudo enviar');
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
			toast(err.message || 'No se pudo subir el archivo');
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
					'Sin respuestas rápidas' + (query ? ' para “/' + query + '”' : '') + '. ',
					el('button', { type: 'button', class: 'link', onclick: openQuickRepliesManager, text: 'Crear una' })
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
		var body = q.body.replace(/\{nombre\}/gi, (S.convs[S.openId] && S.convs[S.openId].name.split(' ')[0]) || '');
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
		var fKey = el('input', { class: 'field', placeholder: 'atajo (ej. envio)', required: true, maxlength: '60' });
		var fTitle = el('input', { class: 'field', placeholder: 'Título', maxlength: '120' });
		var fBody = el('textarea', { class: 'field', rows: '4', placeholder: 'Texto de la respuesta. Usa {nombre} para el nombre del cliente.', required: true });
		var saveBtn = el('button', { class: 'btn btn-primary', type: 'submit', text: 'Guardar' });
		var cancel = el('button', { class: 'btn btn-ghost', type: 'button', text: 'Nueva', onclick: function () { fId.value = ''; form.reset(); fKey.focus(); } });
		[fId, el('div', { class: 'qr-form-row' }, [fKey, fTitle]), fBody, el('div', { class: 'qr-form-actions' }, [cancel, saveBtn])].forEach(function (n) { form.appendChild(n); });
		function draw(list) {
			listBox.textContent = '';
			list.forEach(function (q) {
				listBox.appendChild(el('div', { class: 'qr-row' }, [
					el('button', { type: 'button', class: 'qr-row-main', onclick: function () { fId.value = q.id; fKey.value = q.shortcut; fTitle.value = q.title; fBody.value = q.body; fBody.focus(); } }, [
						el('span', { class: 'qr-key', text: '/' + q.shortcut }), el('span', { class: 'qr-title', text: q.title || '' }), el('span', { class: 'qr-body', text: q.body })
					]),
					el('button', { type: 'button', class: 'icon-btn danger', 'aria-label': 'Eliminar', onclick: function () {
						if (!confirm('¿Eliminar /' + q.shortcut + '?')) return;
						api('DELETE', 'agent/quick-replies/' + q.id).then(function (l) { S.quickReplies = l; draw(l); });
					} }, [icon('close')])
				]));
			});
		}
		form.addEventListener('submit', function (e) {
			e.preventDefault();
			api('POST', 'agent/quick-replies', { id: fId.value, shortcut: fKey.value, title: fTitle.value || fKey.value, body: fBody.value })
				.then(function (l) { S.quickReplies = l; draw(l); form.reset(); fId.value = ''; toast('Guardado'); })
				.catch(function (err) { toast(err.message); });
		});
		modal('Respuestas rápidas', [el('p', { class: 'muted', text: 'Escribe / en el chat para insertarlas.' }), listBox, form]);
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
			if (action === 'close') { S.convs[id].unread = 0; toast('Chat resuelto'); }
			renderThreadHeader(); renderTabs(); renderList();
			sync();
			if (action === 'close' && isMobile()) closeThreadMobile();
		}).catch(function (e) { toast(e.message); });
	}
	function openThreadMenu(e) {
		var c = S.convs[S.openId];
		if (!c) return;
		var items = [];
		var others = S.agents.filter(function (a) { return a.id !== c.assignedTo; });
		if (c.status !== 'closed') {
			others.forEach(function (a) {
				items.push({ label: (a.id === S.me.id ? 'Asignarme' : 'Transferir a ' + a.name), hint: a.online && a.available ? 'en línea' : '', onClick: function () { assign(a.id); } });
			});
			if (c.assignedTo) items.push({ label: 'Liberar (sin asignar)', onClick: function () { assign(0); } });
		}
		if (c.email) items.push({ label: 'Copiar email', onClick: function () { copy(c.email); } });
		popover(e.currentTarget, items);
	}
	function copy(text) {
		(navigator.clipboard ? navigator.clipboard.writeText(text) : Promise.reject()).then(function () { toast('Copiado'); }).catch(function () { prompt('Copia:', text); });
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
			el('button', { class: 'icon-btn info-close', type: 'button', 'aria-label': 'Cerrar', onclick: function () { toggleInfo(false); } }, [icon(isMobile() ? 'back' : 'close')]),
			el('strong', { text: 'Cliente' })
		]));
		var cust = d && d.customer;
		var card = el('div', { class: 'cust-card' }, [
			avatar(c.name, null, 'xl'),
			el('div', { class: 'cust-name', text: c.name }),
			c.email ? el('a', { class: 'cust-line', href: 'mailto:' + c.email, text: c.email }) : null,
			cust && cust.phone ? el('a', { class: 'cust-line', href: 'tel:' + cust.phone.replace(/[^\d+]/g, ''), text: cust.phone }) : null,
			cust && cust.city ? el('div', { class: 'cust-line muted', text: cust.city }) : null,
			el('div', { class: 'cust-tags' }, [
				el('span', { class: 'chip ' + (cust && cust.registered ? 'chip-ok' : ''), text: cust && cust.registered ? 'Cliente registrado' : 'Invitado' }),
				cust && cust.orderCount ? el('span', { class: 'chip', text: cust.orderCount + (cust.orderCount === 1 ? ' pedido' : ' pedidos') + ' · ' + cust.totalSpent }) : null
			]),
			cust && cust.profileUrl && CFG.canManage ? el('a', { class: 'link small', href: cust.profileUrl, target: '_blank', rel: 'noopener', text: 'Ver perfil en WordPress' }) : null
		]);
		R.info.appendChild(card);

		if (!d) { R.info.appendChild(el('div', { class: 'loading-row', text: 'Cargando datos…' })); return; }
		var ctx = d.context || {};

		if (ctx.page) {
			R.info.appendChild(section('Viendo ahora', [
				el('a', { class: 'page-link', href: ctx.page.url, target: '_blank', rel: 'noopener' }, [el('span', { text: ctx.page.title || ctx.page.url }), icon('ext')]),
				el('div', { class: 'muted small', text: relTime(ctx.page.at) })
			]));
		}

		if (ctx.cart && ctx.cart.items && ctx.cart.items.length) {
			R.info.appendChild(section('Carrito · ' + ctx.cart.total, ctx.cart.items.map(function (it) {
				return el('a', { class: 'cart-item', href: it.url, target: '_blank', rel: 'noopener' }, [
					it.image ? el('img', { src: it.image, alt: '', loading: 'lazy' }) : el('span', { class: 'cart-ph' }),
					el('span', { class: 'cart-text' }, [el('span', { class: 'cart-name', text: it.name }), el('span', { class: 'muted small', text: it.qty + ' × · ' + it.total })])
				]);
			})));
		} else if (ctx.cart) {
			R.info.appendChild(section('Carrito', [el('p', { class: 'muted small', text: 'Vacío' })]));
		}

		if (cust && cust.orders && cust.orders.length) {
			R.info.appendChild(section('Pedidos recientes', cust.orders.map(function (o) {
				return el('a', { class: 'order', href: o.editUrl, target: '_blank', rel: 'noopener' }, [
					el('span', { class: 'order-top' }, [el('strong', { text: '#' + o.number }), el('span', { class: 'status status-' + o.slug, text: o.status })]),
					el('span', { class: 'order-items muted small', text: o.items.join(', ') }),
					el('span', { class: 'order-bottom small' }, [el('span', { text: o.total }), el('span', { class: 'muted', text: new Date(o.date * 1000).toLocaleDateString('es', { day: 'numeric', month: 'short', year: 'numeric' }) })])
				]);
			})));
		} else if (cust) {
			R.info.appendChild(section('Pedidos', [el('p', { class: 'muted small', text: 'Sin pedidos con este email.' })]));
		}

		if (ctx.pages && ctx.pages.length > 1) {
			R.info.appendChild(section('Recorrido', ctx.pages.slice().reverse().map(function (p) {
				return el('a', { class: 'trail', href: p.url, target: '_blank', rel: 'noopener' }, [el('span', { text: p.title || p.url }), el('span', { class: 'muted small', text: relTime(p.at) })]);
			})));
		}

		var tech = [];
		if (ctx.referrer) tech.push(el('div', { class: 'small' }, [el('span', { class: 'muted', text: 'Llegó desde: ' }), ctx.referrer]));
		if (ctx.userAgent) tech.push(el('div', { class: 'small muted', text: describeUA(ctx.userAgent) }));
		tech.push(el('div', { class: 'small muted', text: 'Chat iniciado ' + relTime(c.createdAt) }));
		R.info.appendChild(section('Detalles', tech));
	}
	function describeUA(ua) {
		var os = /iPhone|iPad/.test(ua) ? 'iOS' : /Android/.test(ua) ? 'Android' : /Mac OS/.test(ua) ? 'macOS' : /Windows/.test(ua) ? 'Windows' : /Linux/.test(ua) ? 'Linux' : '';
		var br = /Edg\//.test(ua) ? 'Edge' : /OPR\//.test(ua) ? 'Opera' : /Chrome\//.test(ua) ? 'Chrome' : /Firefox\//.test(ua) ? 'Firefox' : /Safari\//.test(ua) ? 'Safari' : '';
		var mobile = /Mobi|iPhone|Android/.test(ua) ? 'móvil' : 'escritorio';
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
		document.title = (total ? '(' + total + ') ' : '') + CFG.brand + ' · Chats';
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
		$('.avail-text', R.availBtn).textContent = S.me.available ? 'Disponible' : 'Ausente';
		R.availBtn.title = S.me.available ? 'Recibes chats y notificaciones. Toca para ausentarte.' : 'No recibes notificaciones de chats nuevos. Toca para estar disponible.';
	}
	function toggleAvailability() {
		S.me.available = !S.me.available;
		renderAvailability();
		api('POST', 'agent/presence', { available: S.me.available ? 1 : 0 }).then(function (r) {
			S.store = r.store;
			toast(S.me.available ? 'Estás disponible' : 'Te marcaste como ausente');
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
			return toast('Este navegador no soporta notificaciones push.');
		}
		Notification.requestPermission().then(function (perm) {
			if (perm !== 'granted') { toast('Permiso de notificaciones denegado'); renderNotifBanner(); return; }
			return (swReg ? Promise.resolve(swReg) : registerSW()).then(function (reg) {
				return reg.pushManager.getSubscription().then(function (sub) {
					return sub || reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: urlB64ToUint8(CFG.vapid) });
				});
			}).then(saveSubscription).then(function () {
				toast('Notificaciones activadas');
				api('POST', 'agent/push/test', {}).catch(function () {});
				renderNotifBanner();
			});
		}).catch(function (e) { toast('No se pudieron activar: ' + (e.message || e)); });
	}
	function disablePush() {
		if (!swReg) return;
		swReg.pushManager.getSubscription().then(function (sub) {
			if (!sub) return;
			var ep = sub.endpoint;
			return sub.unsubscribe().then(function () { return api('POST', 'agent/push/unsubscribe', { endpoint: ep }); });
		}).then(function () { toast('Notificaciones desactivadas en este dispositivo'); renderNotifBanner(); });
	}
	function renderNotifBanner() {
		pushState().then(function (st) {
			S.pushState = st;
			R.notifBanner.textContent = '';
			if (st === 'off') {
				R.notifBanner.hidden = false;
				R.notifBanner.appendChild(icon('bell'));
				R.notifBanner.appendChild(el('span', { text: 'Activa las notificaciones para enterarte de cada chat aunque cierres la app.' }));
				R.notifBanner.appendChild(el('button', { class: 'btn btn-primary btn-sm', type: 'button', onclick: enablePush, text: 'Activar' }));
			} else if (st === 'ios-install') {
				R.notifBanner.hidden = false;
				R.notifBanner.appendChild(icon('bell'));
				R.notifBanner.appendChild(el('span', { text: 'En iPhone, instala la app en tu pantalla de inicio para recibir notificaciones.' }));
				R.notifBanner.appendChild(el('button', { class: 'btn btn-primary btn-sm', type: 'button', onclick: showIOSInstall, text: 'Cómo' }));
			} else if (st === 'denied') {
				R.notifBanner.hidden = false;
				R.notifBanner.appendChild(icon('bell'));
				R.notifBanner.appendChild(el('span', { text: 'Las notificaciones están bloqueadas. Actívalas en los ajustes del navegador para este sitio.' }));
			} else {
				R.notifBanner.hidden = true;
			}
		});
	}
	function showIOSInstall() {
		modal('Instalar en iPhone / iPad', [
			el('ol', { class: 'steps' }, [
				el('li', { text: 'Abre esta página en Safari.' }),
				el('li', { text: 'Toca el botón Compartir (el cuadrado con la flecha hacia arriba).' }),
				el('li', { text: 'Elige “Agregar a pantalla de inicio”.' }),
				el('li', { text: 'Abre la app desde el icono nuevo y activa las notificaciones.' })
			]),
			el('p', { class: 'muted small', text: 'Requiere iOS 16.4 o posterior.' })
		]);
	}

	window.addEventListener('beforeinstallprompt', function (e) { e.preventDefault(); S.installPrompt = e; });
	window.addEventListener('appinstalled', function () { S.installPrompt = null; toast('App instalada'); });
	function install() {
		if (S.installPrompt) {
			S.installPrompt.prompt();
			S.installPrompt.userChoice.finally(function () { S.installPrompt = null; });
		} else if (isIOS()) showIOSInstall();
		else modal('Instalar la app', [el('p', { text: 'Abre el menú del navegador (⋮) y elige “Instalar app” o “Agregar a pantalla de inicio”. En Chrome de escritorio también aparece un icono de instalación en la barra de direcciones.' })]);
	}

	/* ================================================================== *
	 * Menus, modals, toasts
	 * ================================================================== */
	function openMenu(e) {
		var items = [];
		if (!isStandalone()) items.push({ label: 'Instalar app', onClick: install });
		if (S.pushState === 'on') {
			items.push({ label: 'Enviar notificación de prueba', onClick: function () { api('POST', 'agent/push/test', {}).then(function () { toast('Enviada'); }); } });
			items.push({ label: 'Desactivar notificaciones aquí', onClick: disablePush });
		} else {
			items.push({ label: 'Activar notificaciones', onClick: enablePush });
		}
		items.push({ label: S.sound ? 'Silenciar sonido' : 'Activar sonido', onClick: function () { S.sound = !S.sound; localStorage.setItem('hilo_sound', S.sound ? '1' : '0'); if (S.sound) playSound(); } });
		items.push({ label: 'Respuestas rápidas', onClick: openQuickRepliesManager });
		items.push({ label: 'Equipo', onClick: showTeam });
		if (CFG.adminUrl) items.push({ label: 'Ajustes del chat', href: CFG.adminUrl });
		items.push({ label: 'Cerrar sesión', href: CFG.logout, danger: true });
		popover(e.currentTarget, items);
	}
	function showTeam() {
		var st = S.store;
		modal('Equipo', [
			st ? el('p', { class: 'store-status ' + (st.online ? 'on' : '') }, [el('span', { class: 'dot' }), st.online ? 'El chat se muestra En línea a los clientes.' : (st.inHours ? 'Fuera de línea: no hay agentes disponibles.' : 'Fuera del horario de atención.')]) : null,
			el('div', { class: 'team' }, S.agents.map(function (a) {
				return el('div', { class: 'team-row' }, [avatar(a.name, a.avatar), el('span', { class: 'team-name', text: a.name + (a.id === S.me.id ? ' (tú)' : '') }),
					el('span', { class: 'chip ' + (a.online && a.available ? 'chip-ok' : ''), text: a.online ? (a.available ? 'Disponible' : 'Ausente') : 'Desconectado' })]);
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
				el('header', { class: 'modal-head' }, [el('h2', { text: title }), el('button', { class: 'icon-btn', type: 'button', 'aria-label': 'Cerrar', onclick: function () { close(); } }, [icon('close')])]),
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

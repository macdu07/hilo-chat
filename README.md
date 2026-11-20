# Hilo Chat

*English · [Español](README.es.md)*

Live support chat for WooCommerce that runs entirely on your own WordPress site: a chat widget for customers and an installable agent app (PWA) with push notifications, so your team can answer from a phone or a desktop. No external chat service, no monthly fees, no build step.

## Features

- **Storefront widget** rendered in a Shadow DOM, so theme and page-builder CSS cannot break it. Attachments, typing indicator, "Seen" receipts, an offline mode and a greeting bubble shown once per visit.
- **Agent app (PWA)** at `/chat-agentes/` (configurable), installable on Android, iOS 16.4+ and desktop browsers. Responsive three-pane layout: inbox, conversation and customer details.
- **Web Push notifications** encrypted end to end (VAPID + `aes128gcm`, RFC 8291/8292), implemented with PHP's openssl. No Composer dependencies.
- **Multiple agents** with an **Unassigned → Take / Transfer / Resolve** workflow, availability toggle and presence.
- **WooCommerce context** next to each chat: current page, browsing journey, live cart and the customer's latest orders (HPOS compatible).
- **Quick replies** inserted by typing `/shortcut`; `{name}` is replaced with the customer's first name.
- **Business hours** (weekly schedule, 24 h days or 24/7). Outside hours, or when nobody is available, visitors leave a message and the agent's reply is **emailed** to them with a link to resume the chat.
- **Delete resolved conversations** one by one from the app, all at once (store managers), or automatically after N days.
- **Branding settings**: brand name, colors, fonts, email logo and app icon, with a live widget preview. Defaults are neutral, so the plugin fits any store.
- **Bilingual**: English source strings with a full Spanish translation, used for every Spanish locale (`es_ES`, `es_CO`, `es_MX`…). The widget follows the site language; the agent app follows each agent's profile language.

## Requirements

- WordPress 6.9 or later.
- WooCommerce 11.0 or later.
- PHP 8.1 or later with the openssl extension.
- HTTPS (required for service workers and push notifications).

## Installation

1. Copy the `hilo-chat` folder into `wp-content/plugins/`.
2. Activate **Hilo Chat** from the Plugins screen. It starts in **test mode**: only users who can answer chats see the widget.
3. Open **WooCommerce → Hilo Chat** and set your brand, colors, business hours and app icon.
4. Give the **Chat agent** role to the people who will answer. Administrators and shop managers can answer too.
5. Each agent opens the agent app, installs it (on iPhone: Share → Add to Home Screen) and turns on notifications.
6. Turn off test mode.

## How it works

- **Real time without websockets.** The widget and the app use adaptive polling against small REST endpoints (`hilo-chat/v1`): every 3 s with the chat open, 20 s minimized and 60 s in a background tab. Pages without a conversation never poll. When the agent app is closed, push notifications take over.
- **Visitor identity** is an HTTP-only cookie token; only its hash is stored. Logged-in customers are recognized automatically and can continue on another device.
- **Attachments** (images and PDF, up to 8 MB) are stored in `uploads/hilo-chat/` with random names. Images are re-encoded to at most 1600 px, which also strips EXIF/GPS data.
- **Caching.** All chat responses are sent with `Cache-Control: no-store`. The plugin whitelists its namespace in All-In-One WP Security's REST lock; with other firewalls that block anonymous REST requests, allow `hilo-chat/v1`.
- **Emails** to customers who left are scheduled with WP-Cron. For punctual delivery on low-traffic sites, run a real system cron:

```
wp --path=/path/to/site cron event run --due-now
```

## Extensibility

Open the chat from any link or button:

```html
<a href="#hilo-open">Need help?</a>
<button data-hilo-chat-open>Chat with us</button>
<script>HiloChat.open();</script>
```

Filters:

- `hilo_chat_show_widget( $show )`: hide the widget on specific pages.
- `hilo_chat_app_icons( $icons )`: change the PWA icons.
- `aios_whitelisted_rest_routes`: used internally to allow the REST namespace through All-In-One WP Security.

## Translations

Strings live in `languages/hilo-chat.pot`; the Spanish translation is `languages/hilo-chat-es_ES.po`. Files placed in `wp-content/languages/plugins/` (for example by Loco Translate) take priority. JavaScript strings use `__( 'Text', 'hilo-chat' )`, so `make-pot` extracts them and PHP sends the browser only the translations it needs.

Regenerate after changing strings:

```
wp i18n make-pot . languages/hilo-chat.pot --domain=hilo-chat --exclude=assets/agent/sw.js,assets/admin
wp i18n make-mo languages
wp i18n make-php languages
```

## Project structure

```
hilo-chat.php              Bootstrap and HILO_* constants
includes/class-hilo-*.php  Install, data, visitor/agent REST, push, presence, hours, email, uploads, Woo context, widget, agent app, settings, i18n
templates/                 Agent app shell and reply email
assets/widget/             Storefront widget (JS + CSS for the shadow root)
assets/agent/              Agent app, service worker and default icons
assets/admin/              Settings screen
languages/                 POT and Spanish translation
```

The settings screen shares its design system with [Descuento Global Woo](https://github.com/macdu07/descuento-global-woo); see [DESIGN.md](DESIGN.md).

## Uninstall

Deleting the plugin from WordPress removes its tables, options, role and scheduled events. Uploaded attachments in `uploads/hilo-chat/` are kept, because reply emails may still link to them.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).

## Author

[Mauricio Correa](https://mauriciocorread.com/)

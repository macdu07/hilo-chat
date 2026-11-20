# Hilo Chat

*[English](README.md) · Español*

Chat de soporte en vivo para WooCommerce, sin servicios externos:

- **Widget** para clientes (Shadow DOM, no choca con el CSS del tema), con adjuntos, "escribiendo…", "Visto" y modo sin conexión.
- **Panel de agentes instalable (PWA)** en `/{ruta}/` (por defecto `/chat-agentes/`), para celular y escritorio, con **notificaciones Web Push** cifradas (VAPID + aes128gcm, implementadas con openssl; no requiere Composer).
- Varios agentes con cola **Sin asignar → Tomar / Transferir / Resolver**.
- **Contexto WooCommerce**: página actual, recorrido, carrito y últimos pedidos (compatible con HPOS).
- **Respuestas rápidas** (`/atajo` en el compositor, admite `{name}` para el nombre del cliente).
- **Horario de atención** (también 24/7 o 24 h por día) y respuesta por **email** cuando el cliente ya se fue (con enlace para retomar el chat).
- **Burbuja de saludo** junto al botón, una vez por visita.
- **Opción de WhatsApp**: al activarla, el botón del chat se convierte en un pequeño menú donde el cliente elige el chat en vivo o WhatsApp (tu número, texto del botón y un mensaje predefinido con `{page}` / `{title}`). Si hay Google Tag Manager, cada clic se envía a `dataLayer` como `hilo_whatsapp_click`.
- **Eliminar conversaciones resueltas**, una a una desde la app o todas de una vez (gestores de tienda), además de la purga automática.
- **Bilingüe (inglés / español)**: el código está en inglés y trae traducción `es_ES`, que se usa también para cualquier otra variante de español (`es_CO`, `es_MX`…).

## Requisitos
WordPress 6.9+, WooCommerce 11+, PHP 8.1+ con openssl. Sitio en HTTPS.

## Puesta en marcha
1. Activa el plugin. Queda en **modo prueba**: solo los usuarios con permiso de agente ven el widget.
2. Ve a **WooCommerce → Hilo Chat** y configura marca, colores, tipografías, horario e icono de la app.
3. Da el rol **Agente de chat** a quienes atenderán. Administradores y gestores de tienda ya tienen acceso.
4. Cada agente abre el panel, lo instala (en iPhone: Compartir → Agregar a inicio) y activa las notificaciones.
5. Desactiva el modo prueba.

## Idiomas
- El widget usa el idioma del sitio y la app de agentes, el idioma del perfil de cada agente (Usuarios → Perfil → Idioma).
- Los textos de los ajustes (título, saludo, etc.) se pueden dejar vacíos: así se usa el texto por defecto traducido. Si los escribes, se muestran tal cual.
- Para añadir o corregir traducciones: `languages/hilo-chat.pot` (plantilla) y `languages/hilo-chat-es_ES.po`. Tras editar, compila con `wp i18n make-mo languages && wp i18n make-php languages`. También funciona con Loco Translate (los archivos en `wp-content/languages/plugins/` tienen prioridad).
- Las cadenas del JS se escriben como `__('Text', 'hilo-chat')`; `make-pot` las extrae y PHP envía al navegador solo las traducciones necesarias.

## Integración
- Abrir el chat desde cualquier botón: `<a href="#hilo-open">¿Necesitas ayuda?</a>`, `data-hilo-chat-open` o `HiloChat.open()`. Con WhatsApp activado: `data-hilo-whatsapp` o `HiloChat.whatsapp()`.
- Filtro `hilo_chat_show_widget` (bool) para ocultar el widget en ciertas páginas.
- Filtro `hilo_chat_app_icons` para cambiar los iconos de la PWA.
- Firewalls: el plugin añade `hilo-chat` a la lista blanca REST de All-In-One WP Security. Con otros firewalls que bloqueen la REST a anónimos, permite el namespace `hilo-chat/v1`.

## Notas de operación
- Tiempo real por *polling* adaptativo: 3 s con el chat abierto, 20 s minimizado y 60 s en segundo plano. Sin conversación no hay polling.
- Los emails se programan con WP-Cron. Para que salgan puntuales, configura un cron real del servidor que llame a `wp-cron.php` cada minuto.
- Los adjuntos se guardan en `uploads/hilo-chat/` con nombres aleatorios. Las imágenes se reencodean (sin EXIF/GPS) a un máximo de 1600 px.
- Los chats resueltos se borran tras N días (configurable, 0 = nunca).

## Estructura
```
hilo-chat.php              bootstrap y constantes HILO_*
includes/class-hilo-*.php  instalación, BD, REST visitante/agente, push, presencia, horario, email, adjuntos, contexto Woo, widget, app, ajustes
templates/                 shell de la app y email
assets/widget/             widget.js + widget.css (shadow root)
assets/agent/              app.js, app.css, sw.js, iconos por defecto
```

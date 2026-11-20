# Hilo Chat

Chat de soporte en vivo para WooCommerce, sin servicios externos:

- **Widget** para clientes (Shadow DOM, no choca con el CSS del tema), con adjuntos, "escribiendo…", "Visto" y modo sin conexión.
- **Panel de agentes instalable (PWA)** en `/{ruta}/` (por defecto `/chat-agentes/`), para celular y escritorio, con **notificaciones Web Push** cifradas (VAPID + aes128gcm, implementadas con openssl; no requiere Composer).
- Varios agentes con cola **Sin asignar → Tomar / Transferir / Resolver**.
- **Contexto WooCommerce**: página actual, recorrido, carrito y últimos pedidos (compatible con HPOS).
- **Respuestas rápidas** (`/atajo` en el compositor, admite `{nombre}`).
- **Horario de atención** y respuesta por **email** cuando el cliente ya se fue (con enlace para retomar el chat).

## Requisitos
WordPress 6.9+, WooCommerce 11+, PHP 8.1+ con openssl. Sitio en HTTPS.

## Puesta en marcha
1. Activa el plugin. Queda en **modo prueba**: solo los usuarios con permiso de agente ven el widget.
2. Ve a **WooCommerce → Hilo Chat** y configura marca, colores, tipografías, horario e icono de la app.
3. Da el rol **Agente de chat** a quienes atenderán. Administradores y gestores de tienda ya tienen acceso.
4. Cada agente abre el panel, lo instala (en iPhone: Compartir → Agregar a inicio) y activa las notificaciones.
5. Desactiva el modo prueba.

## Integración
- Abrir el chat desde cualquier botón: `<a href="#hilo-open">¿Necesitas ayuda?</a>`, `data-hilo-chat-open` o `HiloChat.open()`.
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

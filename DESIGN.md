# Design System: Hilo Chat (settings screen)

The settings screen belongs to the same family as **Descuento Global Woo**: same owner palette, same components, same rules. Read that plugin's `DESIGN.md` for the full rationale; this file only records what Hilo adds or adapts.

Scope: only the wp-admin settings page (`assets/admin/`). The storefront widget and the agent app follow each store's own brand colors from the settings.

## Shared tokens (identical to Descuento Global)
- Ground **cream** `#f8f3ea`, surfaces **warm paper** `#fffdf9`, kraft **stub** `#f1e9db`, hairlines **tan** `#e2d8c6` / `#cfc3ae`.
- **Gunmetal** `#1d2730` for structure and every command: header slab, primary buttons, switch on, focus rings, native radios/checkboxes (via `--wp-admin-theme-color`).
- **Turquoise** `#00aeae` (text `#00706f`, wash `#dcf3f2`) means **live right now** and nothing else.
- **Orange** (text `#ad4214`, wash `#ffeadf`) means **attention / not yet public**.
- **Red** (text `#c0262a`, wash `#fde6e5`, line `#f5b5b4`) means **destroy**.
- System sans, tabular numerals, 4px rhythm, radii 14 / 10 / 8 / 6, flat (borders and tint, no shadows), 150ms ease-out color transitions only.

## How the state language maps to chat
- Header stamp: **Online now** (turquoise) when the widget shows "online" to customers; **Test mode** (orange) while only agents see it; **Offline** / **Hidden** (slate, no fill) otherwise.
- Agents list: turquoise dot + label only for agents that are available and connected.
- Test mode banner uses the orange wash; success banners use paper. Destructive actions (delete resolved conversations) live in a red-line danger box and confirm before submitting.

## Components added in Hilo
- **Toggle fields**: hidden checkbox + the shared 42×24 switch, bold title and slate explanation.
- **Choice tiles** for the widget position, with a tiny corner diagram; selected tile gets the kraft fill and inset gunmetal stroke (same as scope options).
- **Week schedule**: one bordered list, a row per day with its switch, a "24 h" checkbox and the time range. Closed days show "Closed"; the whole list dims when "Open 24 hours" is on.
- **Color swatches** with hex code, and a **live preview** of the widget on a kraft hatched canvas that reflects colors, fonts, title, greeting and position as you type. The preview is the only place where the store's brand colors appear on this screen.
- **Sticky save bar** on cream; its note turns burnt orange when there are unsaved changes (and the page warns before leaving).
- **Aside**: app card (icon, URL, open / copy link), agents list, danger box. Sticky on wide screens, below the form under 1100px.

## Layout
Max width 1180px. Header → feedback → two columns (`1fr | 320px`, 20px gap). At 1100px the aside drops below the form; at 782px header stacks, field grids go to one column, panels pad 20px and the time range wraps under each day.

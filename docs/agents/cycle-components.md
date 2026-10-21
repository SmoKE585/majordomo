# Cycle status and control components

Reusable cycle UI for module pages and X-Ray.

## What is available

- `MDJUI::cycleCard()` — server-rendered cycle widget.
  - `compact => true` — compact strip for a module page (MQTT, etc.).
  - `compact => false` — full card (alternative for a standalone page).
- `md-cycle-table` — row/table layout for the X-Ray services list.
- `resources/js/modules/admin_panel/119_cycle_widget.js` — polling, AJAX commands and live state updates for both variants.

## Server component

```php
<?= MDJUI::cycleCard([
    'name' => 'cycle_mqtt',
    'label' => 'Состояние цикла',
    'status' => 'stopped',          // running|starting|stopping|hang|stopped|unknown
    'status_text' => 'Остановлен',
    'online' => false,
    'updated' => '08.07.2020 17:55:07',
    'auto_restart' => false,        // null hides the row
    'compact' => true,
    'poll' => true,
    'endpoint' => ROOTHTML . 'ajax/xray.html?action=service',
    'log_url' => '',                // optional link; omit for no Log action
]) ?>
```

Rendering rules:

- stopped/unknown — only **Запуск**.
- running/starting/stopping/hang — **Рестарт** and **Остановить**.
- status badge and signal dot are both driven by the state; do not hardcode colors in module templates.
- compact strip is limited to `500px` so it does not turn into an empty full-width card.
- full card shows label, details, updated and auto-restart; compact shows name, state, online, updated and actions.

## X-Ray table

`public/assets/modules/xray/xray.js` renders:

```html
<div class="md-cycle-table">
  <div class="md-cycle-table__head">…</div>
  <div class="md-cycle-table__row is-stopped" data-md-cycle
       data-md-cycle-name="cycle_mqtt"
       data-md-cycle-endpoint="/ajax/xray.html?action=service"
       data-md-cycle-poll="0">
    … идентификация / состояние / обновлено / действия …
  </div>
</div>
```

Columns: **цикл**, **состояние**, **обновлено**, **действия**. On `≤900px` the header is hidden and each row folds into two lines. Actions are icon buttons with `title`/`aria-label`.

## Data attributes

- `data-md-cycle` — marks a widget/row for the shared controller.
- `data-md-cycle-name` — cycle name sent to the endpoint.
- `data-md-cycle-endpoint` — JSON endpoint; default is `ROOTHTML + ajax/xray.html?action=service`.
- `data-md-cycle-poll="1|0"` — enable/disable periodic refresh. X-Ray rows are refreshed by the page poller; the module mini widget polls itself.
- `data-md-cycle-command="start|stop|restart"` — action buttons.
- `data-md-cycle-status` — status badge.
- `data-md-cycle-online` — ONLINE/OFFLINE indicator.
- `data-md-cycle-updated` — last activity text.

## Backend contract

`modules/xray/xray.class.php::service_control()` returns:

```json
{
  "cycle": "cycle_mqtt",
  "STATUS": "running",
  "STATUS_TEXT": "Работает",
  "UPDATED": 1594216507,
  "ONLINE": 1,
  "BODY": "…legacy summary…"
}
```

Commands are queued through the same endpoint (`op=start|stop|restart`). Do not create a second control API for a module; pass the same endpoint to the component.

## Files

- `lib/ui/MDJUI.php` — `cycleCard()`.
- `templates/ui/components/cycle-card.php` — markup.
- `resources/css/ui-system.css` — `.md-cycle-card`, `.md-cycle-table`, states, responsive rules.
- `resources/js/modules/admin_panel/119_cycle_widget.js` — controller.
- `templates/xray/xray.html` — X-Ray layout / service action.
- `public/assets/modules/xray/xray.js` — X-Ray table rows.
- `modules/xray/xray.class.php` — initial state + JSON endpoint.

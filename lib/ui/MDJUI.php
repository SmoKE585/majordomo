<?php
/**
 * MDJUI — server-side UI component library for the MajorDoMo admin.
 *
 * The class is a small, Blade-like component engine adapted to MajorDoMo's
 * plain-PHP stack. Each component lives in templates/ui/components/<name>.php
 * and receives `$props` plus an optional `$slot` string. The public methods
 * below are the canonical API: they keep markup, variants, ARIA attributes and
 * design-system classes in one place.
 *
 * Example:
 *   echo MDJUI::button('Сохранить', ['variant' => 'primary', 'icon' => 'check']);
 *
 * Components are server-rendered on purpose: the admin is PHP-first and most
 * pages already render their markup on the server. Alpine.js is used only for
 * local interactive state.
 */

class MDJUI
{
    /** @var array<string, array<string, mixed>> */
    private static $demoData = array();

    /** @var array<string, string>|null */
    private static $iconCatalog = null;

    /**
     * Legacy Bootstrap Icons names used by older UI-kit data and modules.
     * New code should pass Tabler names directly (e.g. `device-lightbulb`).
     */
    private static $iconAliases = array(
        'activity' => 'activity',
        'archive' => 'archive',
        'arrow-clockwise' => 'refresh',
        'arrow-down' => 'arrow-down',
        'arrow-down-right' => 'arrow-down-right',
        'arrow-down-up' => 'arrows-up-down',
        'arrow-left' => 'arrow-left',
        'arrow-repeat' => 'repeat',
        'arrow-up' => 'arrow-up',
        'arrow-up-right' => 'arrow-up-right',
        'bar-chart' => 'chart-bar',
        'bell' => 'bell',
        'box-arrow-right' => 'logout',
        'box-arrow-up-right' => 'external-link',
        'box-seam' => 'box-seam',
        'braces' => 'braces',
        'brightness-high' => 'sun-high',
        'broadcast' => 'broadcast',
        'calendar' => 'calendar',
        'calendar-check' => 'calendar-check',
        'chat-dots' => 'messages',
        'chat-square-text' => 'message',
        'check-circle' => 'circle-check',
        'check2' => 'check',
        'check2-circle' => 'circle-check',
        'chevron-down' => 'chevron-down',
        'chevron-up' => 'chevron-up',
        'clipboard' => 'clipboard',
        'clock' => 'clock',
        'clock-history' => 'history',
        'cloud-arrow-up' => 'cloud-upload',
        'code-slash' => 'code',
        'command' => 'command',
        'cpu' => 'cpu',
        'cup-hot' => 'coffee',
        'cursor' => 'pointer',
        'dash-lg' => 'minus',
        'database' => 'database',
        'diagram-2' => 'hierarchy-2',
        'diagram-3' => 'hierarchy',
        'door-open' => 'door',
        'droplet' => 'droplet',
        'droplet-half' => 'droplet-half',
        'exclamation-circle' => 'alert-circle',
        'exclamation-triangle' => 'alert-triangle',
        'eye' => 'eye',
        'eye-slash' => 'eye-off',
        'fan' => 'propeller',
        'file-earmark' => 'file',
        'file-text' => 'file-text',
        'film' => 'movie',
        'fire' => 'flame',
        'floppy' => 'device-floppy',
        'gear' => 'settings',
        'geo-alt' => 'map-pin',
        'graph-up' => 'chart-line',
        'grid' => 'layout-grid',
        'grid-1x2' => 'layout-grid',
        'hand-index-thumb' => 'hand-click',
        'hdd-network' => 'server',
        'hourglass-split' => 'hourglass',
        'house' => 'home',
        'house-door' => 'home',
        'house-gear' => 'home-cog',
        'info-circle' => 'info-circle',
        'input-cursor-text' => 'forms',
        'keyboard' => 'keyboard',
        'lamp' => 'lamp',
        'layout-sidebar-right' => 'layout-sidebar-right',
        'layout-text-window' => 'layout',
        'lightbulb' => 'bulb',
        'lightbulb-off' => 'bulb-off',
        'lightning-charge' => 'bolt',
        'link-45deg' => 'link',
        'list' => 'list',
        'list-check' => 'list-check',
        'magic' => 'wand',
        'moon' => 'moon',
        'moon-stars' => 'moon-stars',
        'palette' => 'palette',
        'pause-fill' => 'player-pause',
        'people' => 'users',
        'person-walking' => 'walk',
        'play-circle' => 'player-play',
        'play-fill' => 'player-play',
        'plug' => 'plug',
        'plus-lg' => 'plus',
        'puzzle' => 'puzzle',
        'question-circle' => 'help-circle',
        'reception-0' => 'antenna-bars-1',
        'reception-1' => 'antenna-bars-2',
        'reception-2' => 'antenna-bars-3',
        'reception-3' => 'antenna-bars-4',
        'reception-4' => 'antenna-bars-5',
        'rulers' => 'ruler-measure',
        'search' => 'search',
        'shield-check' => 'shield-check',
        'shield-lock' => 'shield-lock',
        'shop' => 'shopping-bag',
        'signpost-split' => 'route',
        'sliders' => 'adjustments-horizontal',
        'snow' => 'snowflake',
        'sun' => 'sun',
        'sunrise' => 'sunrise',
        'table' => 'table',
        'terminal' => 'terminal-2',
        'thermometer-half' => 'temperature',
        'three-dots' => 'dots',
        'trash3' => 'trash',
        'upload' => 'upload',
        'wallet' => 'wallet',
        'wallet2' => 'wallet',
        'wifi' => 'wifi',
        'wind' => 'wind',
        'x-circle' => 'circle-x',
        'x-lg' => 'x',
        'x-octagon' => 'alert-octagon',
    );

    // ---------------------------------------------------------------------
    // Engine
    // ---------------------------------------------------------------------

    public static function render($component, array $props = array(), $slot = '')
    {
        $component = preg_replace('/[^a-z0-9_-]/i', '', (string)$component);
        $file = ROOT . 'templates/ui/components/' . $component . '.php';

        if (!is_file($file)) {
            return '<!-- MDJUI component not found: ' . self::e($component) . ' -->';
        }

        ob_start();
        include($file);
        return trim(ob_get_clean());
    }

    public static function e($value)
    {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Builds an attribute string from an associative array.
     * `true` renders a boolean attribute, `null`/`false` skips it.
     */
    public static function attrs(array $attrs = array())
    {
        $out = '';
        foreach ($attrs as $name => $value) {
            $name = trim((string)$name);
            if ($name === '' || $value === null || $value === false) {
                continue;
            }
            if ($value === true) {
                $out .= ' ' . $name;
                continue;
            }
            $out .= ' ' . $name . '="' . self::e($value) . '"';
        }
        return $out;
    }

    public static function cx(array $classes)
    {
        $flat = array();
        foreach ($classes as $class) {
            if (is_array($class)) {
                foreach ($class as $nested) {
                    if ($nested !== null && $nested !== '') {
                        $flat[] = $nested;
                    }
                }
                continue;
            }
            if ($class !== null && $class !== '') {
                $flat[] = $class;
            }
        }
        return implode(' ', array_unique($flat));
    }

    /**
     * Russian plural form helper: 1 запуск, 2 запуска, 5 запусков.
     */
    public static function plural($number, $one, $few, $many)
    {
        $number = abs((int)$number);
        $mod10 = $number % 10;
        $mod100 = $number % 100;
        if ($mod10 === 1 && $mod100 !== 11) {
            return $one;
        }
        if ($mod10 >= 2 && $mod10 <= 4 && ($mod100 < 12 || $mod100 > 14)) {
            return $few;
        }
        return $many;
    }

    /**
     * Resolves legacy Bootstrap Icons names to the canonical Tabler name.
     */
    public static function iconName($name)
    {
        $name = strtolower(trim((string)$name));
        for ($i = 0; $i < 3; $i++) {
            $stripped = preg_replace('/^(?:bi|ti)[\s-]+/i', '', $name);
            if ($stripped === $name) {
                break;
            }
            $name = $stripped;
        }
        $name = str_replace(array('_', ' '), '-', $name);
        $name = preg_replace('/[^a-z0-9-]/', '', $name);
        if (isset(self::$iconAliases[$name])) {
            return self::$iconAliases[$name];
        }
        return $name;
    }

    /**
     * Full Tabler Icons catalog generated by `npm run icons`.
     *
     * @return string[]
     */
    public static function iconCatalog()
    {
        if (self::$iconCatalog !== null) {
            return self::$iconCatalog;
        }

        $file = ROOT . 'lib/ui/tabler-icons.json';
        $catalog = array();
        if (is_file($file)) {
            $decoded = json_decode((string)file_get_contents($file), true);
            if (is_array($decoded)) {
                foreach ($decoded as $icon) {
                    if (is_string($icon) && preg_match('/^[a-z0-9-]+$/', $icon)) {
                        $catalog[] = $icon;
                    }
                }
            }
        }

        self::$iconCatalog = $catalog;
        return self::$iconCatalog;
    }

    public static function icon($name, array $props = array())
    {
        $name = self::iconName($name);
        if ($name === '') {
            return '';
        }
        $class = trim('ti ti-' . $name . ' ' . (isset($props['class']) ? $props['class'] : ''));
        return '<i class="' . self::e($class) . '" aria-hidden="true"' . self::attrs(isset($props['attrs']) ? $props['attrs'] : array()) . '></i>';
    }

    // ---------------------------------------------------------------------
    // Buttons and actions
    // ---------------------------------------------------------------------

    public static function button($label = '', array $props = array())
    {
        return self::render('button', array_merge($props, array('label' => $label)));
    }

    public static function iconButton($icon, array $props = array())
    {
        return self::render('icon-button', array_merge($props, array('icon' => $icon)));
    }

    public static function buttonGroup($slot, array $props = array())
    {
        return self::render('button-group', $props, $slot);
    }

    // ---------------------------------------------------------------------
    // Status and identity
    // ---------------------------------------------------------------------

    public static function badge($label, $tone = 'neutral', array $props = array())
    {
        return self::render('badge', array_merge($props, array('label' => $label, 'tone' => $tone)));
    }

    public static function status($state, $label = '', array $props = array())
    {
        return self::render('status', array_merge($props, array('state' => $state, 'label' => $label)));
    }

    public static function avatar($name, array $props = array())
    {
        return self::render('avatar', array_merge($props, array('name' => $name)));
    }

    public static function avatarGroup(array $people, array $props = array())
    {
        return self::render('avatar-group', array_merge($props, array('people' => $people)));
    }

    // ---------------------------------------------------------------------
    // Containers
    // ---------------------------------------------------------------------

    public static function card(array $props = array(), $slot = '')
    {
        return self::render('card', $props, $slot);
    }

    public static function drawer(array $props = array(), $slot = '')
    {
        return self::render('drawer', $props, $slot);
    }

    /**
     * Read-only folder picker dialog. The endpoint must return the JSON shape
     * produced by MajorDoMo\Core\Filesystem\FolderBrowser::listing().
     */
    public static function folderPicker(array $props = array())
    {
        return self::render('folder-picker', $props);
    }

    public static function kpi(array $props = array())
    {
        return self::render('kpi', $props);
    }

    public static function sectionHeading(array $props = array())
    {
        return self::render('section-heading', $props);
    }

    public static function emptyState(array $props = array())
    {
        return self::render('empty-state', $props);
    }

    public static function skeleton(array $props = array())
    {
        return self::render('skeleton', $props);
    }

    public static function progress($value, array $props = array())
    {
        return self::render('progress', array_merge($props, array('value' => $value)));
    }

    public static function meter(array $props = array())
    {
        return self::render('meter', $props);
    }

    public static function chartCard(array $props = array(), $slot = '')
    {
        return self::render('chart-card', $props, $slot);
    }

    public static function historyDrawer(array $props = array())
    {
        return self::render('history-drawer', $props);
    }

    /**
     * Always-on property trace drawer.
     *
     * Renders a hidden template mounted into the shared drawer host by
     * `41_trace.js`. The server endpoint is selected from the trigger's
     * `data-trace-object-id` / `data-trace-property-id`; a demo payload can be
     * passed through `demo_data` for the UI Kit.
     */
    public static function traceDrawer(array $props = array())
    {
        return self::render('trace-drawer', $props);
    }

    /**
     * Activity feed / timeline of actions.
     *
     * @param array $props items|groups, title, subtitle, eyebrow, actions,
     *                     footer, limit, more_href, compact, empty_text
     */
    public static function activityFeed(array $props = array())
    {
        return self::render('activity-feed', $props);
    }

    /**
     * Contextual hint / note block.
     *
     * @param array $props tone, title, text, points, steps, actions, link,
     *                     dismissible, compact
     */
    public static function hint(array $props = array(), $slot = '')
    {
        return self::render('hint', $props, $slot);
    }

    /**
     * Read-only code sample with highlighting and copy.
     *
     * @param array $props language+code or tabs, title, caption, line_numbers,
     *                     max_height, copy
     */
    public static function codeSample(array $props = array())
    {
        return self::render('code-sample', $props);
    }

    // ---------------------------------------------------------------------
    // Forms
    // ---------------------------------------------------------------------

    public static function field(array $props = array(), $slot = '')
    {
        return self::render('field', $props, $slot);
    }

    public static function input(array $props = array())
    {
        return self::render('input', $props);
    }

    public static function textarea(array $props = array())
    {
        return self::render('textarea', $props);
    }

    public static function select(array $props = array())
    {
        return self::render('select', $props);
    }

    public static function checkbox(array $props = array())
    {
        return self::render('checkbox', $props);
    }

    public static function radio(array $props = array())
    {
        return self::render('radio', $props);
    }

    public static function choiceGroup(array $props = array())
    {
        return self::render('choice-group', $props);
    }

    public static function switchControl(array $props = array())
    {
        return self::render('switch', $props);
    }

    public static function range(array $props = array())
    {
        return self::render('range', $props);
    }

    // ---------------------------------------------------------------------
    // Feedback
    // ---------------------------------------------------------------------

    public static function alert($type, $title, $text = '', array $props = array())
    {
        return self::render('alert', array_merge($props, array('type' => $type, 'title' => $title, 'text' => $text)));
    }

    // ---------------------------------------------------------------------
    // Smart home widgets
    // ---------------------------------------------------------------------

    public static function deviceCard(array $props = array())
    {
        return self::render('device-card', $props);
    }

    public static function sensor(array $props = array())
    {
        return self::render('sensor', $props);
    }

    public static function thermostat(array $props = array())
    {
        return self::render('thermostat', $props);
    }

    public static function lightControl(array $props = array())
    {
        return self::render('light-control', $props);
    }

    public static function sceneCard(array $props = array())
    {
        return self::render('scene-card', $props);
    }

    public static function energyWidget(array $props = array())
    {
        return self::render('energy-widget', $props);
    }

    public static function sparkline(array $props = array())
    {
        return self::render('sparkline', $props);
    }

    public static function connectionStatus(array $props = array())
    {
        return self::render('connection-status', $props);
    }

    public static function lastSeen(array $props = array())
    {
        return self::render('last-seen', $props);
    }

    public static function roomCard(array $props = array())
    {
        return self::render('room-card', $props);
    }

    public static function automationRow(array $props = array())
    {
        return self::render('automation-row', $props);
    }

    public static function cycleCard(array $props = array())
    {
        return self::render('cycle-card', $props);
    }

    // ---------------------------------------------------------------------
    // Demo data for the UI Kit page
    // ---------------------------------------------------------------------

    public static function demoData()
    {
        if (!empty(self::$demoData)) {
            return self::$demoData;
        }

        $devices = array(
            array(
                'id' => 'kitchen-light',
                'name' => 'Кухня · Основной свет',
                'room' => 'Кухня',
                'type' => 'Свет',
                'icon' => 'bulb',
                'state' => 'online',
                'state_label' => 'Онлайн',
                'value' => '72%',
                'value_label' => 'Яркость',
                'tone' => 'warning',
                'updated' => '12 сек назад',
                'connection' => 'Zigbee',
                'signal' => 92,
                'on' => true,
                'brightness' => 72,
            ),
            array(
                'id' => 'living-thermostat',
                'name' => 'Гостиная · Термостат',
                'room' => 'Гостиная',
                'type' => 'Климат',
                'icon' => 'temperature',
                'state' => 'online',
                'state_label' => 'Онлайн',
                'value' => '22,5 °C',
                'value_label' => 'Сейчас',
                'tone' => 'primary',
                'updated' => '4 сек назад',
                'connection' => 'Wi-Fi',
                'signal' => 86,
                'on' => true,
                'current' => 22.5,
                'target' => 23,
            ),
            array(
                'id' => 'bedroom-sensor',
                'name' => 'Спальня · Датчик климата',
                'room' => 'Спальня',
                'type' => 'Датчик',
                'icon' => 'droplet-half',
                'state' => 'warning',
                'state_label' => 'Низкий заряд',
                'value' => '48%',
                'value_label' => 'Влажность',
                'tone' => 'info',
                'updated' => '1 мин назад',
                'connection' => 'Zigbee',
                'signal' => 64,
                'on' => true,
            ),
            array(
                'id' => 'hall-lock',
                'name' => 'Прихожая · Замок',
                'room' => 'Прихожая',
                'type' => 'Безопасность',
                'icon' => 'shield-lock',
                'state' => 'offline',
                'state_label' => 'Офлайн',
                'value' => 'Закрыт',
                'value_label' => 'Состояние',
                'tone' => 'neutral',
                'updated' => '18 мин назад',
                'connection' => 'Z-Wave',
                'signal' => 0,
                'on' => false,
            ),
            array(
                'id' => 'office-co2',
                'name' => 'Кабинет · CO₂',
                'room' => 'Кабинет',
                'type' => 'Датчик',
                'icon' => 'wind',
                'state' => 'error',
                'state_label' => 'Ошибка',
                'value' => '—',
                'value_label' => 'Нет данных',
                'tone' => 'danger',
                'updated' => '2 ч назад',
                'connection' => 'MQTT',
                'signal' => 12,
                'on' => false,
            ),
            array(
                'id' => 'bath-fan',
                'name' => 'Ванная · Вытяжка',
                'room' => 'Ванная',
                'type' => 'Вентиляция',
                'icon' => 'propeller',
                'state' => 'online',
                'state_label' => 'Онлайн',
                'value' => 'Выкл',
                'value_label' => 'Питание',
                'tone' => 'neutral',
                'updated' => '34 сек назад',
                'connection' => 'Wi-Fi',
                'signal' => 78,
                'on' => false,
            ),
        );

        $scenes = array(
            array(
                'name' => 'Доброе утро',
                'description' => 'Свет 40%, шторы открыты, кофеварка',
                'icon' => 'sunrise',
                'tone' => 'warning',
                'schedule' => 'Пн–Пт · 07:00',
                'state' => 'online',
                'state_label' => 'Активна',
                'runs' => '18 запусков',
                'enabled' => true,
            ),
            array(
                'name' => 'Ухожу',
                'description' => 'Выключить свет, закрыть замки, охрана',
                'icon' => 'logout',
                'tone' => 'primary',
                'schedule' => 'По геозоне',
                'state' => 'online',
                'state_label' => 'Активна',
                'runs' => '7 запусков',
                'enabled' => true,
            ),
            array(
                'name' => 'Кино',
                'description' => 'Свет 12%, шторы закрыты, проектор',
                'icon' => 'movie',
                'tone' => 'info',
                'schedule' => 'Ручной запуск',
                'state' => 'idle',
                'state_label' => 'Пауза',
                'runs' => '3 запуска',
                'enabled' => false,
            ),
            array(
                'name' => 'Ночной режим',
                'description' => 'Ночники 8%, климат 19 °C, тишина',
                'icon' => 'moon-stars',
                'tone' => 'neutral',
                'schedule' => 'Ежедневно · 23:30',
                'state' => 'warning',
                'state_label' => 'Проверить',
                'runs' => '42 запуска',
                'enabled' => true,
            ),
        );

        $tableRows = array(
            array(
                'id' => 101,
                'name' => 'Кухня · Основной свет',
                'icon' => 'bulb',
                'class' => 'Свет',
                'room' => 'Кухня',
                'state' => 'online',
                'state_label' => 'Онлайн',
                'signal' => 92,
                'updated' => '12 сек назад',
                'value' => '72%',
            ),
            array(
                'id' => 102,
                'name' => 'Гостиная · Термостат',
                'icon' => 'temperature',
                'class' => 'Климат',
                'room' => 'Гостиная',
                'state' => 'online',
                'state_label' => 'Онлайн',
                'signal' => 86,
                'updated' => '4 сек назад',
                'value' => '22,5 °C',
            ),
            array(
                'id' => 103,
                'name' => 'Спальня · Датчик климата',
                'icon' => 'droplet-half',
                'class' => 'Датчик',
                'room' => 'Спальня',
                'state' => 'warning',
                'state_label' => 'Низкий заряд',
                'signal' => 64,
                'updated' => '1 мин назад',
                'value' => '48%',
            ),
            array(
                'id' => 104,
                'name' => 'Прихожая · Замок',
                'icon' => 'shield-lock',
                'class' => 'Безопасность',
                'room' => 'Прихожая',
                'state' => 'offline',
                'state_label' => 'Офлайн',
                'signal' => 0,
                'updated' => '18 мин назад',
                'value' => 'Закрыт',
            ),
            array(
                'id' => 105,
                'name' => 'Кабинет · CO₂',
                'icon' => 'wind',
                'class' => 'Датчик',
                'room' => 'Кабинет',
                'state' => 'error',
                'state_label' => 'Ошибка',
                'signal' => 12,
                'updated' => '2 ч назад',
                'value' => '—',
            ),
            array(
                'id' => 106,
                'name' => 'Ванная · Вытяжка',
                'icon' => 'propeller',
                'class' => 'Вентиляция',
                'room' => 'Ванная',
                'state' => 'online',
                'state_label' => 'Онлайн',
                'signal' => 78,
                'updated' => '34 сек назад',
                'value' => 'Выкл',
            ),
            array(
                'id' => 107,
                'name' => 'Коридор · Диммер',
                'icon' => 'sun-high',
                'class' => 'Свет',
                'room' => 'Коридор',
                'state' => 'online',
                'state_label' => 'Онлайн',
                'signal' => 81,
                'updated' => '2 мин назад',
                'value' => '35%',
            ),
            array(
                'id' => 108,
                'name' => 'Балкон · Датчик движения',
                'icon' => 'walk',
                'class' => 'Безопасность',
                'room' => 'Балкон',
                'state' => 'offline',
                'state_label' => 'Офлайн',
                'signal' => 0,
                'updated' => '3 ч назад',
                'value' => 'Нет движения',
            ),
        );

        $rooms = array(
            array(
                'name' => 'Гостиная',
                'icon' => 'lamp',
                'devices' => 5,
                'online' => 5,
                'temperature' => '22,5 °C',
                'humidity' => '44%',
                'active' => 'Свет 72% · Термостат 23 °C',
                'state' => 'online',
            ),
            array(
                'name' => 'Кухня',
                'icon' => 'coffee',
                'devices' => 4,
                'online' => 3,
                'temperature' => '23,1 °C',
                'humidity' => '51%',
                'active' => 'Свет 100% · Вытяжка выкл',
                'state' => 'warning',
            ),
            array(
                'name' => 'Спальня',
                'icon' => 'moon',
                'devices' => 3,
                'online' => 3,
                'temperature' => '20,8 °C',
                'humidity' => '48%',
                'active' => 'Свет 0% · Климат 19 °C',
                'state' => 'online',
            ),
            array(
                'name' => 'Прихожая',
                'icon' => 'door',
                'devices' => 2,
                'online' => 1,
                'temperature' => '21,4 °C',
                'humidity' => '46%',
                'active' => 'Замок офлайн · Свет 0%',
                'state' => 'error',
            ),
        );

        $paletteItems = array(
            array('group' => 'Перейти', 'label' => 'Объекты', 'hint' => 'Список объектов и свойств', 'icon' => 'box-seam', 'href' => '?action=objects', 'kbd' => 'O'),
            array('group' => 'Перейти', 'label' => 'Классы', 'hint' => 'Классы, методы и свойства', 'icon' => 'hierarchy', 'href' => '?action=classes', 'kbd' => 'C'),
            array('group' => 'Перейти', 'label' => 'Сценарии', 'hint' => 'Автоматизации и расписания', 'icon' => 'code', 'href' => '?action=scripts', 'kbd' => 'S'),
            array('group' => 'Перейти', 'label' => 'Настройки', 'hint' => 'Система и модули', 'icon' => 'adjustments-horizontal', 'href' => '?action=settings', 'kbd' => 'N'),
            array('group' => 'Действия', 'label' => 'Запустить сцену «Доброе утро»', 'hint' => 'Кухня, гостиная, спальня', 'icon' => 'player-play', 'action' => 'scene', 'kbd' => '↵'),
            array('group' => 'Действия', 'label' => 'Выключить весь свет', 'hint' => '12 устройств', 'icon' => 'bulb-off', 'action' => 'lights-off', 'kbd' => 'L'),
            array('group' => 'Действия', 'label' => 'Обновить состояние устройств', 'hint' => 'MQTT · Zigbee · Z-Wave', 'icon' => 'refresh', 'action' => 'refresh', 'kbd' => 'R'),
            array('group' => 'Настройки', 'label' => 'Открыть UI Kit', 'hint' => 'Документация дизайн-системы', 'icon' => 'palette', 'href' => '?action=ui_kit', 'kbd' => 'U'),
        );

        $automations = array(
            array('name' => 'Свет по движению', 'description' => 'Коридор · датчик движения', 'state' => 'online', 'state_label' => 'Работает', 'last' => '2 мин назад', 'icon' => 'walk', 'runs' => 128),
            array('name' => 'Проветривание', 'description' => 'Кухня · CO₂ > 900 ppm', 'state' => 'online', 'state_label' => 'Работает', 'last' => '18 мин назад', 'icon' => 'wind', 'runs' => 24),
            array('name' => 'Ночная подсветка', 'description' => 'Коридор · 23:00–06:00', 'state' => 'idle', 'state_label' => 'Выключена', 'last' => 'вчера', 'icon' => 'moon-stars', 'runs' => 42),
            array('name' => 'Протечка воды', 'description' => 'Ванная · аварийный сценарий', 'state' => 'error', 'state_label' => 'Ошибка', 'last' => '3 ч назад', 'icon' => 'droplet', 'runs' => 0),
        );

        $activity = array(
            array(
                'date' => 'Сегодня',
                'title' => 'Кухня · Основной свет',
                'text' => 'Яркость изменена с 0% на 72% сценарием «Доброе утро».',
                'time' => '2 мин назад',
                'datetime' => '2026-10-22 14:02:00',
                'icon' => 'bulb',
                'tone' => 'primary',
                'actor' => 'Сценарий «Доброе утро»',
                'meta' => array('Zigbee', array('text' => 'Кухня', 'icon' => 'map-pin')),
                'badge' => 'Выполнено',
                'badge_tone' => 'success',
                'href' => '?action=objects',
                'unread' => true,
            ),
            array(
                'date' => 'Сегодня',
                'title' => 'Датчик движения · Коридор',
                'text' => 'Зафиксировано движение, сценарий подсветки запущен.',
                'time' => '18 мин назад',
                'datetime' => '2026-10-22 13:46:00',
                'icon' => 'walk',
                'tone' => 'info',
                'actor' => 'Коридор',
                'meta' => array('Zigbee', 'Батарея 86%'),
            ),
            array(
                'date' => 'Сегодня',
                'title' => 'Ошибка системы',
                'text' => 'Цикл MQTT перезапущен после потери соединения с брокером.',
                'time' => '43 мин назад',
                'datetime' => '2026-10-22 13:21:00',
                'icon' => 'alert-triangle',
                'tone' => 'warning',
                'actor' => 'Cycle Supervisor',
                'badge' => 'Перезапуск',
                'badge_tone' => 'warning',
                'href' => '?action=system_errors',
            ),
            array(
                'date' => 'Вчера',
                'title' => 'Сценарий «Ночной режим»',
                'text' => 'Свет в спальне выключен, климат переведён на 19 °C.',
                'time' => 'вчера, 23:30',
                'datetime' => '2026-10-21 23:30:00',
                'icon' => 'moon-stars',
                'tone' => 'success',
                'actor' => 'Расписание',
                'meta' => array('4 действия', '12 секунд'),
            ),
            array(
                'date' => 'Вчера',
                'title' => 'Обновление системы',
                'text' => 'Установлена версия 2026.10.21, циклы перезапущены.',
                'time' => 'вчера, 18:04',
                'datetime' => '2026-10-21 18:04:00',
                'icon' => 'cloud-upload',
                'tone' => 'neutral',
                'actor' => 'Администратор',
                'href' => '?action=saverestore',
            ),
        );

        self::$demoData = array(
            'devices' => $devices,
            'scenes' => $scenes,
            'table_rows' => $tableRows,
            'rooms' => $rooms,
            'palette_items' => $paletteItems,
            'automations' => $automations,
            'activity' => $activity,
            'energy' => array(
                'today' => '8,4 кВт·ч',
                'yesterday' => '9,1 кВт·ч',
                'delta' => '−7,7%',
                'cost' => '412 ₽',
                'peak' => '19:00–21:00',
                'series' => array(28, 34, 31, 38, 42, 40, 35, 30, 33, 45, 52, 48, 56, 62, 58, 64, 71, 76, 68, 61, 55, 49, 44, 39),
                'usage' => array(
                    array('label' => 'Климат', 'value' => 38, 'tone' => 'primary'),
                    array('label' => 'Свет', 'value' => 24, 'tone' => 'warning'),
                    array('label' => 'Техника', 'value' => 23, 'tone' => 'info'),
                    array('label' => 'Прочее', 'value' => 15, 'tone' => 'neutral'),
                ),
            ),
            'sensors' => array(
                array('label' => 'Температура', 'value' => '22,5', 'unit' => '°C', 'icon' => 'temperature', 'tone' => 'primary', 'trend' => '−0,4°', 'trend_dir' => 'down', 'hint' => 'Гостиная · 4 сек назад'),
                array('label' => 'Влажность', 'value' => '48', 'unit' => '%', 'icon' => 'droplet-half', 'tone' => 'info', 'trend' => '+2%', 'trend_dir' => 'up', 'hint' => 'Спальня · 1 мин назад'),
                array('label' => 'Освещённость', 'value' => '320', 'unit' => 'лк', 'icon' => 'sun-high', 'tone' => 'warning', 'trend' => '−40', 'trend_dir' => 'down', 'hint' => 'Кухня · 30 сек назад'),
            ),
            'connections' => array(
                array('label' => 'MQTT', 'state' => 'online', 'state_label' => 'Подключено', 'detail' => '12 мс', 'icon' => 'broadcast'),
                array('label' => 'Zigbee', 'state' => 'online', 'state_label' => 'Подключено', 'detail' => '24 устройства', 'icon' => 'hierarchy-2'),
                array('label' => 'Z-Wave', 'state' => 'warning', 'state_label' => 'Нестабильно', 'detail' => '3 устройства', 'icon' => 'antenna-bars-3'),
                array('label' => 'WebSocket', 'state' => 'offline', 'state_label' => 'Нет связи', 'detail' => 'переподключение', 'icon' => 'plug'),
            ),
            'icons' => array(
                'home', 'home-2', 'home-cog', 'building', 'door', 'window', 'sofa', 'bed', 'bath', 'lamp',
                'bulb', 'bulb-off', 'sun', 'sun-high', 'moon', 'moon-stars', 'sunrise', 'sunset', 'temperature', 'temperature-sun',
                'flame', 'snowflake', 'air-conditioning', 'wind', 'propeller', 'droplet', 'droplet-half', 'cloud', 'cloud-rain', 'leaf',
                'bolt', 'plug', 'plug-connected', 'battery', 'battery-2', 'battery-charging', 'wifi', 'bluetooth', 'router', 'access-point',
                'antenna', 'broadcast', 'antenna-bars-1', 'antenna-bars-3', 'antenna-bars-5', 'server', 'database', 'cpu', 'device-desktop', 'device-mobile',
                'activity', 'chart-line', 'chart-bar', 'chart-pie', 'gauge', 'trending-up', 'trending-down', 'clock', 'calendar', 'calendar-check',
                'stopwatch', 'history', 'bell', 'message', 'messages', 'send', 'mail', 'phone', 'users', 'users-group',
                'shield', 'shield-check', 'shield-lock', 'lock', 'key', 'fingerprint', 'settings', 'adjustments-horizontal', 'tool', 'palette',
                'brush', 'color-swatch', 'typography', 'layout', 'layout-grid', 'layout-sidebar-right', 'columns', 'table', 'list', 'list-check',
                'search', 'filter', 'sort-ascending', 'sort-descending', 'eye', 'eye-off', 'help-circle', 'info-circle', 'alert-circle', 'alert-triangle',
                'circle-check', 'circle-x', 'player-play', 'player-pause', 'power', 'wand', 'code', 'braces', 'terminal-2', 'command',
                'refresh', 'repeat', 'download', 'upload', 'share', 'link', 'external-link', 'copy', 'clipboard', 'pencil',
                'trash', 'archive', 'folder', 'file', 'file-text', 'map-pin', 'shopping-bag', 'package', 'box', 'box-seam',
                'hierarchy', 'route', 'logout', 'movie', 'coffee', 'walk', 'wallet', 'photo', 'camera', 'music',
            ),
        );

        return self::$demoData;
    }
}


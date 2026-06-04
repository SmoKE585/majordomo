<?php
/*
* @version 0.2 (ux refresh)
*/

global $filter_name;

include_once DIR_MODULES . 'settings/settings_structure.inc.php';

if (!function_exists('settingsBuildLanguageOptions')) {
    function settingsBuildLanguageOptions()
    {
        return array(
            array('OPTION_VALUE' => 'bg', 'OPTION_TITLE' => 'Болгарский'),
            array('OPTION_VALUE' => 'cs', 'OPTION_TITLE' => 'Чешский'),
            array('OPTION_VALUE' => 'el', 'OPTION_TITLE' => 'Греческий'),
            array('OPTION_VALUE' => 'en', 'OPTION_TITLE' => 'English'),
            array('OPTION_VALUE' => 'es', 'OPTION_TITLE' => 'Испанский'),
            array('OPTION_VALUE' => 'et', 'OPTION_TITLE' => 'Эстонский'),
            array('OPTION_VALUE' => 'he', 'OPTION_TITLE' => 'Иврит'),
            array('OPTION_VALUE' => 'it', 'OPTION_TITLE' => 'Итальянский'),
            array('OPTION_VALUE' => 'lt', 'OPTION_TITLE' => 'Литовский'),
            array('OPTION_VALUE' => 'lv', 'OPTION_TITLE' => 'Латышский'),
            array('OPTION_VALUE' => 'pl', 'OPTION_TITLE' => 'Польский'),
            array('OPTION_VALUE' => 'ro', 'OPTION_TITLE' => 'Румынский'),
            array('OPTION_VALUE' => 'ru', 'OPTION_TITLE' => 'Русский'),
            array('OPTION_VALUE' => 'ua', 'OPTION_TITLE' => 'Украинский'),
            array('OPTION_VALUE' => 'zh', 'OPTION_TITLE' => 'Китайский'),
        );
    }

    function settingsBuildTimezoneOptions()
    {
        $regions = array(
            'Africa' => DateTimeZone::AFRICA,
            'America' => DateTimeZone::AMERICA,
            'Antarctica' => DateTimeZone::ANTARCTICA,
            'Asia' => DateTimeZone::ASIA,
            'Atlantic' => DateTimeZone::ATLANTIC,
            'Europe' => DateTimeZone::EUROPE,
            'Indian' => DateTimeZone::INDIAN,
            'Pacific' => DateTimeZone::PACIFIC
        );

        $zones = array();
        foreach ($regions as $mask) {
            $tzList = DateTimeZone::listIdentifiers($mask);
            foreach ($tzList as $zone) {
                $timezone = new DateTimeZone($zone);
                $current = new DateTime('now', $timezone);
                $offset = $timezone->getOffset($current) / 3600;
                $offsetText = $offset > 0 ? '+' . $offset : (string)$offset;
                $zones[] = array(
                    'OPTION_VALUE' => $zone,
                    'OPTION_TITLE' => $zone . ' (GMT ' . $offsetText . ')',
                    'OFFSET' => $offset,
                );
            }
        }

        usort($zones, function ($a, $b) {
            if ($a['OFFSET'] == $b['OFFSET']) {
                return strcmp($a['OPTION_VALUE'], $b['OPTION_VALUE']);
            }
            return ($a['OFFSET'] < $b['OFFSET']) ? -1 : 1;
        });

        return $zones;
    }

    function settingsBuildOptionsFromString($data)
    {
        $result = array();
        foreach (explode('|', (string)$data) as $item) {
            if ($item === '') {
                continue;
            }
            $parts = explode('=', $item, 2);
            $result[] = array(
                'OPTION_VALUE' => $parts[0],
                'OPTION_TITLE' => isset($parts[1]) ? $parts[1] : $parts[0],
            );
        }
        return $result;
    }

    function settingsDefaultDisplay($row)
    {
        $type = isset($row['TYPE']) ? $row['TYPE'] : 'text';
        $value = isset($row['DEFAULTVALUE']) ? (string)$row['DEFAULTVALUE'] : '';

        if ($type == 'onoff') {
            return $value == '1' ? 'Включено' : 'Выключено';
        }
        if ($type == 'yesno') {
            return $value == '1' ? 'Да' : 'Нет';
        }
        if ($type == 'enable') {
            return $value == '1' ? 'Включено' : 'Выключено';
        }
        return $value;
    }
}

$sectionKeys = array_keys($settings_sections);
$defaultSection = isset($sectionKeys[0]) ? $sectionKeys[0] : '';

if ($filter_name) {
    $this->filter_name = $filter_name;
}

if (!isset($this->filter_name) || $this->filter_name == '' || !isset($settings_sections[$this->filter_name])) {
    $this->filter_name = $defaultSection;
}

$activeSection = $this->filter_name;
$activeSectionData = isset($settings_sections[$activeSection]) ? $settings_sections[$activeSection] : array('title' => '', 'description' => '', 'settings' => array());
$options = $activeSectionData['settings'];
$specialOptions = array(
    'SITE_LANGUAGE' => settingsBuildLanguageOptions(),
    'VOICE_LANGUAGE' => settingsBuildLanguageOptions(),
    'SITE_TIMEZONE' => settingsBuildTimezoneOptions(),
    'THEME' => settingsBuildOptionsFromString('light=Светлая|dark=Тёмная'),
);

$sections = array();
foreach ($settings_sections as $sectionId => $sectionData) {
    $sections[] = array(
        'FILTER' => $sectionId,
        'TITLE' => $sectionData['title'],
        'SELECTED' => $sectionId == $activeSection ? 1 : 0,
    );
}

$out['SECTIONS'] = $sections;
$out['FILTER_NAME'] = $activeSection;
$out['ACTIVE_SECTION_TITLE'] = $activeSectionData['title'];
$out['ACTIVE_SECTION_DESCRIPTION'] = $activeSectionData['description'];
$out['ACTIVE_SECTION_COUNT'] = count($options);
$out['SECTION_COUNT'] = count($sections);

foreach ($options as $settingName => $meta) {
    $existing = SQLSelectOne("SELECT * FROM settings WHERE NAME='" . DBSafe($settingName) . "'");
    if (!$existing['ID']) {
        $newRow = array(
            'NAME' => $settingName,
            'TITLE' => isset($meta['title']) ? $meta['title'] : $settingName,
            'TYPE' => isset($meta['type']) ? $meta['type'] : 'text',
            'DEFAULTVALUE' => isset($meta['default']) ? $meta['default'] : '',
            'NOTES' => isset($meta['notes']) ? $meta['notes'] : '',
            'DATA' => isset($meta['data']) ? $meta['data'] : '',
            'PRIORITY' => isset($meta['priority']) ? (int)$meta['priority'] : 0,
            'VALUE' => isset($meta['default']) ? $meta['default'] : '',
        );
        SQLInsert('settings', $newRow);
    }
}

global $session;
if ($this->owner->name == 'panel') {
    $out['CONTROLPANEL'] = 1;
}

$settingNames = array();
foreach (array_keys($options) as $settingName) {
    $settingNames[] = "'" . DBSafe($settingName) . "'";
}

$sortby = "PRIORITY DESC, NAME";
$out['SORTBY'] = $sortby;

$res = array();
if ($settingNames) {
    $sql = "SELECT * FROM settings WHERE NAME IN (" . implode(',', $settingNames) . ") ORDER BY $sortby";
    $res = SQLSelect($sql);
}

if ($res) {
    $total = count($res);
    for ($i = 0; $i < $total; $i++) {
        $meta = isset($options[$res[$i]['NAME']]) ? $options[$res[$i]['NAME']] : array();

        if (isset($meta['title'])) {
            $res[$i]['TITLE'] = $meta['title'];
        }
        if (isset($meta['type'])) {
            $res[$i]['TYPE'] = $meta['type'];
        }
        if (isset($meta['default'])) {
            $res[$i]['DEFAULTVALUE'] = $meta['default'];
        }
        if (isset($meta['notes'])) {
            $res[$i]['NOTES'] = $meta['notes'];
        }
        if (isset($meta['data'])) {
            $res[$i]['DATA'] = $meta['data'];
        }
        if (isset($meta['priority'])) {
            $res[$i]['PRIORITY'] = (int)$meta['priority'];
        }
        $res[$i]['ROWS'] = isset($meta['rows']) ? (int)$meta['rows'] : 4;

        if ($this->mode == 'update') {
            ${'value_' . $res[$i]['ID']} = gr('value_' . $res[$i]['ID']);

            if ($res[$i]['TYPE'] == 'json' && preg_match('/^HOOK_EVENT_/is', $res[$i]['NAME'])) {
                $data = json_decode($res[$i]['VALUE'], true);
                if (!is_array($data)) {
                    $data = json_decode($res[$i]['DEFAULTVALUE'], true);
                }
                if (!is_array($data)) {
                    $data = array();
                }
                foreach ($data as $key => $value) {
                    $data[$key]['filter'] = gr($key . '_' . $res[$i]['ID'] . '_filter');
                    if ($data[$key]['filter'] === '') {
                        unset($data[$key]['filter']);
                    }
                    $data[$key]['priority'] = gr($key . '_' . $res[$i]['ID'] . '_priority', 'int');
                }
                ${'value_' . $res[$i]['ID']} = json_encode($data, JSON_UNESCAPED_UNICODE);
            }

            if (!isset(${'value_' . $res[$i]['ID']})) {
                continue;
            }

            $res[$i]['VALUE'] = ${'value_' . $res[$i]['ID']};
            SQLUpdate('settings', $res[$i]);
        }

        if ($this->mode == 'reset') {
            $res[$i]['VALUE'] = isset($meta['default']) ? $meta['default'] : $res[$i]['DEFAULTVALUE'];
            SQLUpdate('settings', $res[$i]);
        }

        if ($res[$i]['TYPE'] == 'select') {
            if (isset($specialOptions[$res[$i]['NAME']])) {
                $res[$i]['OPTIONS'] = $specialOptions[$res[$i]['NAME']];
            } else {
                $res[$i]['OPTIONS'] = settingsBuildOptionsFromString($res[$i]['DATA']);
            }
            $res[$i]['CONTROL_VIEW'] = count($res[$i]['OPTIONS']) > 6 || in_array($res[$i]['NAME'], array('SITE_LANGUAGE', 'VOICE_LANGUAGE', 'SITE_TIMEZONE', 'CODEEDITOR_THEME', 'CODEEDITOR_AUTOSAVE', 'CODEEDITOR_SHOWLINE', 'CODEEDITOR_MIXLINE', 'MAIL_TYPE', 'MAIL_SECURE')) ? 'select' : 'choices';
            $res[$i]['IS_SELECT_DROPDOWN'] = $res[$i]['CONTROL_VIEW'] == 'select' ? 1 : 0;
            $res[$i]['IS_SELECT_CHOICES'] = $res[$i]['CONTROL_VIEW'] == 'choices' ? 1 : 0;
        } elseif ($res[$i]['TYPE'] == 'json' && preg_match('/^HOOK_EVENT_/is', $res[$i]['NAME'])) {
            $data = json_decode($res[$i]['VALUE'], true);
            if (!is_array($data)) {
                $data = json_decode($res[$i]['DEFAULTVALUE'], true);
            }
            if (is_array($data)) {
                foreach ($data as $key => $value) {
                    $res[$i]['OPTIONS'][] = array(
                        'OPTION_TITLE' => $key,
                        'FILTER' => isset($value['filter']) ? htmlspecialchars($value['filter']) : '',
                        'PRIORITY' => isset($value['priority']) ? (int)$value['priority'] : 0
                    );
                }
            }
            if (isset($res[$i]['OPTIONS']) && is_array($res[$i]['OPTIONS'])) {
                usort($res[$i]['OPTIONS'], function ($a, $b) {
                    if ($a['PRIORITY'] == $b['PRIORITY']) {
                        return strcmp($a['OPTION_TITLE'], $b['OPTION_TITLE']);
                    }
                    return ($a['PRIORITY'] > $b['PRIORITY']) ? -1 : 1;
                });
            }
        }

        $currentValue = isset($res[$i]['VALUE']) ? (string)$res[$i]['VALUE'] : '';
        $defaultValue = isset($res[$i]['DEFAULTVALUE']) ? (string)$res[$i]['DEFAULTVALUE'] : '';
        if ($currentValue === $defaultValue) {
            $res[$i]['ISDEFAULT'] = '1';
        }

        $res[$i]['VALUE_RAW'] = $currentValue;
        $res[$i]['VALUE'] = htmlspecialchars($currentValue);
        $res[$i]['NOTES'] = trim((string)$res[$i]['NOTES']);
        $res[$i]['NOTES_SAFE'] = htmlspecialchars($res[$i]['NOTES'], ENT_QUOTES);
        $res[$i]['DEFAULT_DISPLAY'] = htmlspecialchars(settingsDefaultDisplay($res[$i]));
        $searchText = $res[$i]['TITLE'] . ' ' . $res[$i]['NOTES'] . ' ' . $res[$i]['NAME'];
        if (function_exists('mb_strtolower')) {
            $searchText = mb_strtolower($searchText, 'UTF-8');
        } else {
            $searchText = strtolower($searchText);
        }
        $res[$i]['SEARCH_TEXT'] = htmlspecialchars($searchText, ENT_QUOTES);
        $res[$i]['IS_WIDE'] = in_array($res[$i]['TYPE'], array('textarea', 'json')) ? 1 : 0;
        $res[$i]['IS_SECRET'] = in_array($res[$i]['TYPE'], array('password')) ? 1 : 0;
    }

    $out['RESULT'] = $res;
}

if ($this->mode == 'update') {
    if ($activeSection == 'system' && file_exists(ROOT . 'scripts/cycle_db_save.php')) {
        $service = 'cycle_db_save';
        sg($service . 'Run', '');
        sg($service . 'Control', 'restart');
    }
    $this->redirect("?updated=1&filter_name=" . urlencode($activeSection));
}

if ($this->mode == 'reset') {
    if ($activeSection == 'system' && file_exists(ROOT . 'scripts/cycle_db_save.php')) {
        $service = 'cycle_db_save';
        sg($service . 'Run', '');
        sg($service . 'Control', 'restart');
    }
    $this->redirect("?updated=1&filter_name=" . urlencode($activeSection));
}

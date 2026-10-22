<?php
/*
* @version 0.2 (auto-set)
*/

global $session;

$clear = gr('clear', 'int');
$room_id = gr('room_id', 'int');
$importance = gr('importance', 'int');

if ($this->action == 'admin' && $clear) {
    SQLExec("DELETE FROM shouts");
    $this->redirect("?");
}

if (is_object($this->owner) && $this->owner->name == 'panel') {
    $out['CONTROLPANEL'] = 1;
}
$qry = "1";

if ($room_id) {
    $qry .= " AND ROOM_ID=" . (int)$room_id;
}

if ($importance) {
    $this->importance = $importance;
}
if ($this->importance) {
    $qry .= " AND IMPORTANCE>=" . (int)$this->importance;
}

// search filters
// QUERY READY
global $save_qry;
if ($save_qry) {
    $qry = $session->get('shouts_qry');
} else {
    $session->set('shouts_qry', $qry);
}
if (!$qry) $qry = "1";
// FIELDS ORDER
$sortby = gr('sortby');
if (!$sortby && $session->has('shouts_sort')) {
    $sortby = $session->get('shouts_sort');
} else {
    if ($session->has('shouts_sort') && $session->get('shouts_sort') == $sortby) {
        if (Is_Integer(strpos($sortby, ' DESC'))) {
            $sortby = str_replace(' DESC', '', $sortby);
        } else {
            $sortby = $sortby . " DESC";
        }
    }
    $session->set('shouts_sort', $sortby);
}
if (!$sortby) $sortby = "shouts.ADDED DESC";
$out['SORTBY'] = $sortby;
// SEARCH RESULTS
$limit = gr('limit', 'int');
if ($limit) {
    $this->limit = $limit;
}

if ($this->limit) {
    $limit = "LIMIT " . $this->limit;
} else {
    $limit = "LIMIT 50";
}
$limit = str_replace('LIMIT LIMIT', 'LIMIT', $limit);

$out['LIMIT'] = $this->limit;
$out['IMPORTANCE'] = $this->importance;

$res = SQLSelect("SELECT shouts.*, DATE_FORMAT(shouts.ADDED, '%H:%i') as DAT, TO_DAYS(shouts.ADDED) as DT, users.NAME, users.COLOR FROM shouts LEFT JOIN users ON shouts.MEMBER_ID=users.ID WHERE $qry ORDER BY shouts.ADDED DESC, ID DESC $limit");


//  if ($_GET['reverse']) {
$this->reverse = 1;
//  }

if (!$this->reverse) {
    $res = array_reverse($res);
} else {
    $out['REVERSE'] = 1;
}
$comp_name = defined('SETTINGS_GENERAL_ALICE_NAME') && SETTINGS_GENERAL_ALICE_NAME !== '' ? SETTINGS_GENERAL_ALICE_NAME : LANG_DEFAULT_COMPUTER_NAME;
foreach ($res as &$row) {
    $parts = explode(' ', $row['ADDED']);
    $row['ADDED'] = fromDBDate($parts[0]) . ' ' . ($parts[1] ?? '');
    if ((int)$row['MEMBER_ID'] === 0) $row['NAME'] = $comp_name;
    $row['MESSAGE_HTML'] = htmlspecialchars($row['MESSAGE']);
}
unset($row);
$out['RESULT'] = $res;

// Admin UI: build the design-system activity feed from the same rows.
if ($this->action == 'admin') {
    require_once ROOT . 'lib/ui/MDJUI.php';

    $adminRows = isset($out['RESULT']) && is_array($out['RESULT']) ? $out['RESULT'] : array();
    $shownCount = count($adminRows);

    $totalRow = SQLSelectOne("SELECT COUNT(*) AS CNT FROM shouts");
    $systemRow = SQLSelectOne("SELECT COUNT(*) AS CNT FROM shouts WHERE MEMBER_ID=0");
    $totalCount = $totalRow ? (int)$totalRow['CNT'] : 0;
    $systemCount = $systemRow ? (int)$systemRow['CNT'] : 0;

    $out['TOTAL_SHOUTS'] = $totalCount;
    $out['SHOWN_SHOUTS'] = $shownCount;
    $out['SYSTEM_SHOUTS'] = $systemCount;
    $lastRow = $shownCount ? $adminRows[0] : null;
    $out['LAST_SHOUT_TIME'] = $lastRow && $lastRow['DAT'] !== '' ? $lastRow['DAT'] : '—';

    $activityItems = array();
    $lastDateLabel = '';
    foreach ($adminRows as $row) {
        $rowId = (int)$row['ID'];
        $isSystem = (int)$row['MEMBER_ID'] === 0;
        $name = trim((string)$row['NAME']);
        if ($name === '') {
            $name = $isSystem ? $comp_name : 'Пользователь #' . (int)$row['MEMBER_ID'];
        }

        $added = (string)$row['ADDED'];
        $parsed = DateTime::createFromFormat('m/d/Y H:i:s', $added);
        if (!$parsed) {
            $parsed = DateTime::createFromFormat('d.m.Y H:i:s', $added);
        }
        $dateLabel = $parsed ? $parsed->format('d.m.Y') : substr($added, 0, 10);
        if ($lastDateLabel === '') {
            $lastDateLabel = $dateLabel;
        }
        $timeLabel = trim((string)$row['DAT']);
        if ($timeLabel === '' && strlen($added) >= 16) {
            $timeLabel = substr($added, 11, 5);
        }
        $datetime = $parsed ? $parsed->format('c') : '';

        $meta = array();
        $meta[] = $isSystem ? 'say()' : 'Чат';
        $source = trim((string)$row['SOURCE']);
        if ($source !== '' && $source !== 'terminal0') {
            $meta[] = 'Источник: ' . $source;
        }

        $deleteButton = MDJUI::iconButton('trash', array(
            'label' => 'Удалить сообщение #' . $rowId,
            'variant' => 'danger',
            'size' => 'sm',
            'href' => '?view_mode=delete_shouts&id=' . $rowId,
            'attrs' => array('data-md-confirm' => 'Удалить это сообщение?'),
        ));

        $activityItems[] = array(
            'date' => $dateLabel,
            'title' => $name,
            'text' => (string)$row['MESSAGE'],
            'time' => $timeLabel,
            'datetime' => $datetime,
            'icon' => $isSystem ? 'terminal-2' : 'message',
            'tone' => $isSystem ? 'info' : 'primary',
            'badge' => '#' . $rowId,
            'badge_tone' => 'outline',
            'meta' => $meta,
            'actions' => $deleteButton,
        );
    }

    $out['LAST_SHOUT_DATE'] = $lastDateLabel !== '' ? $lastDateLabel : 'Нет сообщений';

    $feedActions = MDJUI::button('Обновить', array(
        'variant' => 'secondary',
        'size' => 'sm',
        'href' => '?',
        'icon' => 'refresh',
    ));
    if ($totalCount > 0) {
        $feedActions .= MDJUI::button('Удалить всё', array(
            'variant' => 'danger',
            'size' => 'sm',
            'href' => '?clear=1',
            'icon' => 'trash',
            'attrs' => array('data-md-confirm' => 'Удалить все сообщения? Это действие нельзя отменить.'),
        ));
    }

    $feedFooter = '';
    if ($totalCount > 0) {
        $feedFooter = '<span>Показано ' . $shownCount . ' из ' . $totalCount . '</span>';
        if ($totalCount > $shownCount) {
            $nextLimit = min($totalCount, max(100, $shownCount * 2));
            $feedFooter .= MDJUI::button('Показать ещё', array(
                'variant' => 'ghost',
                'size' => 'sm',
                'href' => '?limit=' . $nextLimit,
                'icon_after' => 'arrow-down',
            ));
        }
    }

    $out['SHOUT_FEED'] = MDJUI::activityFeed(array(
        'eyebrow' => 'say()',
        'title' => 'Последние сообщения',
        'subtitle' => 'Сначала новые. Системные сообщения отмечены значком терминала.',
        'icon' => 'message',
        'actions' => $feedActions,
        'items' => $activityItems,
        'footer' => $feedFooter,
        'empty_title' => 'Сообщений пока нет',
        'empty_text' => 'Как только сценарий, метод объекта или чат вызовет say(), запись появится здесь.',
    ));

    $out['SHOUT_HINT'] = MDJUI::hint(array(
        'tone' => 'tip',
        'title' => 'Что попадает в журнал',
        'text' => 'Здесь собираются все вызовы say(): системные события, сообщения сценариев, методов объектов и чата.',
        'points' => array(
            'Системные записи помечены значком терминала и меткой «say()».',
            'Сообщение можно удалить отдельно или очистить весь журнал.',
        ),
    ));

    $out['SHOUT_CODE'] = MDJUI::codeSample(array(
        'eyebrow' => 'say()',
        'title' => 'Отправить сообщение',
        'tabs' => array(
            array(
                'language' => 'php',
                'label' => 'PHP',
                'code' => "say('Все системы работают в штатном режиме');",
            ),
            array(
                'language' => 'python',
                'label' => 'Python',
                'code' => "say('Все системы работают в штатном режиме')",
            ),
        ),
        'line_numbers' => false,
        'max_height' => '180px',
        'caption' => 'Сообщение появится в журнале.',
    ));
}


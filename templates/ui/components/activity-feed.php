<?php
if (!class_exists('MDJUI')) {
    http_response_code(404);
    exit;
}

/**
 * Activity feed / timeline.
 *
 * Props:
 * - items: list of events. Each item: title, text, time, datetime, date,
 *   icon, tone, badge, badge_tone, actor, meta, href, unread.
 * - groups: alternative to items — list of ['label' => 'Сегодня', 'items' => [...]].
 * - title, subtitle, eyebrow, icon, actions, footer.
 * - limit: show only the first N events; the rest is counted in the footer.
 * - more_href, more_label: footer link to the full history.
 * - compact, bare, empty_title, empty_text, class, attrs, id.
 */
$props += array(
    'items' => array(),
    'groups' => array(),
    'title' => '',
    'subtitle' => '',
    'eyebrow' => '',
    'icon' => 'history',
    'actions' => '',
    'footer' => '',
    'limit' => 0,
    'more_href' => '',
    'more_label' => 'Показать всю историю',
    'compact' => false,
    'bare' => false,
    'empty_title' => 'Пока нет событий',
    'empty_text' => 'Здесь появится история действий, когда что-то произойдёт.',
    'class' => '',
    'attrs' => array(),
    'id' => '',
);

$toneIcons = array(
    'primary' => 'activity',
    'success' => 'circle-check',
    'warning' => 'alert-triangle',
    'danger' => 'alert-circle',
    'info' => 'info-circle',
    'neutral' => 'point',
);
$tones = array_keys($toneIcons);

$normalizeItem = function ($item) use ($tones) {
    if (!is_array($item)) {
        return array();
    }
    $tone = isset($item['tone']) && in_array($item['tone'], $tones, true) ? $item['tone'] : 'neutral';
    $item += array(
        'title' => '',
        'text' => '',
        'time' => '',
        'datetime' => '',
        'date' => '',
        'icon' => '',
        'tone' => $tone,
        'badge' => '',
        'badge_tone' => 'outline',
        'actor' => '',
        'meta' => array(),
        'actions' => '',
        'href' => '',
        'unread' => false,
    );
    if (!in_array($item['tone'], $tones, true)) {
        $item['tone'] = 'neutral';
    }
    return $item;
};

// Build ordered groups. Explicit groups win; flat items are grouped by `date`
// while preserving the original order.
$groups = array();
if (!empty($props['groups']) && is_array($props['groups'])) {
    foreach ($props['groups'] as $group) {
        $groupItems = isset($group['items']) && is_array($group['items']) ? $group['items'] : array();
        if (!$groupItems) {
            continue;
        }
        $groups[] = array(
            'label' => isset($group['label']) ? (string)$group['label'] : '',
            'items' => array_map($normalizeItem, $groupItems),
        );
    }
} else {
    $currentLabel = null;
    $currentItems = array();
    foreach ((array)$props['items'] as $rawItem) {
        $item = $normalizeItem($rawItem);
        if (!$item) {
            continue;
        }
        $date = trim((string)$item['date']);
        if ($currentLabel === null) {
            $currentLabel = $date;
        } elseif ($date !== $currentLabel) {
            if ($currentItems) {
                $groups[] = array('label' => $currentLabel, 'items' => $currentItems);
            }
            $currentLabel = $date;
            $currentItems = array();
        }
        $currentItems[] = $item;
    }
    if ($currentItems) {
        $groups[] = array('label' => $currentLabel, 'items' => $currentItems);
    }
}

$total = 0;
foreach ($groups as $group) {
    $total += count($group['items']);
}

$limit = max(0, (int)$props['limit']);
$shown = 0;
$remaining = 0;
if ($limit > 0) {
    foreach ($groups as $index => $group) {
        $take = max(0, $limit - $shown);
        if ($take <= 0) {
            $remaining += count($group['items']);
            $groups[$index]['items'] = array();
            continue;
        }
        if (count($group['items']) > $take) {
            $remaining += count($group['items']) - $take;
            $groups[$index]['items'] = array_slice($group['items'], 0, $take);
        }
        $shown += count($groups[$index]['items']);
    }
    $groups = array_values(array_filter($groups, function ($group) {
        return !empty($group['items']);
    }));
}

$footer = $props['footer'];
if ($footer === '' && $remaining > 0) {
    $remainingLabel = 'ещё ' . $remaining . ' ' . MDJUI::plural($remaining, 'событие', 'события', 'событий');
    if ($props['more_href'] !== '') {
        $footer = MDJUI::button($props['more_label'] . ' · ' . $remainingLabel, array(
            'variant' => 'ghost',
            'size' => 'sm',
            'href' => $props['more_href'],
            'icon_after' => 'arrow-right',
        ));
    } else {
        $footer = '<span>' . MDJUI::e('Ещё ' . $remainingLabel) . '</span>';
    }
} elseif ($footer === '' && $props['more_href'] !== '' && $total > 0) {
    $footer = MDJUI::button($props['more_label'], array(
        'variant' => 'ghost',
        'size' => 'sm',
        'href' => $props['more_href'],
        'icon_after' => 'arrow-right',
    ));
}

$renderItem = function (array $item) use ($toneIcons) {
    $tone = $item['tone'];
    $icon = $item['icon'] !== '' ? $item['icon'] : $toneIcons[$tone];
    $title = MDJUI::e($item['title']);
    if ($item['href'] !== '') {
        $title = '<a class="md-activity__title-link" href="' . MDJUI::e($item['href']) . '">' . $title . '</a>';
    }
    ?>
    <li class="md-activity__item md-activity__item--<?= MDJUI::e($tone) ?><?= $item['unread'] ? ' is-unread' : '' ?>">
        <span class="md-activity__rail" aria-hidden="true">
            <span class="md-activity__dot"><?= MDJUI::icon($icon) ?></span>
        </span>
        <div class="md-activity__content">
            <div class="md-activity__head">
                <div class="md-activity__heading">
                    <?php if ($item['unread']): ?><span class="md-activity__unread" aria-label="Новое событие"></span><?php endif; ?>
                    <span class="md-activity__title"><?= $title ?></span>
                </div>
                <?php if ($item['time'] !== ''): ?>
                    <time class="md-activity__time"<?= $item['datetime'] !== '' ? ' datetime="' . MDJUI::e($item['datetime']) . '"' : '' ?><?= $item['datetime'] !== '' ? ' title="' . MDJUI::e($item['datetime']) . '"' : '' ?>><?= MDJUI::e($item['time']) ?></time>
                <?php endif; ?>
            </div>
            <?php if ($item['text'] !== ''): ?><p class="md-activity__text"><?= MDJUI::e($item['text']) ?></p><?php endif; ?>
            <?php if ($item['actor'] !== '' || $item['badge'] !== '' || $item['actions'] !== '' || !empty($item['meta'])): ?>
                <div class="md-activity__meta">
                    <?php if ($item['actor'] !== ''): ?>
                        <span class="md-activity__chip"><?= MDJUI::icon('user') ?><?= MDJUI::e($item['actor']) ?></span>
                    <?php endif; ?>
                    <?php foreach ((array)$item['meta'] as $meta): ?>
                        <?php if (is_array($meta)): ?>
                            <?php $metaText = isset($meta['text']) ? (string)$meta['text'] : ''; ?>
                            <?php if ($metaText !== ''): ?>
                                <span class="md-activity__chip">
                                    <?= !empty($meta['icon']) ? MDJUI::icon($meta['icon']) : '' ?>
                                    <?php if (!empty($meta['href'])): ?><a href="<?= MDJUI::e($meta['href']) ?>"><?= MDJUI::e($metaText) ?></a><?php else: ?><?= MDJUI::e($metaText) ?><?php endif; ?>
                                </span>
                            <?php endif; ?>
                        <?php elseif ((string)$meta !== ''): ?>
                            <span class="md-activity__chip"><?= MDJUI::e($meta) ?></span>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <?php if ($item['badge'] !== ''): ?><?= MDJUI::badge($item['badge'], $item['badge_tone']) ?><?php endif; ?>
                    <?php if ($item['actions'] !== ''): ?><div class="md-activity__actions"><?= $item['actions'] ?></div><?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </li>
    <?php
};

$hasHead = $props['title'] !== '' || $props['subtitle'] !== '' || $props['eyebrow'] !== '' || $props['actions'] !== '';
$classes = MDJUI::cx(array(
    'md-card',
    'md-activity-card',
    $props['compact'] ? 'md-card--compact' : '',
    $props['class'],
));
$attrs = is_array($props['attrs']) ? $props['attrs'] : array();
if (!$props['bare']) {
    $attrs['class'] = $classes;
    if ($props['id'] !== '') {
        $attrs['id'] = $props['id'];
    }
}

$body = '';
if ($total === 0) {
    $body = MDJUI::emptyState(array(
        'icon' => 'history',
        'title' => $props['empty_title'],
        'text' => $props['empty_text'],
        'compact' => true,
    ));
} else {
    ob_start();
    ?>
    <div class="md-activity<?= $props['compact'] ? ' md-activity--compact' : '' ?>">
        <?php foreach ($groups as $group): ?>
            <div class="md-activity__group">
                <?php if ($group['label'] !== ''): ?><div class="md-activity__date"><?= MDJUI::e($group['label']) ?></div><?php endif; ?>
                <ol class="md-activity__list">
                    <?php foreach ($group['items'] as $item) { $renderItem($item); } ?>
                </ol>
            </div>
        <?php endforeach; ?>
    </div>
    <?php
    $body = ob_get_clean();
}

if ($props['bare']) {
    echo $body;
    return;
}
?>
<section<?= MDJUI::attrs($attrs) ?>>
    <?php if ($hasHead): ?>
    <header class="md-card__head">
        <div class="md-card__titles">
            <?php if ($props['eyebrow'] !== ''): ?><div class="md-card__eyebrow"><?= MDJUI::e($props['eyebrow']) ?></div><?php endif; ?>
            <?php if ($props['title'] !== ''): ?>
                <h3 class="md-card__title">
                    <?= MDJUI::icon($props['icon'], array('class' => 'md-card__title-icon')) ?>
                    <?= MDJUI::e($props['title']) ?>
                </h3>
            <?php endif; ?>
            <?php if ($props['subtitle'] !== ''): ?><p class="md-card__subtitle"><?= MDJUI::e($props['subtitle']) ?></p><?php endif; ?>
        </div>
        <?php if ($props['actions'] !== ''): ?><div class="md-card__actions"><?= $props['actions'] ?></div><?php endif; ?>
    </header>
    <?php endif; ?>
    <div class="md-card__body"><?= $body ?></div>
    <?php if ($footer !== ''): ?><footer class="md-card__footer"><?= $footer ?></footer><?php endif; ?>
</section>

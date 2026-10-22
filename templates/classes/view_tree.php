<?php
/**
 * Classes tree — the body of /panel/classes.html.
 *
 * PHP partial included from templates/classes/classes_search_admin.html with
 * `[#inc view_tree.php#]`. Replaced the Smarty template of the same name so the
 * admin UI no longer needs a second template engine.
 *
 * The tree is flattened depth-first in PHP: every level is rendered as a
 * sibling <tr>, the nesting is visual only (--md-tree-level).
 *
 * @var array<string, mixed> $data Data passed by the template engine.
 */

$data = is_array($data ?? null) ? $data : array();

/** HTML escape, same flags as Smarty's `escape:'html'`. */
$escape = static function ($value): string {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
};

/** Language/global constant with a graceful fallback for a missing one. */
$const = static function (string $name): string {
    return defined($name) ? (string)constant($name) : '';
};

$flat = array();
$flatten = static function (array $items) use (&$flatten, &$flat): void {
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $flat[] = $item;
        if (isset($item['RESULT']) && is_array($item['RESULT'])) {
            $flatten($item['RESULT']);
        }
    }
};
$flatten(is_array($data['RESULT'] ?? null) ? $data['RESULT'] : array());
?>
<div class="md-ui-page md-ui-page--stack md-classes-page" data-md-classes-page data-md-classes-total="<?= (int)($data['CLASSES_TOTAL'] ?? 0) ?>">
    <header class="md-ui-hero">
        <div>
            <div class="md-ui-hero__eyebrow"><i class="ti ti-hierarchy" aria-hidden="true"></i><?= $const('LANG_MODULE_OBJECTS') ?></div>
            <h1 class="md-ui-hero__title">Классы</h1>
            <p class="md-ui-hero__lede">Иерархия классов, объектов, свойств и методов. Раскройте класс, чтобы увидеть его объекты.</p>
            <div class="md-ui-hero__meta">
                <span class="md-ui-hero__meta-item"><i class="ti ti-hierarchy-2" aria-hidden="true"></i>Корневых: <strong><?= (int)($data['ROOT_CLASSES_TOTAL'] ?? 0) ?></strong></span>
                <span class="md-ui-hero__meta-item"><i class="ti ti-git-branch" aria-hidden="true"></i>Дочерних: <strong><?= (int)($data['CHILD_CLASSES_TOTAL'] ?? 0) ?></strong></span>
                <span class="md-ui-hero__meta-item"><i class="ti ti-box" aria-hidden="true"></i>Объектов: <strong><?= (int)($data['OBJECTS_TOTAL'] ?? 0) ?></strong></span>
            </div>
        </div>
        <div class="md-ui-hero__actions">
            <a href="?view_mode=edit_classes" class="md-btn md-btn--primary">
                <i class="ti ti-plus" aria-hidden="true"></i><span class="md-btn__label"><?= $const('LANG_ADD_NEW_CLASS') ?></span>
            </a>
            <a href="<?= $const('ROOTHTML') ?>panel/class/0/object/0.html?md=objects&amp;view_mode=edit_objects&amp;id=" class="md-btn md-btn--secondary">
                <i class="ti ti-box" aria-hidden="true"></i><span class="md-btn__label"><?= $const('LANG_ADD_NEW_OBJECT') ?></span>
            </a>
            <button type="button" class="md-btn md-btn--ghost" data-bs-toggle="modal" data-bs-target="#mdClassesImportModal">
                <i class="ti ti-upload" aria-hidden="true"></i><span class="md-btn__label"><?= $const('LANG_IMPORT') ?></span>
            </button>
        </div>
    </header>

    <div class="md-ui-kpi-grid">
        <div class="md-kpi md-kpi--primary">
            <div class="md-kpi__top">
                <span class="md-kpi__icon"><i class="ti ti-hierarchy" aria-hidden="true"></i></span>
                <span class="md-kpi__label">Классы</span>
            </div>
            <div class="md-kpi__value"><?= (int)($data['CLASSES_TOTAL'] ?? 0) ?></div>
            <div class="md-kpi__meta"><?= (int)($data['ROOT_CLASSES_TOTAL'] ?? 0) ?> корневых · <?= (int)($data['CHILD_CLASSES_TOTAL'] ?? 0) ?> дочерних</div>
        </div>
        <div class="md-kpi md-kpi--info">
            <div class="md-kpi__top">
                <span class="md-kpi__icon"><i class="ti ti-box" aria-hidden="true"></i></span>
                <span class="md-kpi__label">Объекты</span>
            </div>
            <div class="md-kpi__value"><?= (int)($data['OBJECTS_TOTAL'] ?? 0) ?></div>
            <div class="md-kpi__meta">Привязаны к классам</div>
        </div>
        <div class="md-kpi md-kpi--warning">
            <div class="md-kpi__top">
                <span class="md-kpi__icon"><i class="ti ti-braces" aria-hidden="true"></i></span>
                <span class="md-kpi__label">Методы классов</span>
            </div>
            <div class="md-kpi__value"><?= (int)($data['METHODS_TOTAL'] ?? 0) ?></div>
            <div class="md-kpi__meta">На уровне классов</div>
        </div>
        <div class="md-kpi md-kpi--success">
            <div class="md-kpi__top">
                <span class="md-kpi__icon"><i class="ti ti-list-check" aria-hidden="true"></i></span>
                <span class="md-kpi__label">Свойства классов</span>
            </div>
            <div class="md-kpi__value"><?= (int)($data['PROPERTIES_TOTAL'] ?? 0) ?></div>
            <div class="md-kpi__meta">На уровне классов</div>
        </div>
    </div>

    <?php if (!empty($data['RESULT'])): ?>
        <div class="md-table-wrap md-classes-table-wrap">
            <div class="md-table__toolbar">
                <div class="md-table__search">
                    <i class="ti ti-search" aria-hidden="true"></i>
                    <input type="search" id="filterProp" data-md-classes-search placeholder="Поиск классов и объектов" aria-label="Поиск классов и объектов" autocomplete="off">
                </div>
                <select class="md-table__filter" data-md-classes-filter aria-label="Фильтр классов">
                    <option value="">Все классы</option>
                    <option value="with">С объектами</option>
                    <option value="empty">Без объектов</option>
                </select>
                <button type="button" class="md-btn md-btn--ghost md-btn--sm" data-md-classes-expand-all aria-label="Развернуть все классы">
                    <i class="ti ti-chevrons-down" aria-hidden="true"></i><span class="md-btn__label">Развернуть всё</span>
                </button>
                <button type="button" class="md-btn md-btn--ghost md-btn--sm" data-md-classes-collapse-all aria-label="Свернуть все классы">
                    <i class="ti ti-chevrons-up" aria-hidden="true"></i><span class="md-btn__label">Свернуть</span>
                </button>
                <div class="md-table__meta" data-md-classes-count><?= (int)($data['CLASSES_TOTAL'] ?? 0) ?> <?= $const('LANG_CLASSES') ?></div>
            </div>

            <div class="md-table__scroll">
                <table class="md-table md-tree-table">
                    <thead>
                        <tr>
                            <th scope="col">Класс</th>
                            <th scope="col">Дочерние</th>
                            <th scope="col">Объекты</th>
                            <th scope="col">Методы</th>
                            <th scope="col">Свойства</th>
                            <th scope="col" class="text-end"><span class="md-visually-hidden"><?= $const('LANG_ACTIONS') ?></span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($flat as $item): ?>
                        <?php
                        $classId = (int)($item['ID'] ?? 0);
                        $classTitle = (string)($item['TITLE'] ?? '');
                        $classDescription = (string)($item['DESCRIPTION'] ?? '');
                        $childClasses = (int)($item['CHILD_CLASSES_TOTAL'] ?? 0);
                        $classObjects = (int)($item['OBJECTS_TOTAL'] ?? 0);
                        $deepObjects = (int)($item['OBJECTS_DEEP_TOTAL'] ?? 0);
                        $classMethods = (int)($item['METHODS_TOTAL'] ?? 0);
                        $classProperties = (int)($item['PROPERTIES_TOTAL'] ?? 0);
                        $classLevel = (int)($item['LEVEL'] ?? 0);
                        $parentId = (int)($item['PARENT_ID'] ?? 0);
                        ?>
                        <tr class="md-tree-table__row" data-md-class-row data-class-id="<?= $classId ?>" data-parent-id="<?= $parentId ?>" data-objects="<?= $deepObjects ?>" <?php if ($childClasses > 0): ?>data-has-children="1"<?php endif; ?> data-search="<?= $escape($classTitle) ?> <?= $escape($classDescription) ?>">
                            <td>
                                <div class="md-tree-table__cell" style="--md-tree-level: <?= $classLevel ?>;">
                                    <?php if ($childClasses > 0 || $classObjects > 0): ?>
                                    <button type="button" class="md-tree-table__toggle" data-md-class-toggle aria-expanded="false" <?php if ($classObjects > 0): ?>aria-controls="md-class-detail-<?= $classId ?>"<?php endif; ?> aria-label="Показать содержимое класса <?= $escape($classTitle) ?>">
                                        <i class="ti ti-chevron-right" aria-hidden="true"></i>
                                    </button>
                                    <?php else: ?>
                                    <span class="md-tree-table__toggle-placeholder" aria-hidden="true"></span>
                                    <?php endif; ?>
                                    <span class="md-tree-table__icon"><i class="ti ti-hierarchy-2" aria-hidden="true"></i></span>
                                    <span class="md-tree-table__text">
                                        <a class="md-tree-table__title" href="?view_mode=edit_classes&amp;id=<?= $classId ?>"><?= $escape($classTitle) ?></a>
                                        <?php if ($classDescription !== ''): ?><span class="md-tree-table__desc"><?= $escape($classDescription) ?></span><?php endif; ?>
                                    </span>
                                </div>
                            </td>
                            <td class="md-tree-table__count">
                                <?php if ($childClasses > 0): ?>
                                    <span class="md-badge md-badge--outline" title="Дочерних классов: <?= $childClasses ?>"><?= $childClasses ?></span>
                                <?php else: ?>
                                    <span class="md-tree-empty">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="md-tree-table__count">
                                <?php if ($classObjects > 0): ?>
                                    <span class="md-badge md-badge--primary" title="Прямых объектов: <?= $classObjects ?>"><?= $classObjects ?></span>
                                <?php elseif ($deepObjects > 0): ?>
                                    <span class="md-tree-empty" title="Объектов в подклассах: <?= $deepObjects ?>"><?= $deepObjects ?> в подклассах</span>
                                <?php else: ?>
                                    <span class="md-tree-empty">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="md-tree-table__count"><?= $classMethods ?></td>
                            <td class="md-tree-table__count"><?= $classProperties ?></td>
                            <td>
                                <div class="md-table__actions">
                                    <a class="md-icon-btn md-icon-btn--sm" href="?view_mode=edit_classes&amp;id=<?= $classId ?>" data-bs-toggle="tooltip" data-bs-title="<?= $const('LANG_EDIT') ?>" aria-label="<?= $const('LANG_EDIT') ?>">
                                        <i class="ti ti-pencil" aria-hidden="true"></i>
                                    </a>
                                    <div class="dropdown">
                                        <button type="button" class="md-icon-btn md-icon-btn--sm" data-bs-toggle="dropdown" aria-expanded="false" aria-label="<?= $const('LANG_ACTIONS') ?>">
                                            <i class="ti ti-dots" aria-hidden="true"></i>
                                        </button>
                                        <ul class="dropdown-menu dropdown-menu-end md-menu">
                                            <li><a class="dropdown-item" href="?view_mode=edit_classes&amp;id=<?= $classId ?>&amp;tab=objects"><i class="ti ti-box" aria-hidden="true"></i><?= $const('LANG_OBJECTS') ?></a></li>
                                            <li><a class="dropdown-item" href="?view_mode=edit_classes&amp;id=<?= $classId ?>&amp;tab=properties"><i class="ti ti-list-check" aria-hidden="true"></i><?= $const('LANG_PROPERTIES') ?></a></li>
                                            <li><a class="dropdown-item" href="?view_mode=edit_classes&amp;id=<?= $classId ?>&amp;tab=methods"><i class="ti ti-braces" aria-hidden="true"></i><?= $const('LANG_METHODS') ?></a></li>
                                            <li><a class="dropdown-item" href="?view_mode=edit_classes&amp;parent_id=<?= $classId ?>"><i class="ti ti-git-branch" aria-hidden="true"></i><?= $const('LANG_EXPAND') ?></a></li>
                                            <?php if (isset($item['CAN_DELETE'])): ?>
                                            <li><hr class="dropdown-divider"></li>
                                            <li><a class="dropdown-item text-danger" href="?view_mode=delete_classes&amp;id=<?= $classId ?>" data-md-confirm="<?= $escape($const('LANG_ARE_YOU_SURE')) ?>"><i class="ti ti-trash" aria-hidden="true"></i><?= $const('LANG_DELETE') ?></a></li>
                                            <?php endif; ?>
                                        </ul>
                                    </div>
                                </div>
                            </td>
                        </tr>
                        <?php if ($classObjects > 0): ?>
                        <tr class="md-tree-table__detail<?php if ($childClasses > 0): ?> md-tree-table__detail--with-children<?php endif; ?>" id="md-class-detail-<?= $classId ?>" data-md-class-detail data-detail-for="<?= $classId ?>" style="--md-tree-level: <?= $classLevel ?>;" hidden>
                            <td colspan="6">
                                <div class="md-tree-table__panel" style="--md-tree-level: <?= $classLevel ?>;">
                                    <div class="md-tree-table__panel-head">
                                        <span><?= $const('LANG_OBJECTS') ?></span>
                                        <span class="md-badge md-badge--outline"><?= $classObjects ?></span>
                                    </div>
                                    <div class="md-tree-objects">
                                        <?php foreach ((array)($item['OBJECTS'] ?? array()) as $object): ?>
                                        <a class="md-tree-object" href="<?= $const('ROOTHTML') ?>panel/class/<?= $classId ?>/object/<?= (int)($object['ID'] ?? 0) ?>.html">
                                            <span class="md-tree-object__icon"><i class="ti ti-box" aria-hidden="true"></i></span>
                                            <span class="md-tree-object__body">
                                                <strong><?= $escape($object['TITLE'] ?? '') ?></strong>
                                                <?php if ((string)($object['DESCRIPTION'] ?? '') !== ''): ?><small><?= $escape($object['DESCRIPTION'] ?? '') ?></small><?php endif; ?>
                                            </span>
                                            <?php if (isset($object['METHODS'])): ?>
                                            <span class="md-badge md-badge--outline"><?= count((array)$object['METHODS']) ?></span>
                                            <?php endif; ?>
                                        </a>
                                        <?php endforeach; ?>
                                    </div>
                                    <div class="md-tree-panel-actions">
                                        <a href="?view_mode=edit_classes&amp;id=<?= $classId ?>&amp;tab=objects">Открыть объекты класса</a>
                                        <a href="?view_mode=edit_classes&amp;id=<?= $classId ?>&amp;tab=properties">Свойства</a>
                                        <a href="?view_mode=edit_classes&amp;id=<?= $classId ?>&amp;tab=methods">Методы</a>
                                    </div>
                                </div>
                            </td>
                        </tr>
                        <?php endif; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="md-empty md-empty--compact" data-md-classes-empty hidden>
                <span class="md-empty__icon"><i class="ti ti-search" aria-hidden="true"></i></span>
                <div class="md-empty__title">Ничего не найдено</div>
                <p class="md-empty__text">Измените запрос или сбросьте фильтр.</p>
                <div class="md-empty__actions">
                    <button type="button" class="md-btn md-btn--secondary md-btn--sm" data-md-classes-reset>Сбросить фильтры</button>
                </div>
            </div>
        </div>
    <?php else: ?>
        <div class="md-empty">
            <span class="md-empty__icon"><i class="ti ti-hierarchy" aria-hidden="true"></i></span>
            <div class="md-empty__title">Классов пока нет</div>
            <p class="md-empty__text">Создайте первый класс, чтобы описать структуру объектов.</p>
            <div class="md-empty__actions">
                <a href="?view_mode=edit_classes" class="md-btn md-btn--primary"><?= $const('LANG_ADD_NEW_CLASS') ?></a>
            </div>
        </div>
    <?php endif; ?>

</div>

<div class="modal fade md-modal" id="mdClassesImportModal" tabindex="-1" aria-labelledby="mdClassesImportTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form action="?" enctype="multipart/form-data" method="post">
                <div class="modal-header">
                    <h2 class="modal-title" id="mdClassesImportTitle"><?= $const('LANG_IMPORT') ?></h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Закрыть"></button>
                </div>
                <div class="modal-body">
                    <div class="md-field">
                        <label class="md-field__label" for="classesImportFile"><?= $const('LANG_IMPORT_CLASS_FROM_FILE') ?></label>
                        <div class="md-field__control">
                            <input class="md-input" id="classesImportFile" type="file" name="file" enctype="multipart/form-data">
                        </div>
                        <div class="md-field__hint">Файл экспорта класса (.txt).</div>
                    </div>
                    <label class="md-check" for="classesOverwrite">
                        <input class="md-check__input" id="classesOverwrite" type="checkbox" name="overwrite" value="1">
                        <span class="md-check__box" aria-hidden="true"><i class="ti ti-check"></i></span>
                        <span class="md-check__text"><span class="md-check__label"><?= $const('LANG_OVERWRITE') ?></span></span>
                    </label>
                    <label class="md-check" for="classesOnlyClasses">
                        <input class="md-check__input" id="classesOnlyClasses" type="checkbox" name="only_classes" value="1">
                        <span class="md-check__box" aria-hidden="true"><i class="ti ti-check"></i></span>
                        <span class="md-check__text"><span class="md-check__label"><?= $const('LANG_ONLY_CLASSES') ?></span></span>
                    </label>
                </div>
                <div class="modal-footer">
                    <button type="button" class="md-btn md-btn--secondary" data-bs-dismiss="modal"><?= $const('LANG_CANCEL') ?></button>
                    <button type="submit" name="submit" value="1" class="md-btn md-btn--primary"><?= $const('LANG_IMPORT') ?></button>
                </div>
                <input type="hidden" name="view_mode" value="import_classes">
            </form>
        </div>
    </div>
</div>

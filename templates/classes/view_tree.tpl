<div class="md-ui-page md-ui-page--stack md-classes-page" data-md-classes-page data-md-classes-total="{$CLASSES_TOTAL|default:'0'}">
    <header class="md-ui-hero">
        <div>
            <div class="md-ui-hero__eyebrow"><i class="ti ti-hierarchy" aria-hidden="true"></i>{$smarty.const.LANG_MODULE_OBJECTS}</div>
            <h1 class="md-ui-hero__title">Классы</h1>
            <p class="md-ui-hero__lede">Иерархия классов, объектов, свойств и методов. Раскройте класс, чтобы увидеть его объекты.</p>
            <div class="md-ui-hero__meta">
                <span class="md-ui-hero__meta-item"><i class="ti ti-hierarchy-2" aria-hidden="true"></i>Корневых: <strong>{$ROOT_CLASSES_TOTAL|default:'0'}</strong></span>
                <span class="md-ui-hero__meta-item"><i class="ti ti-git-branch" aria-hidden="true"></i>Дочерних: <strong>{$CHILD_CLASSES_TOTAL|default:'0'}</strong></span>
                <span class="md-ui-hero__meta-item"><i class="ti ti-box" aria-hidden="true"></i>Объектов: <strong>{$OBJECTS_TOTAL|default:'0'}</strong></span>
            </div>
        </div>
        <div class="md-ui-hero__actions">
            <a href="?view_mode=edit_classes" class="md-btn md-btn--primary">
                <i class="ti ti-plus" aria-hidden="true"></i><span class="md-btn__label">{$smarty.const.LANG_ADD_NEW_CLASS}</span>
            </a>
            <a href="{$smarty.const.ROOTHTML}panel/class/0/object/0.html?md=objects&amp;view_mode=edit_objects&amp;id=" class="md-btn md-btn--secondary">
                <i class="ti ti-box" aria-hidden="true"></i><span class="md-btn__label">{$smarty.const.LANG_ADD_NEW_OBJECT}</span>
            </a>
            <button type="button" class="md-btn md-btn--ghost" data-bs-toggle="modal" data-bs-target="#mdClassesImportModal">
                <i class="ti ti-upload" aria-hidden="true"></i><span class="md-btn__label">{$smarty.const.LANG_IMPORT}</span>
            </button>
        </div>
    </header>

    <div class="md-ui-kpi-grid">
        <div class="md-kpi md-kpi--primary">
            <div class="md-kpi__top">
                <span class="md-kpi__icon"><i class="ti ti-hierarchy" aria-hidden="true"></i></span>
                <span class="md-kpi__label">Классы</span>
            </div>
            <div class="md-kpi__value">{$CLASSES_TOTAL|default:'0'}</div>
            <div class="md-kpi__meta">{$ROOT_CLASSES_TOTAL|default:'0'} корневых · {$CHILD_CLASSES_TOTAL|default:'0'} дочерних</div>
        </div>
        <div class="md-kpi md-kpi--info">
            <div class="md-kpi__top">
                <span class="md-kpi__icon"><i class="ti ti-box" aria-hidden="true"></i></span>
                <span class="md-kpi__label">Объекты</span>
            </div>
            <div class="md-kpi__value">{$OBJECTS_TOTAL|default:'0'}</div>
            <div class="md-kpi__meta">Привязаны к классам</div>
        </div>
        <div class="md-kpi md-kpi--warning">
            <div class="md-kpi__top">
                <span class="md-kpi__icon"><i class="ti ti-braces" aria-hidden="true"></i></span>
                <span class="md-kpi__label">Методы классов</span>
            </div>
            <div class="md-kpi__value">{$METHODS_TOTAL|default:'0'}</div>
            <div class="md-kpi__meta">На уровне классов</div>
        </div>
        <div class="md-kpi md-kpi--success">
            <div class="md-kpi__top">
                <span class="md-kpi__icon"><i class="ti ti-list-check" aria-hidden="true"></i></span>
                <span class="md-kpi__label">Свойства классов</span>
            </div>
            <div class="md-kpi__value">{$PROPERTIES_TOTAL|default:'0'}</div>
            <div class="md-kpi__meta">На уровне классов</div>
        </div>
    </div>

    {if $RESULT}
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
                <div class="md-table__meta" data-md-classes-count>{$CLASSES_TOTAL|default:'0'} {$smarty.const.LANG_CLASSES}</div>
            </div>

            <div class="md-table__scroll">
                <table class="md-table md-tree-table">
                    <thead>
                        <tr>
                            <th scope="col">Класс</th>
                            <th scope="col">Объекты</th>
                            <th scope="col">Методы</th>
                            <th scope="col">Свойства</th>
                            <th scope="col" class="text-end"><span class="md-visually-hidden">{$smarty.const.LANG_ACTIONS}</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        {function name=classes}
                        {foreach $items as $item}
                        <tr class="md-tree-table__row" data-md-class-row data-class-id="{$item.ID}" data-parent-id="{$item.PARENT_ID|default:'0'}" data-objects="{$item.OBJECTS_TOTAL|default:'0'}" data-search="{$item.TITLE|escape:'html'} {$item.DESCRIPTION|escape:'html'}">
                            <td>
                                <div class="md-tree-table__cell" style="--md-tree-level: {$item.LEVEL|default:'0'};">
                                    {if isset($item.RESULT) || $item.OBJECTS_TOTAL > 0}
                                    <button type="button" class="md-tree-table__toggle" data-md-class-toggle aria-expanded="false" aria-controls="md-class-detail-{$item.ID}" aria-label="Показать содержимое класса {$item.TITLE|escape:'html'}">
                                        <i class="ti ti-chevron-right" aria-hidden="true"></i>
                                    </button>
                                    {else}
                                    <span class="md-tree-table__toggle-placeholder" aria-hidden="true"></span>
                                    {/if}
                                    <span class="md-tree-table__icon"><i class="ti ti-hierarchy-2" aria-hidden="true"></i></span>
                                    <span class="md-tree-table__text">
                                        <a class="md-tree-table__title" href="?view_mode=edit_classes&amp;id={$item.ID}">{$item.TITLE|escape:'html'}</a>
                                        {if $item.DESCRIPTION != ''}<span class="md-tree-table__desc">{$item.DESCRIPTION|escape:'html'}</span>{/if}
                                    </span>
                                </div>
                            </td>
                            <td class="md-tree-table__count">
                                {if $item.OBJECTS_TOTAL > 0}
                                    <span class="md-badge md-badge--primary">{$item.OBJECTS_TOTAL}</span>
                                {else}
                                    <span class="md-tree-empty">—</span>
                                {/if}
                            </td>
                            <td class="md-tree-table__count">{$item.METHODS_TOTAL|default:'0'}</td>
                            <td class="md-tree-table__count">{$item.PROPERTIES_TOTAL|default:'0'}</td>
                            <td>
                                <div class="md-table__actions">
                                    <a class="md-icon-btn md-icon-btn--sm" href="?view_mode=edit_classes&amp;id={$item.ID}" data-bs-toggle="tooltip" data-bs-title="{$smarty.const.LANG_EDIT}" aria-label="{$smarty.const.LANG_EDIT}">
                                        <i class="ti ti-pencil" aria-hidden="true"></i>
                                    </a>
                                    <div class="dropdown">
                                        <button type="button" class="md-icon-btn md-icon-btn--sm" data-bs-toggle="dropdown" aria-expanded="false" aria-label="{$smarty.const.LANG_ACTIONS}">
                                            <i class="ti ti-dots" aria-hidden="true"></i>
                                        </button>
                                        <ul class="dropdown-menu dropdown-menu-end md-menu">
                                            <li><a class="dropdown-item" href="?view_mode=edit_classes&amp;id={$item.ID}&amp;tab=objects"><i class="ti ti-box" aria-hidden="true"></i>{$smarty.const.LANG_OBJECTS}</a></li>
                                            <li><a class="dropdown-item" href="?view_mode=edit_classes&amp;id={$item.ID}&amp;tab=properties"><i class="ti ti-list-check" aria-hidden="true"></i>{$smarty.const.LANG_PROPERTIES}</a></li>
                                            <li><a class="dropdown-item" href="?view_mode=edit_classes&amp;id={$item.ID}&amp;tab=methods"><i class="ti ti-braces" aria-hidden="true"></i>{$smarty.const.LANG_METHODS}</a></li>
                                            <li><a class="dropdown-item" href="?view_mode=edit_classes&amp;parent_id={$item.ID}"><i class="ti ti-git-branch" aria-hidden="true"></i>{$smarty.const.LANG_EXPAND}</a></li>
                                            {if isset($item.CAN_DELETE)}
                                            <li><hr class="dropdown-divider"></li>
                                            <li><a class="dropdown-item text-danger" href="?view_mode=delete_classes&amp;id={$item.ID}" data-md-confirm="{$smarty.const.LANG_ARE_YOU_SURE|escape:'html'}"><i class="ti ti-trash" aria-hidden="true"></i>{$smarty.const.LANG_DELETE}</a></li>
                                            {/if}
                                        </ul>
                                    </div>
                                </div>
                            </td>
                        </tr>
                        {if isset($item.RESULT) || $item.OBJECTS_TOTAL > 0}
                        <tr class="md-tree-table__detail" id="md-class-detail-{$item.ID}" data-md-class-detail data-detail-for="{$item.ID}" hidden>
                            <td colspan="5">
                                <div class="md-tree-table__panel" style="--md-tree-level: {$item.LEVEL|default:'0'};">
                                    <div class="md-tree-table__panel-head">
                                        <span>{$smarty.const.LANG_OBJECTS}</span>
                                        <span class="md-badge md-badge--outline">{$item.OBJECTS_TOTAL|default:'0'}</span>
                                    </div>
                                    {if $item.OBJECTS_TOTAL > 0}
                                    <div class="md-tree-objects">
                                        {foreach $item.OBJECTS as $object}
                                        <a class="md-tree-object" href="{$smarty.const.ROOTHTML}panel/class/{$item.ID}/object/{$object.ID}.html">
                                            <span class="md-tree-object__icon"><i class="ti ti-box" aria-hidden="true"></i></span>
                                            <span class="md-tree-object__body">
                                                <strong>{$object.TITLE|escape:'html'}</strong>
                                                {if $object.DESCRIPTION != ''}<small>{$object.DESCRIPTION|escape:'html'}</small>{/if}
                                            </span>
                                            {if isset($object.METHODS)}
                                            <span class="md-badge md-badge--outline">{$object.METHODS|@count}</span>
                                            {/if}
                                        </a>
                                        {/foreach}
                                    </div>
                                    {else}
                                    <div class="md-tree-empty">В классе пока нет объектов.</div>
                                    {/if}
                                    <div class="md-tree-panel-actions">
                                        <a href="?view_mode=edit_classes&amp;id={$item.ID}&amp;tab=objects">Открыть объекты класса</a>
                                        <a href="?view_mode=edit_classes&amp;id={$item.ID}&amp;tab=properties">Свойства</a>
                                        <a href="?view_mode=edit_classes&amp;id={$item.ID}&amp;tab=methods">Методы</a>
                                    </div>
                                </div>
                            </td>
                        </tr>
                        {/if}
                        {if isset($item.RESULT)}
                            {classes items=$item.RESULT}
                        {/if}
                        {/foreach}
                        {/function}
                        {classes items=$RESULT}
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
    {else}
        <div class="md-empty">
            <span class="md-empty__icon"><i class="ti ti-hierarchy" aria-hidden="true"></i></span>
            <div class="md-empty__title">Классов пока нет</div>
            <p class="md-empty__text">Создайте первый класс, чтобы описать структуру объектов.</p>
            <div class="md-empty__actions">
                <a href="?view_mode=edit_classes" class="md-btn md-btn--primary">{$smarty.const.LANG_ADD_NEW_CLASS}</a>
            </div>
        </div>
    {/if}

</div>

<div class="modal fade md-modal" id="mdClassesImportModal" tabindex="-1" aria-labelledby="mdClassesImportTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form action="?" enctype="multipart/form-data" method="post">
                <div class="modal-header">
                    <h2 class="modal-title" id="mdClassesImportTitle">{$smarty.const.LANG_IMPORT}</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Закрыть"></button>
                </div>
                <div class="modal-body">
                    <div class="md-field">
                        <label class="md-field__label" for="classesImportFile">{$smarty.const.LANG_IMPORT_CLASS_FROM_FILE}</label>
                        <div class="md-field__control">
                            <input class="md-input" id="classesImportFile" type="file" name="file" enctype="multipart/form-data">
                        </div>
                        <div class="md-field__hint">Файл экспорта класса (.txt).</div>
                    </div>
                    <label class="md-check" for="classesOverwrite">
                        <input class="md-check__input" id="classesOverwrite" type="checkbox" name="overwrite" value="1">
                        <span class="md-check__box" aria-hidden="true"><i class="ti ti-check"></i></span>
                        <span class="md-check__text"><span class="md-check__label">{$smarty.const.LANG_OVERWRITE}</span></span>
                    </label>
                    <label class="md-check" for="classesOnlyClasses">
                        <input class="md-check__input" id="classesOnlyClasses" type="checkbox" name="only_classes" value="1">
                        <span class="md-check__box" aria-hidden="true"><i class="ti ti-check"></i></span>
                        <span class="md-check__text"><span class="md-check__label">{$smarty.const.LANG_ONLY_CLASSES}</span></span>
                    </label>
                </div>
                <div class="modal-footer">
                    <button type="button" class="md-btn md-btn--secondary" data-bs-dismiss="modal">{$smarty.const.LANG_CANCEL}</button>
                    <button type="submit" name="submit" value="1" class="md-btn md-btn--primary">{$smarty.const.LANG_IMPORT}</button>
                </div>
                <input type="hidden" name="view_mode" value="import_classes">
            </form>
        </div>
    </div>
</div>

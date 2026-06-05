<div class="md-classes-page">
    <div class="md-classes-toolbar">
        <div class="md-classes-toolbar__search">
            <label class="md-admin-visually-hidden" for="filterProp">{$smarty.const.LANG_NEWMARKET_SEARCH_INPUT_PLACEHOLDER}</label>
            <input type="text" class="form-control md-classes-search-input" id="filterProp" data-md-class-search placeholder="{$smarty.const.LANG_NEWMARKET_SEARCH_INPUT_PLACEHOLDER}">
        </div>
        <div class="md-classes-toolbar__actions">
            <a href="?view_mode=edit_classes" class="btn btn-primary">+ {$smarty.const.LANG_ADD_NEW_CLASS}</a>
            <a href="{$smarty.const.ROOTHTML}panel/class/0/object/0.html?md=objects&view_mode=edit_objects&id=" class="btn btn-outline-primary">+ {$smarty.const.LANG_ADD_NEW_OBJECT}</a>
        </div>
    </div>

    <form action="?" method="post" name="frmList_classes" class="md-classes-tree">
        {function name=classes}
        {foreach $items as $item}
        <article class="md-classes-card {if $item.TITLE == 'Computer' OR $item.TITLE == 'systemStates' OR $item.TITLE == 'OperationalModes' OR $item.TITLE == 'Timer'}md-classes-card--system{/if} {if isset($item.CAN_DELETE)}is-muted{/if}" data-md-class-card data-md-class-id="{$item.ID}" data-md-class-parent-id="{$item.PARENT_ID}" {if $item.LEVEL_PAD!=0}style="--md-classes-level: {$item.LEVEL_PAD};"{/if}>
            <header class="md-classes-card__header">
                <button type="button" class="md-classes-card__toggle" data-md-class-toggle="{if $item.SUB_LIST!=$item.ID}{$item.ID},{/if}{$item.SUB_LIST}" aria-controls="sub_{$item.ID}" aria-expanded="false">
                    <span class="md-classes-card__chevron" aria-hidden="true"></span>
                    <span class="md-classes-card__title-wrap">
                        <span class="md-classes-card__title">{$item.TITLE}</span>
                        {if $item.DESCRIPTION!=''}<span class="md-classes-card__description">{$item.DESCRIPTION}</span>{/if}
                    </span>
                </button>
                <div class="md-classes-card__actions">
                    <a href="?view_mode=edit_classes&id={$item.ID}" class="btn btn-sm btn-light" title="{$smarty.const.LANG_EDIT}">{$smarty.const.LANG_EDIT}</a>
                    <div class="btn-group">
                        <button type="button" class="btn btn-sm btn-light dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">{$smarty.const.LANG_ACTIONS}</button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="?view_mode=edit_classes&id={$item.ID}&tab=properties">{$smarty.const.LANG_PROPERTIES}</a></li>
                            <li><a class="dropdown-item" href="?view_mode=edit_classes&id={$item.ID}&tab=methods">{$smarty.const.LANG_METHODS}</a></li>
                            <li><a class="dropdown-item" href="?view_mode=edit_classes&id={$item.ID}&tab=objects">{$smarty.const.LANG_OBJECTS}</a></li>
                            <li><a class="dropdown-item" href="?view_mode=edit_classes&parent_id={$item.ID}">{$smarty.const.LANG_EXPAND}</a></li>
                            {if isset($item.CAN_DELETE)}
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item text-danger" href="?view_mode=delete_classes&id={$item.ID}" data-md-confirm="{$smarty.const.LANG_ARE_YOU_SURE|escape:'html'}">{$smarty.const.LANG_DELETE}</a></li>
                            {/if}
                        </ul>
                    </div>
                </div>
            </header>

            <div class="collapse md-classes-card__body" id="sub_{$item.ID}" data-md-class-collapse="{$item.ID}">
                {if isset($item.OBJECTS)}
                <ul class="md-classes-object-list classSearch">
                    {foreach $item.OBJECTS as $object}
                    <li class="md-classes-object" data-md-class-object-item>
                        <div class="md-classes-object__main">
                            <a class="md-classes-object__title" href="{$smarty.const.ROOTHTML}panel/class/{$item.ID}/object/{$object.ID}.html">{$object.TITLE}</a>
                            {if $object.DESCRIPTION != ''}<span class="md-classes-object__meta">{$object.DESCRIPTION}</span>{/if}
                            {if $object.KEY_DATA!=""}<span class="md-classes-object__key">{$object.KEY_DATA}</span>{/if}
                        </div>
                        {if isset($object.METHODS)}
                        <div class="md-classes-object__methods">
                            {foreach $object.METHODS as $method}
                            <a class="badge md-admin-status-badge text-bg-primary" href="{$smarty.const.ROOTHTML}panel/class/{$item.ID}/object/{$object.ID}.html?tab=methods&overwrite=1&method_id={$method.ID}">{$smarty.const.LANG_METHOD}: {$method.TITLE}</a>
                            {/foreach}
                        </div>
                        {/if}
                    </li>
                    {/foreach}
                </ul>
                {/if}

                {if isset($item.RESULT)}
                    {classes items=$item.RESULT}
                {/if}
            </div>
        </article>
        {/foreach}
        {/function}
        {classes items=$RESULT}
        <input type="hidden" name="data_source" value="<#DATA_SOURCE#>">
        <input type="hidden" name="view_mode" value="multiple_classes">
    </form>

    <div class="md-classes-tools-toggle">
        <button type="button" class="btn btn-outline-secondary" data-md-toggle-target="#tools">{$smarty.const.LANG_TOOLS}</button>
    </div>
    <section id="tools" class="md-admin-section-card md-classes-tools">
        <div class="md-admin-section-card__header">
            <h2>{$smarty.const.LANG_TOOLS}</h2>
        </div>
        <div class="md-admin-section-card__body">
            <form action="?" enctype="multipart/form-data" method="post" class="md-admin-form-grid">
                <div class="mb-3">
                    <label class="form-label" for="classesImportFile">{$smarty.const.LANG_IMPORT_CLASS_FROM_FILE}</label>
                    <input class="form-control" id="classesImportFile" type="file" name="file" enctype="multipart/form-data">
                </div>
                <div class="form-check mb-2">
                    <input class="form-check-input" type="checkbox" name="overwrite" value="1" id="classesOverwrite">
                    <label class="form-check-label" for="classesOverwrite">{$smarty.const.LANG_OVERWRITE}</label>
                </div>
                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" name="only_classes" value="1" id="classesOnlyClasses">
                    <label class="form-check-label" for="classesOnlyClasses">{$smarty.const.LANG_ONLY_CLASSES}</label>
                </div>
                <button type="submit" name="submit" value="1" class="btn btn-primary">{$smarty.const.LANG_IMPORT}</button>
                <input type="hidden" name="view_mode" value="import_classes">
            </form>
        </div>
    </section>
</div>

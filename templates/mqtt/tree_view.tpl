<style type="text/css">
    .md-mqtt-tree {
        display: flex;
        flex-direction: column;
        gap: 10px;
    }

    .md-mqtt-tree__branch {
        display: flex;
        flex-direction: column;
        gap: 6px;
    }

    .md-mqtt-tree__node {
        padding: 8px 10px 8px 8px;
        background: rgba(255, 255, 255, .92);
        border: 1px solid rgba(31, 41, 51, .08);
        border-radius: 14px;
        box-shadow: 0 6px 14px rgba(15, 23, 42, .04);
        transition: box-shadow .16s ease, border-color .16s ease;
    }

    .md-mqtt-tree__branch > .md-mqtt-tree__row {
        min-height: 30px;
        padding-left: 2px;
    }

    .md-mqtt-tree__node:hover {
        border-color: rgba(var(--md-admin-primary-rgb, 71, 146, 209), .18);
        box-shadow: 0 10px 20px rgba(15, 23, 42, .06);
    }

    .md-mqtt-tree__row {
        display: grid;
        grid-template-columns: auto minmax(0, 1fr) auto;
        gap: 6px;
        align-items: flex-start;
    }

    .md-mqtt-tree__toggle,
    .md-mqtt-tree__toggle--leaf {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 28px;
        height: 28px;
        flex: 0 0 28px;
        color: var(--md-admin-primary, #4792d1);
        background: rgba(var(--md-admin-primary-rgb, 71, 146, 209), .11);
        border: 0;
        border-radius: 10px;
    }

    .md-mqtt-tree__toggle i {
        transition: transform .18s ease;
        transform: rotate(0deg);
    }

    .md-mqtt-tree__toggle[aria-expanded="true"] i {
        transform: rotate(90deg);
    }

    .md-mqtt-tree__toggle:focus-visible,
    .md-mqtt-tree__toggle--leaf:focus-visible,
    .md-mqtt-tree__delete:focus-visible {
        outline: 0;
        box-shadow: 0 0 0 .18rem rgba(var(--md-admin-primary-rgb, 71, 146, 209), .18);
    }

    .md-mqtt-tree__content {
        min-width: 0;
    }

    .md-mqtt-tree__title {
        display: inline-flex;
        align-items: baseline;
        gap: 4px;
        color: var(--md-admin-text, #1f2933);
        font-size: .88rem;
        font-weight: 700;
        word-break: break-word;
    }

    .md-mqtt-tree__branch-title {
        color: var(--md-admin-text, #1f2933);
        font-size: .84rem;
        font-weight: 700;
        letter-spacing: .01em;
        word-break: break-word;
    }

    .md-mqtt-tree__meta {
        display: flex;
        flex-wrap: wrap;
        gap: 4px;
        margin-top: 4px;
    }

    .md-mqtt-tree__value,
    .md-mqtt-tree__linked {
        display: inline-flex;
        align-items: center;
        min-height: 22px;
        padding: 2px 7px;
        color: var(--md-admin-text, #1f2933);
        background: rgba(248, 251, 254, .95);
        border: 1px solid rgba(31, 41, 51, .06);
        border-radius: 999px;
        font-size: .8rem;
        overflow-wrap: anywhere;
    }

    .md-mqtt-tree__linked {
        color: var(--md-admin-muted, #6b7a88);
    }

    .md-mqtt-tree__delete {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 28px;
        height: 28px;
        color: #c92a2a;
        border-radius: 10px;
        opacity: .35;
        transition: opacity .15s ease, background .15s ease;
    }

    .md-mqtt-tree__children {
        display: flex;
        flex-direction: column;
        gap: 8px;
        padding-left: 14px;
        margin-left: 8px;
        border-left: 2px solid rgba(var(--md-admin-primary-rgb, 71, 146, 209), .12);
    }

    .md-mqtt-tree__children .md-mqtt-tree__node {
        background: rgba(248, 251, 254, .9);
    }

    @media (max-width: 575.98px) {
        .md-mqtt-tree__row {
            grid-template-columns: 26px minmax(0, 1fr) 26px;
            gap: 4px;
        }

        .md-mqtt-tree__node {
            padding-right: 8px;
        }

        .md-mqtt-tree__children {
            padding-left: 10px;
            margin-left: 4px;
        }

        .md-mqtt-tree__value,
        .md-mqtt-tree__linked {
            width: 100%;
        }
    }
</style>

<script type="text/JavaScript">
    function rememberBranchStatus(title, status) {
        var url="?ajax=1&op=branch_status&status="+status+"&branch="+encodeURIComponent(title);
        if (window.fetch) {
            fetch(url, { credentials: 'same-origin' });
        } else if (window.XMLHttpRequest) {
            var xhr = new XMLHttpRequest();
            xhr.open('GET', url, true);
            xhr.send();
        }
    }

    function getDirectChildren(node) {
        if (!node || !node.children) {
            return null;
        }
        for (var i = 0; i < node.children.length; i++) {
            if (node.children[i].classList && node.children[i].classList.contains('md-mqtt-tree__children')) {
                return node.children[i];
            }
        }
        return null;
    }

    function editItem(item_id) {
        window.location.href = '{$smarty.const.ROOTHTML}panel/mqtt.html?view_mode=edit_mqtt&id='+item_id;
    }

    function deletePath(path) {
        if (confirm('{$smarty.const.LANG_ARE_YOU_SURE}')) {
            window.location.href = '{$smarty.const.ROOTHTML}panel/mqtt.html?view_mode=delete_path&path='+path;
        }
        return false;
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-md-mqtt-tree-toggle]').forEach(function (button) {
            button.title = button.getAttribute('aria-expanded') === 'true' ? 'Свернуть ветку' : 'Развернуть ветку';
            button.addEventListener('click', function () {
                var node = button.closest('[data-md-mqtt-tree-branch]');
                var children = getDirectChildren(node);
                if (!children) {
                    return;
                }
                var isOpen = !children.hidden;
                children.hidden = isOpen;
                button.setAttribute('aria-expanded', isOpen ? 'false' : 'true');
                button.title = isOpen ? 'Развернуть ветку' : 'Свернуть ветку';
                rememberBranchStatus(node.getAttribute('data-branch-title') || node.title || '', isOpen ? 0 : 1);
            });
        });

        document.querySelectorAll('[data-md-mqtt-tree-edit]').forEach(function (link) {
            link.addEventListener('click', function (event) {
                event.preventDefault();
                editItem(link.getAttribute('data-md-mqtt-tree-edit'));
            });
        });
    });
</script>

<div class="md-mqtt-tree">
    {function name=menu}
        {foreach $items as $item}
            {if isset($item.RESULT)}
            <section class="md-mqtt-tree__branch" title="{$item.TITLE}" data-md-mqtt-tree-branch data-branch-title="{$item.TITLE}">
                <div class="md-mqtt-tree__row">
                    <button type="button" class="md-mqtt-tree__toggle" data-md-mqtt-tree-toggle aria-expanded="{if isset($item.IS_VISIBLE) && $item.IS_VISIBLE==1}true{else}false{/if}" aria-label="Toggle branch">
                        <i class="glyphicon glyphicon-chevron-right"></i>
                    </button>

                    <div class="md-mqtt-tree__content">
                        {if isset($item.ID)}
                            <a href="#" onclick="return editItem({$item.ID});" data-md-mqtt-tree-edit="{$item.ID}" title="{$item.PATH}" class="md-mqtt-tree__title">
                                {if $item.TITLE!=""}{$item.TITLE}{else}[..]{/if}
                            </a>
                            <div class="md-mqtt-tree__meta">
                                <span id="mqtt{$item.ID}" class="mqtt_value md-mqtt-tree__value">{$item.VALUE}</span>
                                {if $item.LINKED_OBJECT!=""}
                                    <span class="md-mqtt-tree__linked">
                                        {if $item.LINKED_PROPERTY==""}M: {else}P: {/if}{$item.LINKED_OBJECT}.{if $item.LINKED_PROPERTY!=""}{$item.LINKED_PROPERTY}{else}{$item.LINKED_METHOD}{/if}
                                    </span>
                                {/if}
                            </div>
                        {else}
                            <div class="md-mqtt-tree__branch-title">{$item.TITLE}</div>
                        {/if}
                    </div>

                    {if isset($item.ID)}
                        <a href="#" class="md-mqtt-tree__delete" onclick="return deletePath('{$item.PATH_URL}');" aria-label="{$smarty.const.LANG_DELETE}">
                            <i class="glyphicon glyphicon-remove"></i>
                        </a>
                    {/if}
                </div>

                <div class="md-mqtt-tree__children" {if !isset($item.IS_VISIBLE) || $item.IS_VISIBLE!=1}hidden{/if}>
                    {menu items=$item.RESULT}
                </div>
            </section>
            {else}
            <article class="md-mqtt-tree__node is-leaf" title="{$item.TITLE}">
                <div class="md-mqtt-tree__row">
                    <span class="md-mqtt-tree__toggle--leaf" aria-hidden="true">
                        <i class="glyphicon glyphicon-record"></i>
                    </span>

                    <div class="md-mqtt-tree__content">
                        <a href="#" onclick="return editItem({$item.ID});" data-md-mqtt-tree-edit="{$item.ID}" title="{$item.PATH}" class="md-mqtt-tree__title">
                            {if $item.TITLE!=""}{$item.TITLE}{else}[..]{/if}
                        </a>
                        <div class="md-mqtt-tree__meta">
                            <span id="mqtt{$item.ID}" class="mqtt_value md-mqtt-tree__value">{$item.VALUE}</span>
                            {if $item.LINKED_OBJECT!=""}
                                <span class="md-mqtt-tree__linked">
                                    {if $item.LINKED_PROPERTY==""}M: {else}P: {/if}{$item.LINKED_OBJECT}.{if $item.LINKED_PROPERTY!=""}{$item.LINKED_PROPERTY}{else}{$item.LINKED_METHOD}{/if}
                                </span>
                            {/if}
                        </div>
                    </div>

                    <a href="#" class="md-mqtt-tree__delete" onclick="return deletePath('{$item.PATH_URL}');" aria-label="{$smarty.const.LANG_DELETE}">
                        <i class="glyphicon glyphicon-remove"></i>
                    </a>
                </div>
            </article>
            {/if}
        {/foreach}
    {/function}
    {menu items=$RESULT}
</div>

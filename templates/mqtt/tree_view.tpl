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

    .md-mqtt-tree__loading {
        padding: 8px 10px;
        color: var(--md-admin-muted, #6b7a88);
        font-size: .84rem;
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
        var tree = document.querySelector('[data-md-mqtt-tree]');
        if (!tree) {
            return;
        }

        tree.addEventListener('click', function (event) {
            var button = event.target.closest('[data-md-mqtt-tree-toggle]');
            if (button) {
                var node = button.closest('[data-md-mqtt-tree-branch]');
                var children = getDirectChildren(node);
                if (!children) {
                    return;
                }
                var isOpen = !children.hidden;
                if (isOpen) {
                    children.hidden = true;
                    button.setAttribute('aria-expanded', 'false');
                    button.title = 'Развернуть ветку';
                    rememberBranchStatus(node.getAttribute('data-md-mqtt-tree-path') || node.title || '', 0);
                    return;
                }

                if (node.getAttribute('data-md-mqtt-tree-loaded') !== '1') {
                    button.disabled = true;
                    children.innerHTML = '<div class="md-mqtt-tree__loading">Загрузка...</div>';
                    children.hidden = false;
                    var params = new URLSearchParams(window.location.search);
                    params.set('ajax', '1');
                    params.set('op', 'tree_children');
                    params.set('root', node.getAttribute('data-md-mqtt-tree-path') || '');
                    fetch('?' + params.toString(), { credentials: 'same-origin' })
                        .then(function (response) { return response.json(); })
                        .then(function (data) {
                            children.innerHTML = data.HTML || '';
                            node.setAttribute('data-md-mqtt-tree-loaded', '1');
                            button.setAttribute('aria-expanded', 'true');
                            button.title = 'Свернуть ветку';
                            rememberBranchStatus(node.getAttribute('data-md-mqtt-tree-path') || node.title || '', 1);
                        })
                        .catch(function () {
                            children.innerHTML = '<div class="md-admin-empty-state">Не удалось загрузить ветку MQTT.</div>';
                        })
                        .finally(function () {
                            button.disabled = false;
                        });
                    return;
                }

                children.hidden = false;
                button.setAttribute('aria-expanded', 'true');
                button.title = 'Свернуть ветку';
                rememberBranchStatus(node.getAttribute('data-md-mqtt-tree-path') || node.title || '', 1);
                return;
            }

            var editLink = event.target.closest('[data-md-mqtt-tree-edit]');
            if (editLink) {
                event.preventDefault();
                editItem(editLink.getAttribute('data-md-mqtt-tree-edit'));
                return;
            }

            var deleteLink = event.target.closest('[data-md-mqtt-tree-delete]');
            if (deleteLink) {
                event.preventDefault();
                deletePath(deleteLink.getAttribute('data-md-mqtt-tree-delete'));
            }
        });
    });
</script>

<div class="md-mqtt-tree" data-md-mqtt-tree>
    {$TREE_HTML nofilter}
</div>

/*
 * Legacy admin shell helpers.
 *
 * These functions are called from inline handlers in old module templates, so
 * they must stay global. They are loaded as a classic script after the admin
 * bundle and js/scripts.js. New code should use MDJAdminUI modules instead.
 */

function toggleCategory(id) {
    var vl = $.cookie('sub_' + id);
    if (vl == 'off') {
        $.cookie('sub_' + id, 'on', {expires: 180});
    } else {
        $.cookie('sub_' + id, 'off', {expires: 180});
    }
    adminCollapse('row_' + id, 'toggle');
    return false;
}

function adminCollapse(elementId, command) {
    var element = document.getElementById(elementId);
    if (!element) {
        return false;
    }
    if (window.bootstrap && window.bootstrap.Collapse) {
        window.bootstrap.Collapse.getOrCreateInstance(element, {toggle: false})[command]();
        return true;
    }
    if (window.jQuery && typeof jQuery.fn.collapse === 'function') {
        jQuery(element).collapse(command);
        return true;
    }
    element.classList.toggle('show', command === 'show' || (command === 'toggle' && !element.classList.contains('show')));
    return true;
}

function resetHintCookies() {
    var message = (window.MDJAdminConfig && window.MDJAdminConfig.confirmText) || 'Вы уверены?';
    if (!confirm(message)) {
        return false;
    }
    var pairs = document.cookie.split(';');
    for (var i = 0; i < pairs.length; i++) {
        var pair = pairs[i].split('=');
        var cookieName = pair[0].trim();
        if (cookieName.startsWith('hint_')) {
            var id = cookieName.replace('hint_', '');
            $('#hint_' + id).show('fast');
            $.cookie(cookieName, null);
        }
        if (cookieName.startsWith('sub_')) {
            var id = cookieName.replace('sub_', '');
            adminCollapse('row_' + id, 'show');
            $.cookie(cookieName, null);
        }
    }
    return true;
}

function hideHintCookie(id, check) {
    check = check || 0;
    var vl = $.cookie('hint_' + id);
    if (check == 0) {
        $.cookie('hint_' + id, 'off', {expires: 180});
        $('#hint_' + id).hide('fast');
    } else if (check == 1) {
        if (vl != 'off') {
            $('#hint_' + id).show('fast');
        }
    }
    return false;
}

function checkCategory(id) {
    var vl = $.cookie('sub_' + id);
    if (vl == 'on' || vl == null) {
        adminCollapse('row_' + id, 'show');
    } else if (vl == 'off') {
        adminCollapse('row_' + id, 'hide');
    }
    return false;
}

var fileBrowseElementId = '';
function openFileBrowserCallback(url) {
    $('#' + fileBrowseElementId).val(url);
}

function openFileBrowser(id) {
    fileBrowseElementId = id;
    // The old elFinder popup was replaced by the project-scoped folder picker.
    // Keep this global for legacy inline handlers; pages without the picker
    // simply do nothing instead of opening an unrestricted file manager.
    if (window.MDFolderPicker && typeof window.MDFolderPicker.open === 'function') {
        return window.MDFolderPicker.open(id);
    }
    return false;
}

function showHideLayer(id) {
    var layer = document.getElementById('sub_' + id);
    var layer2 = document.getElementById('plus_' + id);
    if (layer.style.display == 'none') {
        layer.style.display = 'block';
        layer2.innerHTML = '[-]';
        $.cookie('sub_' + id, 'on');
    } else {
        layer.style.display = 'none';
        layer2.innerHTML = '[+]';
        $.cookie('sub_' + id, 'off');
    }
    return false;
}

function checkLayer(id) {
    var vl = $.cookie('sub_' + id);
    if (vl == 'off') {
        showHideLayer(id);
    }
}

var filterTimer;

function filterSearch() {
    if (window.MDJAdminSearch) {
        window.MDJAdminSearch.search();
    }
    return false;
}

function setFilter(value) {
    if (window.MDJAdminSearch) {
        return window.MDJAdminSearch.setFilter(value);
    }
    return false;
}

function filterModules() {
    var input = document.querySelector('input[name="filter_modules"]');
    var title = input ? input.value : '';
    title = String(title || '').trim();

    $.cookie('panelFilter', title);
    var normalized = title.toLowerCase();

    var filterResults = document.getElementById('filter_add');
    if (normalized !== '') {
        if (filterResults) {
            filterResults.style.display = '';
        }
        clearTimeout(filterTimer);
        filterTimer = setTimeout(filterSearch, 600);
    } else {
        if (filterResults) {
            filterResults.style.display = 'none';
        }
        clearTimeout(filterTimer);
    }

    document.querySelectorAll('.md-admin-sidebar__item[data-md-module]').forEach(function (item) {
        var moduleTitle = String(item.getAttribute('data-md-title') || '').toLowerCase();
        var categoryTitle = String(item.getAttribute('data-md-category') || '').toLowerCase();
        var matches = normalized === '' || moduleTitle.indexOf(normalized) !== -1 || categoryTitle.indexOf(normalized) !== -1;
        item.style.display = matches ? '' : 'none';
    });

    document.querySelectorAll('.md-admin-sidebar__group').forEach(function (group) {
        var hasVisible = false;
        group.querySelectorAll('.md-admin-sidebar__item[data-md-module]').forEach(function (item) {
            if (item.style.display !== 'none') {
                hasVisible = true;
            }
        });
        group.style.display = hasVisible ? '' : 'none';
    });
}

jQuery.fn.center = function () {
    this.css('position', 'absolute');
    this.css('top', ($(window).height() - this.height()) / 2 + $(window).scrollTop() + 'px');
    this.css('left', ($(window).width() - this.width()) / 2 + $(window).scrollLeft() + 'px');
    return this;
};

function openTWindow(winId, winTitle, pageURL, wdth, hdth) {
    parent.$.fancybox.open({src: pageURL, type: 'iframe', iframe: {preload: false}});
    return false;
}

function openModalTWindow(winId, winTitle, pageURL, wdth, hdth) {
    parent.$.fancybox.open({src: pageURL, type: 'iframe', iframe: {preload: false}});
    return false;
}

function leftPanelToggle() {
    var url = window.location.href;
    var dURL = url.replace(/#$/, '');
    if (dURL.indexOf('?') !== -1) {
        dURL += '&toggleLeftPanel=1';
    } else {
        dURL += '?toggleLeftPanel=1';
    }
    window.location.href = dURL;
    return false;
}

var hrefLocation = window.location.href;
hrefLocation = hrefLocation.replace(/^.*\/\/[^\/]+/, '');
var urlInProgress = '';

var consoleDebugTimer;

function updateConsoleDebug() {
    if ($('#console_debug').is(':hidden')) {
        clearTimeout(consoleDebugTimer);
        return;
    }

    var urlDebug = window.ROOTHTML + 'ajax/xray.html?ajax=1&op=getcontent&lines=10';
    $.ajax({
        url: urlDebug
    }).done(function (data) {
        if (typeof data === 'string') {
            data = safeJsonParse(data, {});
        } else if (!data || typeof data !== 'object') {
            data = {};
        }

        if (data.CONTENT) {
            $('#console_debug').html(data.CONTENT);
        } else {
            $('#console_debug').html('<img style="background: white;border-radius: 50px;padding: 5px;" src="' + window.ROOTHTML + 'assets/img/ajax-loader.gif"/> Консоль пуста. Дождитесь новых данных...');
        }
    });
    consoleDebugTimer = setTimeout(updateConsoleDebug, 1000);
}

function consoleDebugToggle() {
    $('#console_debug').toggle();
    if ($('#console_debug').is(':visible')) {
        $.cookie('debug', 'on', {expires: 1});
    } else {
        $.cookie('debug', 'off', {expires: 1});
    }
    updateConsoleDebug();
    return false;
}

var locationTimer;

function checkLocationHref() {
    clearTimeout(locationTimer);
}

var loaderTimer;

function loadInProgress() {
    $('.sp-replacer').remove();
    $('.sp-container').remove();
    $('#preloader').show('fast');
    document.dispatchEvent(new Event('md:before-part-content-replace'));
    $('#partLoadContent').html('');
    $('#stopLoadBtnPreloader').attr('href', urlInProgress);
    return false;
}

var requestCounter = 0;

function partLoad(link) {
    if (document.body.classList.contains('md-admin-print')) {
        return true;
    }
    if (typeof link == 'undefined') {
        return;
    }

    var maxId = setTimeout(function () {}, 0);
    for (var i = 0; i < maxId; i += 1) {
        clearTimeout(i);
    }

    ajaxManager.stop();
    requestCounter++;
    var seqNumber = requestCounter;
    var urlPartLoad = link;
    urlInProgress = link;
    hrefLocation = link;
    hrefLocation = hrefLocation.replace(/^.*\/\/[^\/]+/, '');
    clearTimeout(locationTimer);
    locationTimer = setTimeout(checkLocationHref, 1000);
    urlPartLoad += (urlPartLoad.indexOf('?') === -1 ? '?' : '&') + 'part_load=1';

    var cachedKey = simple_hash(urlPartLoad);
    var cachedContent = localStorage.getItem(cachedKey);
    if (typeof cachedContent == 'string') {
        window.history.pushState('', window.title, link);
        document.dispatchEvent(new Event('md:before-part-content-replace'));
        $('#partLoadContent').html(cachedContent);
        if (window.MDJAdminUI) {
            window.MDJAdminUI.boot(document.getElementById('partLoadContent'));
        }
    } else {
        cachedContent = '';
        loaderTimer = setTimeout(loadInProgress, 2000);
    }

    $.ajax({
        url: urlPartLoad,
        urlInProgress: urlInProgress
    }).done(function (data) {
        $('#preloader').hide('fast');
        clearTimeout(loaderTimer);
        var obj;
        try {
            obj = JSON.parse(data);
        } catch (e) {
            window.location.href = this.urlInProgress;
            return false;
        }
        if ('undefined' !== typeof obj.REDIRECT) {
            link = obj.REDIRECT;
        }

        if (obj.NEED_RELOAD != 0) {
            window.location.href = link;
        } else {
            window.history.pushState('', window.title, link);
            if (cachedContent != obj.CONTENT) {
                document.dispatchEvent(new Event('md:before-part-content-replace'));
                $('#partLoadContent').html(obj.CONTENT);
                if (window.MDJAdminUI) {
                    window.MDJAdminUI.boot(document.getElementById('partLoadContent'));
                }
                localStorage.setItem(cachedKey, obj.CONTENT);
            }
            if (('undefined' !== typeof obj.TITLE) && obj.TITLE != '') {
                window.document.title = obj.TITLE;
            }

            $('input[name=filter_modules]').off('keyup.mdFilter').on('keyup.mdFilter', function () {
                filterModules();
            });

            $('.sp-container').remove();
        }
    });
    return false;
}

function dismissNotification(id) {
    var url = '?ajax_panel=1&op=dismiss_notification&id=' + id;
    $.ajax({
        url: url,
        success: function (data) {
            if (data == 'OK') {
                $('#notification' + id).fadeOut(180, function () { $(this).remove(); });
            }
        }
    });
    return false;
}


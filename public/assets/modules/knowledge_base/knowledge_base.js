/*
 * Knowledge base page behavior.
 *
 * The list is fully server-rendered. This file only filters the rendered cards
 * by audience/category/query and keeps the filter state in the URL, so a link
 * to "user documents about commands" can be shared. The article view adds a
 * lightweight scrollspy for the table of contents.
 */
(function (window, document) {
    'use strict';

    var AUDIENCES = ['all', 'user', 'agent'];
    var articleObserver = null;
    var articleHashHandler = null;

    function queryAll(root, selector) {
        var scope = root && root.querySelectorAll ? root : document;
        return Array.prototype.slice.call(scope.querySelectorAll(selector));
    }

    function normalize(value) {
        return String(value || '')
            .toLowerCase()
            .replace(/\s+/g, ' ')
            .trim();
    }

    function setPressed(button, pressed) {
        button.classList.toggle('is-active', pressed);
        button.setAttribute('aria-pressed', pressed ? 'true' : 'false');
    }

    function readState(page) {
        var params = new URLSearchParams(window.location.search || '');
        var audience = String(params.get('audience') || 'all').toLowerCase();
        var category = String(params.get('cat') || 'all');

        if (AUDIENCES.indexOf(audience) === -1) {
            audience = 'all';
        }

        var categories = queryAll(page, '[data-kb-category]').map(function (button) {
            return button.getAttribute('data-kb-category') || '';
        });
        if (category !== 'all' && categories.indexOf(category) === -1) {
            category = 'all';
        }

        return {
            q: String(params.get('q') || ''),
            audience: audience,
            category: category
        };
    }

    function writeState(page, state) {
        var params = new URLSearchParams(window.location.search || '');

        if (state.q) {
            params.set('q', state.q);
        } else {
            params.delete('q');
        }

        if (state.audience !== 'all') {
            params.set('audience', state.audience);
        } else {
            params.delete('audience');
        }

        if (state.category !== 'all') {
            params.set('cat', state.category);
        } else {
            params.delete('cat');
        }

        var search = params.toString();
        var url = window.location.pathname + (search ? '?' + search : '') + window.location.hash;
        try {
            window.history.replaceState(null, '', url);
        } catch (error) {
            /* file:// and a few embedded browsers forbid history updates. */
        }
    }

    function initList(list) {
        if (!list || list.getAttribute('data-kb-list-ready') === '1') {
            return;
        }
        list.setAttribute('data-kb-list-ready', '1');

        var page = list.closest('.md-kb-page') || document;
        var searchInput = page.querySelector('[data-kb-search]');
        var cards = queryAll(list, '[data-kb-card]');
        var groups = queryAll(list, '[data-kb-group]');
        var audienceButtons = queryAll(page, '[data-kb-audience]');
        var categoryButtons = queryAll(page, '[data-kb-category]');
        var resetButtons = queryAll(page, '[data-kb-reset]');
        var visibleNodes = queryAll(page, '[data-kb-visible]');
        var totalNodes = queryAll(page, '[data-kb-total]');
        var empty = page.querySelector('[data-kb-empty]');
        var state = readState(page);

        if (searchInput) {
            searchInput.value = state.q;
        }

        function apply(updateUrl) {
            var query = normalize(searchInput ? searchInput.value : '');
            var queryTokens = query === '' ? [] : query.split(' ').filter(Boolean);
            var visible = 0;

            cards.forEach(function (card) {
                var haystack = normalize(card.getAttribute('data-search'));
                var audiences = String(card.getAttribute('data-audiences') || '')
                    .split(/\s+/)
                    .filter(Boolean);
                var category = card.getAttribute('data-category') || '';

                var matchesQuery = queryTokens.every(function (token) {
                    return haystack.indexOf(token) !== -1;
                });
                var matchesAudience = state.audience === 'all' || audiences.indexOf(state.audience) !== -1;
                var matchesCategory = state.category === 'all' || category === state.category;
                var show = matchesQuery && matchesAudience && matchesCategory;

                card.hidden = !show;
                if (show) {
                    visible++;
                }
            });

            groups.forEach(function (group) {
                var shown = queryAll(group, '[data-kb-card]').filter(function (card) {
                    return !card.hidden;
                }).length;
                group.hidden = shown === 0;

                var counter = group.querySelector('[data-kb-group-count]');
                if (counter) {
                    counter.textContent = String(shown);
                }
            });

            visibleNodes.forEach(function (node) {
                node.textContent = String(visible);
            });
            totalNodes.forEach(function (node) {
                node.textContent = String(cards.length);
            });

            if (empty) {
                empty.hidden = visible > 0 || cards.length === 0;
            }

            var filtered = query !== '' || state.audience !== 'all' || state.category !== 'all';
            resetButtons.forEach(function (button) {
                button.hidden = !filtered;
            });

            audienceButtons.forEach(function (button) {
                setPressed(button, (button.getAttribute('data-kb-audience') || 'all') === state.audience);
            });
            categoryButtons.forEach(function (button) {
                setPressed(button, (button.getAttribute('data-kb-category') || 'all') === state.category);
            });

            if (updateUrl !== false) {
                writeState(page, {
                    q: query,
                    audience: state.audience,
                    category: state.category
                });
            }
        }

        audienceButtons.forEach(function (button) {
            button.addEventListener('click', function () {
                var value = button.getAttribute('data-kb-audience') || 'all';
                state.audience = AUDIENCES.indexOf(value) === -1 ? 'all' : value;
                apply();
            });
        });

        categoryButtons.forEach(function (button) {
            button.addEventListener('click', function () {
                state.category = button.getAttribute('data-kb-category') || 'all';
                apply();
            });
        });

        resetButtons.forEach(function (button) {
            button.addEventListener('click', function () {
                state.q = '';
                state.audience = 'all';
                state.category = 'all';
                if (searchInput) {
                    searchInput.value = '';
                    searchInput.focus();
                }
                apply();
            });
        });

        if (searchInput) {
            var timer = null;
            searchInput.addEventListener('input', function () {
                window.clearTimeout(timer);
                timer = window.setTimeout(apply, 120);
            });
            searchInput.addEventListener('keydown', function (event) {
                if (event.key === 'Escape') {
                    searchInput.value = '';
                    apply();
                }
            });
        }

        apply(false);
    }

    function teardownArticle() {
        if (articleObserver) {
            articleObserver.disconnect();
            articleObserver = null;
        }
        if (articleHashHandler) {
            window.removeEventListener('hashchange', articleHashHandler);
            articleHashHandler = null;
        }
    }

    function initArticle(article) {
        if (!article || article.getAttribute('data-kb-article-ready') === '1') {
            return;
        }
        article.setAttribute('data-kb-article-ready', '1');
        teardownArticle();

        var links = queryAll(article, '[data-kb-toc-link]');
        var headings = queryAll(article, 'h2[id], h3[id]');
        if (!links.length || !headings.length) {
            return;
        }

        var linksById = {};
        links.forEach(function (link) {
            linksById[link.getAttribute('data-kb-toc-link')] = link;
        });

        function setActive(id) {
            links.forEach(function (link) {
                var active = link.getAttribute('data-kb-toc-link') === id;
                link.classList.toggle('is-active', active);
                if (active) {
                    link.setAttribute('aria-current', 'location');
                } else {
                    link.removeAttribute('aria-current');
                }
            });
        }

        if ('IntersectionObserver' in window) {
            articleObserver = new IntersectionObserver(function (entries) {
                var visible = entries.filter(function (entry) {
                    return entry.isIntersecting;
                });
                if (visible.length) {
                    setActive(visible[0].target.id);
                }
            }, {
                rootMargin: '-88px 0px -65% 0px',
                threshold: [0, 1]
            });

            headings.forEach(function (heading) {
                articleObserver.observe(heading);
            });
        }

        articleHashHandler = function () {
            var raw = String(window.location.hash || '').replace(/^#/, '');
            var id = raw;
            try {
                id = decodeURIComponent(raw);
            } catch (error) {
                id = raw;
            }
            if (id && linksById[id]) {
                setActive(id);
            }
        };
        window.addEventListener('hashchange', articleHashHandler);
    }

    function init(root) {
        queryAll(root, '[data-kb-list]').forEach(initList);
        var articles = queryAll(root, '[data-kb-article]');
        if (articles.length) {
            articles.forEach(initArticle);
        } else {
            teardownArticle();
        }
    }

    if (window.MDJAdminUI && typeof window.MDJAdminUI.registerModuleUI === 'function') {
        window.MDJAdminUI.registerModuleUI('knowledge-base', { init: init });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            init(document);
        });
    } else {
        init(document);
    }
})(window, document);

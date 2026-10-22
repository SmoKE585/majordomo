/*
 * Syntax highlighting for read-only MDJUI::codeSample() blocks.
 *
 * Built to assets/admin/codehighlight.js + codehighlight.css and loaded on
 * demand by the shared code-sample module. Only the languages used by hints
 * are registered here; this keeps the chunk small compared to CodeMirror.
 */
import '../../css/codehighlight.css';
import hljs from 'highlight.js/lib/core';
import bash from 'highlight.js/lib/languages/bash';
import css from 'highlight.js/lib/languages/css';
import javascript from 'highlight.js/lib/languages/javascript';
import json from 'highlight.js/lib/languages/json';
import php from 'highlight.js/lib/languages/php';
import python from 'highlight.js/lib/languages/python';
import xml from 'highlight.js/lib/languages/xml';
import yaml from 'highlight.js/lib/languages/yaml';

hljs.registerLanguage('bash', bash);
hljs.registerLanguage('css', css);
hljs.registerLanguage('javascript', javascript);
hljs.registerLanguage('json', json);
hljs.registerLanguage('php', php);
hljs.registerLanguage('python', python);
hljs.registerLanguage('xml', xml);
hljs.registerLanguage('yaml', yaml);

hljs.configure({ ignoreUnescapedHTML: true });

window.MDCodeHighlight = {
    highlightElement: function (code) {
        if (!code || code.dataset.mdHighlighted === '1') {
            return;
        }
        try {
            hljs.highlightElement(code);
            code.dataset.mdHighlighted = '1';
            var sample = code.closest('.md-code-sample');
            if (sample) {
                sample.classList.add('is-highlighted');
            }
        } catch (error) {
            if (window.console && window.console.warn) {
                window.console.warn('Code sample highlight failed', error);
            }
        }
    },
    highlight: function (root) {
        var scope = root || document;
        Array.prototype.slice.call(scope.querySelectorAll('[data-md-code-sample] code[class*="language-"]')).forEach(function (code) {
            window.MDCodeHighlight.highlightElement(code);
        });
    }
};

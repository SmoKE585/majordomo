<?php

namespace MajorDoMo\Core\Api;

/**
 * Выполнение кода из консоли X-Ray.
 *
 * 2026-10-22 — цикл eval вынесен из modules/inc_panel_ajax.php, чтобы им
 * пользовались и старый AJAX панели, и /api/v1/panel/console.
 */
final class PanelConsole
{
    public function run(string $command): string
    {
        $level = ob_get_level();
        ob_start();
        try {
            foreach (explode('PHP_EOL', $command) as $value) {
                $value = trim($value);
                if ($value === '') {
                    continue;
                }
                if (substr(mb_strtolower($value), 0, 4) === 'echo'
                    || mb_substr($value, 0, 1) === '$'
                    || preg_match('/include/', $value)) {
                    $this->evaluate($value);
                } else {
                    $this->evaluate($value, true);
                }
            }
            return (string)ob_get_clean();
        } catch (\Throwable $error) {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
            throw $error;
        }
    }

    private function evaluate(string $code, bool $print = false): void
    {
        if ($print) {
            if (mb_substr($code, -1) === ';') {
                $code = mb_substr($code, 0, -1);
            }
            eval('print_r(' . $code . ');');
            return;
        }
        setEvalCode($code);
        try {
            eval($code);
        } finally {
            setEvalCode();
        }
    }
}

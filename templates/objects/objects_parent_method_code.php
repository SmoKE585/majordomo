<?php
/**
 * Код метода класса в редакторе метода объекта.
 *
 * Партиал подключается из `objects_edit_methods.html` через
 * `[#inc objects_parent_method_code.php#]`, когда у класса есть реализация.
 * Код только для чтения, поэтому выводится общим компонентом
 * `MDJUI::codeSample()`: подсветка, номера строк и копирование вместо
 * безымянного `<pre>`.
 *
 * @var array<string, mixed> $data Данные шаблона.
 */

if (!class_exists('MDJUI')) {
    require_once(ROOT . 'lib/ui/MDJUI.php');
}

$data = is_array($data ?? null) ? $data : array();

$code = (string)($data['PARENT_METHOD_CODE_RAW'] ?? '');
if (trim($code) === '') {
    return;
}

// Python-методы доступны только при установленном интерпретаторе; метка нужна
// для подсветки. Сам код здесь не исполняется.
$language = 'php';
if (defined('PYTHON_PATH') && function_exists('isItPythonCode') && isItPythonCode($code)) {
    $language = 'python';
}

echo MDJUI::codeSample(array(
    'language' => $language,
    'code' => $code,
    'title' => 'Исходный код',
    'caption' => 'Код класса выполняется, если в методе объекта включён вызов родителя.',
    'max_height' => '360px',
));

<?php

namespace MajorDoMo\Core;

/** User methods and validators retain the original object as $this. */
final class CodeExecutor
{
    public function evaluate(object $object, string $code, array &$variables): mixed
    {
        $execute = function (string $code, array &$variables) {
            extract($variables, EXTR_REFS | EXTR_SKIP);
            return eval($code);
        };
        $bound = $execute->bindTo($object, get_class($object));
        try { return $bound($code, $variables); }
        finally { setEvalCode(); }
    }
}

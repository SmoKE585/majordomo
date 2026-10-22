<?php

namespace MajorDoMo\Core\Console;

/** One concrete class per command; construction must have no side effects. */
abstract class Command
{
    abstract public function name(): string;
    abstract public function description(): string;
    abstract public function execute(Input $input, Output $output, Context $context): int;

    /** @return array<string,string> Argument name => description; suffix ? means optional. */
    public function arguments(): array { return array(); }

    /** @return array<string,string> Option name => description; suffix = requires a value. */
    public function options(): array { return array(); }
}

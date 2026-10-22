<?php

namespace MajorDoMo\Core\Console\Commands;

use MajorDoMo\Core\Console\{Command, Input, Output, Context};

final class UsersPasswordCommand extends Command
{
    public function name(): string { return 'users:password'; }
    public function description(): string { return 'Установить новый пароль выбранному пользователю'; }
    public function arguments(): array { return array('id' => 'ID из users:list; пароль вводится скрыто дважды'); }
    public function options(): array { return array('password-stdin' => 'Прочитать одну строку пароля из перенаправленного stdin вместо диалога'); }
    public function execute(Input $input, Output $output, Context $context): int
    {
        $value = (string)$input->argument('id');
        if (!preg_match('/^[1-9][0-9]*$/D', $value) || (float)$value > min(PHP_INT_MAX, 4294967295)) throw new \InvalidArgumentException('Укажите положительный ID пользователя из users:list');
        $terminal = $context->terminalInput();
        $stdin = (bool)$input->option('password-stdin');
        if ($output->isJson() && !$stdin) throw new \InvalidArgumentException('Для users:password --json передайте пароль через --password-stdin.');
        if (!$stdin && !$terminal->isInteractive()) throw new \InvalidArgumentException('Нужен интерактивный терминал или --password-stdin.');
        $users = $context->users();
        $user = $users->find((int)$value);
        $output->title('Смена пароля');
        $output->table(array('ID', 'Логин', 'Имя'), array(array($user['ID'], $user['USERNAME'], $user['NAME'])));
        $password = '';
        $confirmation = '';
        try {
            if ($stdin) $password = $terminal->passwordFromStdin();
            else {
                $password = $terminal->secret('Новый пароль');
                $confirmation = $terminal->secret('Повторите пароль');
                if (!hash_equals($password, $confirmation)) throw new \InvalidArgumentException('Пароли не совпадают. Изменения не сохранены.');
            }
            $users->changePassword((int)$value, $password);
        } finally {
            unset($password, $confirmation);
        }
        $output->success('Пароль пользователя ' . $user['USERNAME'] . ' (ID ' . $user['ID'] . ') изменён.');
        $output->result(array('user' => $user, 'changed' => true));
        return 0;
    }
}

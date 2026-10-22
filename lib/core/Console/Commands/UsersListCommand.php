<?php

namespace MajorDoMo\Core\Console\Commands;

use MajorDoMo\Core\Console\{Command, Input, Output, Context};

final class UsersListCommand extends Command
{
    public function name(): string { return 'users:list'; }
    public function description(): string { return 'Пользователи: ID, логин, имя и права'; }
    public function options(): array
    {
        return array('search=' => 'Поиск по логину или имени', 'limit=' => 'Размер страницы: 1–100, по умолчанию 20', 'offset=' => 'Смещение, по умолчанию 0');
    }
    public function execute(Input $input, Output $output, Context $context): int
    {
        $limit = $input->integer('limit', 20, 1, 100);
        $offset = $input->integer('offset', 0, 0, PHP_INT_MAX);
        $search = (string)$input->option('search', '');
        if (strlen($search) > 255) throw new \InvalidArgumentException('Поисковая строка длиннее 255 байт');
        $page = $context->users()->page($search, $limit, $offset);
        $output->title('Пользователи');
        $output->table(array('ID', 'Логин', 'Имя', 'Права', 'Основной'), array_map(static fn(array $user) => array(
            $user['ID'], $user['USERNAME'], $user['NAME'], (int)$user['IS_ADMIN'] ? 'Администратор' : 'Пользователь', (int)$user['IS_DEFAULT'] ? 'yes' : 'no',
        ), $page['users']));
        $output->muted('Показано: ' . count($page['users']) . ' из ' . $page['total']);
        if ($offset + $limit < $page['total']) $output->muted('Следующая страница: добавьте --offset=' . ($offset + $limit));
        $output->muted('Смена пароля: php majordomo users:password <id>');
        $output->result($page);
        return 0;
    }
}

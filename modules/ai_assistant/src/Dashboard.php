<?php

declare(strict_types=1);

namespace MajorDoMo\Modules\AiAssistant;

use MajorDoMo\Core\Database;

/**
 * Короткая сводка для главной страницы: что уже настроено и что сделать дальше.
 *
 * Изменения:
 * 2026-10-23 — карточка-приглашение на дашборде без сетевых проверок.
 */
final class Dashboard
{
    public function __construct(private Database $db) {}

    public static function shared(): self
    {
        return new self(\MajorDoMo\Core\Core::database());
    }

    public function status(): array
    {
        $providers = (int)($this->db->one('SELECT COUNT(*) AS C FROM ai_assistant_providers')['C'] ?? 0);
        $models = (int)($this->db->one('SELECT COUNT(*) AS C FROM ai_assistant_models WHERE ENABLED=1')['C'] ?? 0);
        $agents = (int)($this->db->one('SELECT COUNT(*) AS C FROM ai_assistant_agents WHERE DELETED=0 AND ENABLED=1 AND MODEL_ID IS NOT NULL')['C'] ?? 0);
        $readyIndexes = (int)($this->db->one("SELECT COUNT(*) AS C FROM ai_assistant_indexes WHERE STATUS='ready' AND DIMENSION>0")['C'] ?? 0);
        $qdrant = (int)($this->db->one("SELECT COUNT(*) AS C FROM ai_assistant_knowledge WHERE ID=1 AND QDRANT_URL<>''")['C'] ?? 0) > 0;
        $ready = $providers > 0 && $models > 0 && $agents > 0;
        $suggestions = array();
        if ($providers < 1) {
            $suggestions[] = array('title' => 'Подключите провайдера', 'text' => 'OpenAI, локальная модель или совместимый шлюз.',
                'url' => 'panel/ai_assistant/providers/add.html', 'icon' => 'server');
        } elseif ($models < 1) {
            $suggestions[] = array('title' => 'Добавьте модель', 'text' => 'Укажите точный идентификатор и проверьте соединение.',
                'url' => 'panel/ai_assistant/models/add.html', 'icon' => 'cpu');
        } elseif ($agents < 1) {
            $suggestions[] = array('title' => 'Назначьте модель агенту', 'text' => 'Стандартные профили уже созданы.',
                'url' => 'panel/ai_assistant/agents.html', 'icon' => 'robot');
        } else {
            $suggestions[] = array('title' => 'Кто меняет свойство?', 'text' => 'Помощник найдёт обработчик, привязки и цепочку.',
                'url' => 'panel/ai_assistant.html', 'icon' => 'route');
            $suggestions[] = array('title' => 'Объяснить сценарий', 'text' => 'Попросите разобрать код и найти риск.',
                'url' => 'panel/ai_assistant.html', 'icon' => 'code');
            if ($qdrant && $readyIndexes < 1) {
                $suggestions[] = array('title' => 'Соберите индекс знаний', 'text' => 'Qdrant подключён, остался индекс.',
                    'url' => 'panel/ai_assistant/knowledge.html', 'icon' => 'database');
            } else {
                $suggestions[] = array('title' => 'База знаний', 'text' => 'Документация ядра и установленных модулей.',
                    'url' => 'panel/ai_assistant/knowledge.html', 'icon' => 'book-2');
            }
        }
        return array(
            'installed' => true,
            'ready' => $ready,
            'providers' => $providers,
            'models' => $models,
            'agents' => $agents,
            'qdrant' => $qdrant,
            'ready_indexes' => $readyIndexes,
            'suggestions' => $suggestions,
            'chat_url' => 'panel/ai_assistant.html',
            'setup_url' => 'panel/ai_assistant/models.html',
            'agents_url' => 'panel/ai_assistant/agents.html',
            'knowledge_url' => 'panel/ai_assistant/knowledge.html',
        );
    }
}

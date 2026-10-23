<?php

declare(strict_types=1);

namespace MajorDoMo\Modules\AiAssistant;

use MajorDoMo\Core\Database;

/**
 * Короткая сводка для главной страницы: что уже настроено и что сделать дальше.
 *
 * Изменения:
 * 2026-10-23 — пошаговый онбординг (3 шага) и карточка-приглашение на дашборде.
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

        $completedSteps = ($providers > 0 ? 1 : 0) + ($models > 0 ? 1 : 0) + ($agents > 0 ? 1 : 0);

        $steps = array(
            array(
                'number' => 1,
                'key' => 'provider',
                'title' => 'Провайдер API',
                'text' => $providers > 0 ? 'Подключен (' . $providers . ')' : 'OpenAI, Ollama, DeepSeek...',
                'done' => $providers > 0,
                'current' => $providers === 0,
                'url' => $providers > 0 ? 'panel/ai_assistant/providers.html' : 'panel/ai_assistant/providers/add.html',
                'icon' => 'server',
            ),
            array(
                'number' => 2,
                'key' => 'model',
                'title' => 'Языковая модель',
                'text' => $models > 0 ? 'Активно (' . $models . ')' : 'GPT-4o, Claude, Llama 3...',
                'done' => $models > 0,
                'current' => $providers > 0 && $models === 0,
                'url' => $models > 0 ? 'panel/ai_assistant/models.html' : 'panel/ai_assistant/models/add.html',
                'icon' => 'cpu',
            ),
            array(
                'number' => 3,
                'key' => 'agent',
                'title' => 'Назначить агенту',
                'text' => $agents > 0 ? 'Назначено (' . $agents . ')' : 'Связать модель с профилем',
                'done' => $agents > 0,
                'current' => $providers > 0 && $models > 0 && $agents === 0,
                'url' => 'panel/ai_assistant/agents.html',
                'icon' => 'robot',
            ),
        );

        $nextStepUrl = 'panel/ai_assistant/providers/add.html';
        $nextStepTitle = 'Шаг 1: Добавить провайдера';
        if ($providers > 0 && $models === 0) {
            $nextStepUrl = 'panel/ai_assistant/models/add.html';
            $nextStepTitle = 'Шаг 2: Добавить модель';
        } elseif ($providers > 0 && $models > 0 && $agents === 0) {
            $nextStepUrl = 'panel/ai_assistant/agents.html';
            $nextStepTitle = 'Шаг 3: Назначить агенту';
        }

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
            'steps' => $steps,
            'completed_steps' => $completedSteps,
            'next_step_url' => $nextStepUrl,
            'next_step_title' => $nextStepTitle,
            'chat_url' => 'panel/ai_assistant.html',
            'setup_url' => 'panel/ai_assistant/models.html',
            'agents_url' => 'panel/ai_assistant/agents.html',
            'knowledge_url' => 'panel/ai_assistant/knowledge.html',
        );
    }
}

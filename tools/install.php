<?php
/**
 * First-run setup CLI.
 *
 * Usage:
 *   php tools/install.php status
 *   php tools/install.php check
 *   php tools/install.php test-db
 *   php tools/install.php reset
 *   php tools/install.php complete
 */

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/config.php';

use MajorDoMo\Core\Install\InstallEnvironment;
use MajorDoMo\Core\Install\InstallState;

$root = dirname(__DIR__);
$command = $argv[1] ?? 'help';

switch ($command) {
    case 'status':
        $state = InstallState::state($root);
        echo 'Setup complete: ' . ($state['complete'] ? 'yes' : 'no') . PHP_EOL;
        echo 'Status:         ' . $state['status'] . PHP_EOL;
        echo 'Marker:         ' . InstallState::markerPath($root) . ($state['marker_exists'] ? ' (exists)' : ' (missing)') . PHP_EOL;
        echo 'Legacy marker:  ' . ($state['legacy'] ? 'yes' : 'no') . PHP_EOL;
        if ($state['completed_at'] !== '') {
            echo 'Completed at:   ' . $state['completed_at'] . PHP_EOL;
        }
        echo 'Install URL:    ' . InstallState::installUrl(defined('ROOTHTML') ? ROOTHTML : '/') . PHP_EOL;
        exit(0);

    case 'check':
        $environment = new InstallEnvironment($root);
        $summary = $environment->summary();
        foreach ($environment->checks() as $group) {
            echo PHP_EOL . '== ' . $group['title'] . ' ==' . PHP_EOL;
            foreach ($group['items'] as $item) {
                $status = strtoupper((string)($item['status'] ?? 'info'));
                echo '[' . str_pad($status, 4) . '] ' . $item['label'] . ': ' . ($item['value'] ?? '') . PHP_EOL;
                if (!empty($item['hint'])) {
                    echo '         ' . $item['hint'] . PHP_EOL;
                }
            }
        }
        echo PHP_EOL . 'Summary: ' . $summary['ok'] . ' ok, ' . $summary['warn'] . ' warning(s), '
            . $summary['fail'] . ' error(s), ' . $summary['blocking'] . ' blocking.' . PHP_EOL;
        exit(($summary['blocking'] > 0 || $summary['fail'] > 0) ? 1 : 0);

    case 'test-db':
        $environment = new InstallEnvironment($root);
        $status = $environment->databaseStatus();
        echo ($status['ok'] ? '[ OK ] ' : '[FAIL] ') . $status['message'] . PHP_EOL;
        if ($status['ok']) {
            echo 'Server: ' . $status['server'] . PHP_EOL;
            echo 'Tables: ' . count($status['tables']) . PHP_EOL;
            if ($status['missing'] !== array()) {
                echo 'Missing required: ' . implode(', ', $status['missing']) . PHP_EOL;
            }
            echo 'Admin exists: ' . ($status['admin_exists'] === true ? 'yes' : ($status['admin_exists'] === false ? 'no' : 'unknown')) . PHP_EOL;
        }
        exit($status['ok'] && $status['missing'] === array() ? 0 : 1);

    case 'reset':
        if (!InstallState::reset($root, 'cli reset')) {
            fwrite(STDERR, 'Could not write the setup marker. Check permissions on cms/modules_installed.' . PHP_EOL);
            exit(1);
        }
        echo 'The setup wizard is armed again. Open ' . InstallState::installUrl(defined('ROOTHTML') ? ROOTHTML : '/') . PHP_EOL;
        echo 'The database and all existing data were not changed.' . PHP_EOL;
        exit(0);

    case 'complete':
        if (!InstallState::markComplete($root, array('source' => 'cli'))) {
            fwrite(STDERR, 'Could not write the setup marker. Check permissions on cms/modules_installed.' . PHP_EOL);
            exit(1);
        }
        echo 'The setup marker is now marked complete. The wizard will not intercept requests.' . PHP_EOL;
        exit(0);

    case 'help':
    default:
        echo "Usage:\n"
            . "  php tools/install.php status    Show setup state and marker paths\n"
            . "  php tools/install.php check     Run environment diagnostics\n"
            . "  php tools/install.php test-db   Test the database from .env\n"
            . "  php tools/install.php reset     Reopen the setup wizard\n"
            . "  php tools/install.php complete  Mark setup as complete without the wizard\n";
        exit($command === 'help' ? 0 : 1);
}

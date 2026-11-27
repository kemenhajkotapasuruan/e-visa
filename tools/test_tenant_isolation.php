<?php
require __DIR__ . '/../config.php';
require __DIR__ . '/../includes/Auth.php';

Auth::repairAuthTenantIntegrity();

$users = loadJsonFile(STORAGE_DIR . '/data/auth/users.json', []);
foreach ($users as $u) {
    if (($u['username'] ?? '') !== 'KD1322') {
        continue;
    }
    $lem = Auth::resolveLembagaForUser($u);
    if (!$lem) {
        echo "KD1322: no lembaga\n";
        exit(1);
    }
    tenantBindPaths($lem['slug']);
    $records = getRecords();
    $settings = getSettings();
    echo "KD1322 slug={$lem['slug']} records=" . count($records) . "\n";
    echo "source_email=" . ($settings['source_email'] ?? '') . "\n";
    echo "source_accounts=" . count($settings['source_accounts'] ?? []) . "\n";
    echo "gas_gmail=" . (strlen($settings['gas_gmail_webapp_url'] ?? '') ? 'set' : 'empty') . "\n";
    exit(0);
}
echo "KD1322 not found\n";
exit(1);

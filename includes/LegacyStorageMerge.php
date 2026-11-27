<?php
/**
 * Penggabungan data storage lama (storage/data, storage/biovisa) ke tenant lembaga.
 */

function legacyMergePasuruanTenant(string $slug = 'phu-pasuruan'): void
{
    $legacyData = STORAGE_DIR . DIRECTORY_SEPARATOR . 'data';
    $targetRoot = STORAGE_DIR . DIRECTORY_SEPARATOR . 'lembaga' . DIRECTORY_SEPARATOR . $slug;

    tenantEnsureDirs($slug);
    if (!is_dir($legacyData)) {
        return;
    }

    $tenantData = $targetRoot . DIRECTORY_SEPARATOR . 'data';
    if (!is_dir($tenantData)) {
        @mkdir($tenantData, 0755, true);
    }

    foreach (['settings.json', 'jamaah_master.json', 'sync_logs.json'] as $f) {
        $src = $legacyData . DIRECTORY_SEPARATOR . $f;
        $dst = $tenantData . DIRECTORY_SEPARATOR . $f;
        if (!is_file($src)) {
            continue;
        }
        if (!is_file($dst)) {
            @copy($src, $dst);
            continue;
        }
        $legacyJson = loadJsonFile($src, []);
        $tenantJson = loadJsonFile($dst, []);
        if (count($legacyJson) > count($tenantJson)) {
            @copy($src, $dst);
        }
    }

    legacyMergeRecordsIntoTenant($legacyData, $tenantData);
    legacyCopyBinaryAssets($targetRoot);
}

function legacyMergeRecordsIntoTenant(string $legacyData, string $tenantData): void
{
    $srcFile = $legacyData . DIRECTORY_SEPARATOR . 'records.json';
    $dstFile = $tenantData . DIRECTORY_SEPARATOR . 'records.json';
    if (!is_file($srcFile)) {
        return;
    }

    $legacyRecords = loadJsonFile($srcFile, []);
    if ($legacyRecords === []) {
        return;
    }

    $tenantRecords = loadJsonFile($dstFile, []);
    $byId = [];
    $byPassport = [];
    $byMessage = [];
    foreach ($tenantRecords as $idx => $rec) {
        if (!is_array($rec)) {
            continue;
        }
        $id = trim((string)($rec['id'] ?? ''));
        if ($id !== '') {
            $byId[$id] = $idx;
        }
        $passport = strtoupper(trim((string)($rec['paspor'] ?? '')));
        if ($passport !== '' && $passport !== 'TANPAPASPOR') {
            $byPassport[$passport] = $idx;
        }
        $msgId = trim((string)($rec['message_id'] ?? ''));
        if ($msgId !== '') {
            $byMessage[$msgId] = $idx;
        }
    }

    $changed = false;
    foreach ($legacyRecords as $legacy) {
        if (!is_array($legacy)) {
            continue;
        }
        $id = trim((string)($legacy['id'] ?? ''));
        $passport = strtoupper(trim((string)($legacy['paspor'] ?? '')));
        $messageId = trim((string)($legacy['message_id'] ?? ''));

        if ($id !== '' && isset($byId[$id])) {
            continue;
        }
        if ($messageId !== '' && isset($byMessage[$messageId])) {
            continue;
        }
        if ($passport !== '' && $passport !== 'TANPAPASPOR' && isset($byPassport[$passport])) {
            $idx = $byPassport[$passport];
            $existing = $tenantRecords[$idx];
            $legacyIds = $legacy['all_message_ids'] ?? (($legacy['message_id'] ?? '') !== '' ? [$legacy['message_id']] : []);
            $existingIds = $existing['all_message_ids'] ?? (($existing['message_id'] ?? '') !== '' ? [$existing['message_id']] : []);
            $mergedIds = array_values(array_unique(array_merge($existingIds, $legacyIds)));
            if ($mergedIds !== $existingIds) {
                $tenantRecords[$idx]['all_message_ids'] = $mergedIds;
                $changed = true;
            }
            continue;
        }

        $tenantRecords[] = $legacy;
        $newIdx = count($tenantRecords) - 1;
        if ($id !== '') {
            $byId[$id] = $newIdx;
        }
        if ($passport !== '' && $passport !== 'TANPAPASPOR') {
            $byPassport[$passport] = $newIdx;
        }
        if ($messageId !== '') {
            $byMessage[$messageId] = $newIdx;
        }
        $changed = true;
    }

    if (!$changed && is_file($dstFile) && count($tenantRecords) >= count($legacyRecords)) {
        return;
    }

    usort($tenantRecords, static function ($a, $b) {
        $ta = strtotime((string)($a['processed_at'] ?? $a['email_date'] ?? '')) ?: 0;
        $tb = strtotime((string)($b['processed_at'] ?? $b['email_date'] ?? '')) ?: 0;
        return $tb <=> $ta;
    });

    $normChanged = false;
    $tenantRecords = normalizeLegacySourceAccountLabels($tenantRecords, $normChanged);
    saveJsonFile($dstFile, array_values($tenantRecords));
}

function legacyCopyBinaryAssets(string $targetRoot): void
{
    $legacyBiovisa = STORAGE_DIR . DIRECTORY_SEPARATOR . 'biovisa';
    $dstBiovisa = $targetRoot . DIRECTORY_SEPARATOR . 'biovisa';
    if (is_dir($legacyBiovisa)) {
        foreach (glob($legacyBiovisa . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            if (!is_file($file)) {
                continue;
            }
            $d = $dstBiovisa . DIRECTORY_SEPARATOR . basename($file);
            if (!is_file($d)) {
                @copy($file, $d);
            }
        }
    }

    $legacyDok = STORAGE_DIR . DIRECTORY_SEPARATOR . 'dokumen';
    $dstDok = $targetRoot . DIRECTORY_SEPARATOR . 'dokumen';
    if (!is_dir($legacyDok)) {
        return;
    }
    foreach (glob($legacyDok . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [] as $sub) {
        $subName = basename($sub);
        $dstSub = $dstDok . DIRECTORY_SEPARATOR . $subName;
        if (!is_dir($dstSub)) {
            @mkdir($dstSub, 0755, true);
        }
        foreach (glob($sub . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            if (!is_file($file)) {
                continue;
            }
            $d = $dstSub . DIRECTORY_SEPARATOR . basename($file);
            if (!is_file($d)) {
                @copy($file, $d);
            }
        }
    }
}

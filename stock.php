<?php
// Shared stock storage. Stock is kept in data/stock.json as { "Product||Variation": quantity }.
const MGE_STOCK_FILE = __DIR__ . '/../data/stock.json';

function mge_stock_load(): array
{
    if (!is_file(MGE_STOCK_FILE)) {
        return [];
    }
    $data = json_decode((string) file_get_contents(MGE_STOCK_FILE), true);
    return is_array($data) ? $data : [];
}

// Read-modify-write under a lock so two orders / admin saves can't overwrite each other.
function mge_stock_update(callable $change): bool
{
    $dir = dirname(MGE_STOCK_FILE);
    if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
        return false;
    }
    $handle = fopen(MGE_STOCK_FILE, 'c+');
    if (!$handle) {
        return false;
    }
    flock($handle, LOCK_EX);
    $data = json_decode((string) stream_get_contents($handle), true);
    $data = $change(is_array($data) ? $data : []);
    ftruncate($handle, 0);
    rewind($handle);
    fwrite($handle, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);
    return true;
}

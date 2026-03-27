<?php
function is_synonym($a, $c) {
    $synonyms = [
        'convert' => ['transform', 'change'],
        'undo' => ['revert', 'rollback']
    ];
    foreach ($synonyms as $word => $list) {
        if ($a === $word && in_array($c, $list)) return true;
        if ($c === $word && in_array($a, $list)) return true;
    }
    return false;
}
?>
<?php
date_default_timezone_set('Asia/Manila');

function ensure_session(){ 
    if(session_status()!==PHP_SESSION_ACTIVE) session_start(); 
}

function redirect($p){ 
    header("Location: $p"); 
    exit; 
}

function h($s){ 
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); 
}

function transmute($raw, $max = 50){
    // Existing 50-point map
    static $map = [
        0=>50,1=>51,2=>52,3=>53,4=>54,5=>55,6=>56,7=>57,8=>58,9=>59,
        10=>60,11=>61,12=>62,13=>63,14=>64,15=>65,16=>66,17=>67,18=>68,19=>69,
        20=>70,21=>71,22=>72,23=>73,24=>74,25=>75,26=>76,27=>77,28=>78,29=>79,
        30=>80,31=>81,32=>82,33=>83,34=>84,35=>85,36=>86,37=>87,38=>88,39=>89,
        40=>90,41=>91,42=>92,43=>93,44=>94,45=>95,46=>96,47=>97,48=>98,49=>99,50=>100
    ];
    
    // If it's a 50 question exam, use your specific map
    if($max == 50 && isset($map[$raw])) {
        return $map[$raw];
    }
    
    // Backup: Standard Transmutation Formula (Base 50 + (Raw/Max * 50))
    // This ensures if you have 20 or 100 questions, it still works.
    if($max > 0) {
        return round(50 + (($raw / $max) * 50));
    }
    
    return 50;
}

function require_login(){ 
    ensure_session(); 
    if(empty($_SESSION['user'])) redirect('login.php'); 
}

function require_role($role){ 
    ensure_session(); 
    if(empty($_SESSION['user']) || $_SESSION['user']['role'] !== $role) redirect('login.php'); 
}

function is_role($role){ 
    ensure_session(); 
    return !empty($_SESSION['user']) && $_SESSION['user']['role'] === $role; 
}
?>
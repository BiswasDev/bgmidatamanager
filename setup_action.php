<?php
require 'db.php';
$action = $_POST['action'] ?? '';

if ($action === 'create_tournament') {
    $pdo->prepare("INSERT INTO tournaments (name) VALUES (?)")->execute([$_POST['name']]);
} elseif ($action === 'create_stage') {
    $pdo->prepare("INSERT INTO tournament_stages (tournament_id, stage_name) VALUES (?, ?)")->execute([$_POST['tournament_id'], $_POST['stage_name']]);
} elseif ($action === 'create_match') {
    if (empty($_POST['selected_teams'])) die("Error: Select teams.");
    
    $pdo->query("UPDATE matches SET is_active = FALSE");
    $pdo->prepare("INSERT INTO matches (tournament_stage_id, map_name, match_number, is_active) VALUES (?, ?, ?, TRUE)")->execute([$_POST['tournament_stage_id'], $_POST['map_name'], $_POST['match_number']]);
    $mId = $pdo->lastInsertId();

    foreach ($_POST['selected_teams'] as $tid) {
        $tData = $pdo->prepare("SELECT team_name, logo_path FROM tournament_teams WHERE id = ?"); $tData->execute([$tid]); $t = $tData->fetch();
        $pdo->prepare("INSERT INTO match_team_stats (match_id, team_name, logo_path) VALUES (?, ?, ?)")->execute([$mId, $t['team_name'], $t['logo_path']]);
        
        $pData = $pdo->prepare("SELECT ign FROM tournament_players WHERE team_id = ?"); $pData->execute([$tid]);
        foreach ($pData->fetchAll() as $p) {
            $pdo->prepare("INSERT INTO match_player_stats (match_id, team_name, ign) VALUES (?, ?, ?)")->execute([$mId, $t['team_name'], $p['ign']]);
        }
    }
}
header("Location: setup.php"); exit;
?>
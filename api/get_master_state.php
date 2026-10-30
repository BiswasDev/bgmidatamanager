<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *'); 
require '../db.php';

$master_state = [
    "live_match" => null,
    "post_match" => null,
    "overall_standings" => null
];

// --- 1. GET LIVE MATCH ---
$liveMatch = $pdo->query("SELECT m.*, ts.stage_name FROM matches m JOIN tournament_stages ts ON m.tournament_stage_id = ts.id WHERE m.is_active = TRUE LIMIT 1")->fetch();
if ($liveMatch) {
    $mId = $liveMatch['id'];
    // Correct: Only pulls teams for the currently active match
    $stmt = $pdo->prepare("SELECT team_name, logo_path, total_finishes FROM match_team_stats WHERE match_id = ?");
    $stmt->execute([$match_id]);
    $ranking = [];
    foreach ($teams->fetchAll() as $t) {
        $p = $pdo->prepare("SELECT ign, status, dead_timestamp, finishes FROM match_player_stats WHERE match_id = ? AND team_name = ?"); 
        $p->execute([$mId, $t['team_name']]);
        $ranking[] = ["team_name" => $t['team_name'], "logo" => $t['logo_path'], "finishes" => $t['total_finishes'], "status" => $t['is_eliminated'] ? "eliminated" : "alive", "players" => $p->fetchAll()];
    }
    $master_state["live_match"] = [
        "info" => ["match_id" => $mId, "map" => $liveMatch['map_name'], "circle" => $liveMatch['zone_circle'], "blue_zone" => (bool)$liveMatch['blue_zone_active']],
        "ranking" => $ranking
    ];
}

// --- 2. GET POST MATCH (Latest Finalized) ---
$latestFinished = $pdo->query("SELECT id FROM matches WHERE is_active = FALSE ORDER BY id DESC LIMIT 1")->fetchColumn();
if ($latestFinished) {
    // Get WWCD
    $wwcdQuery = $pdo->prepare("SELECT team_name, logo_path, total_finishes FROM match_team_stats WHERE match_id = ? AND elimination_rank = 1 LIMIT 1"); 
    $wwcdQuery->execute([$latestFinished]); 
    
    // Get Top Fraggers
    $fQuery = $pdo->prepare("SELECT mps.ign, mps.team_name, mps.finishes as kills FROM match_player_stats mps WHERE mps.match_id = ? ORDER BY mps.finishes DESC LIMIT 5"); 
    $fQuery->execute([$latestFinished]);

    $master_state["post_match"] = [
        "match_id" => $latestFinished,
        "wwcd" => $wwcdQuery->fetch(),
        "top_fraggers" => $fQuery->fetchAll()
    ];
}

// --- 3. GET OVERALL STANDINGS ---
$stageId = $pdo->query("SELECT tournament_stage_id FROM matches ORDER BY id DESC LIMIT 1")->fetchColumn();
if ($stageId) {
    $stmt = $pdo->prepare("SELECT mts.team_name, mts.logo_path, SUM(mts.total_finishes) as total_kills, SUM(mts.placement_points) as total_placement, (SUM(mts.total_finishes) + SUM(mts.placement_points)) as grand_total FROM match_team_stats mts JOIN matches m ON mts.match_id = m.id WHERE m.tournament_stage_id = ? AND m.is_active = FALSE GROUP BY mts.team_name, mts.logo_path ORDER BY grand_total DESC, total_placement DESC, total_kills DESC");
    $stmt->execute([$stageId]); 
    $master_state["overall_standings"] = $stmt->fetchAll();
}

// Output the unified universe
echo json_encode($master_state, JSON_PRETTY_PRINT);
?>
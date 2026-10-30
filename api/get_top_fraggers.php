<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *'); 
require '../db.php';

$match_id = $_GET['match_id'] ?? 0;

// If match_id is provided, get MVP for THAT match. 
// Otherwise, get Overall MVP for the whole tournament stage.
if ($match_id) {
    $sql = "
        SELECT mps.ign, mps.team_name, mps.finishes as kills, tp.player_image_path
        FROM match_player_stats mps
        LEFT JOIN tournament_players tp ON mps.ign = tp.ign
        WHERE mps.match_id = ?
        ORDER BY mps.finishes DESC
        LIMIT 5
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$match_id]);
} else {
    // Get latest stage
    $stage_id = $pdo->query("SELECT tournament_stage_id FROM matches ORDER BY id DESC LIMIT 1")->fetchColumn();
    $sql = "
        SELECT mps.ign, mps.team_name, SUM(mps.finishes) as kills, tp.player_image_path
        FROM match_player_stats mps
        JOIN matches m ON mps.match_id = m.id
        LEFT JOIN tournament_players tp ON mps.ign = tp.ign
        WHERE m.tournament_stage_id = ? AND m.is_active = FALSE
        GROUP BY mps.ign, mps.team_name, tp.player_image_path
        ORDER BY kills DESC
        LIMIT 10
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$stage_id]);
}

$fraggers = $stmt->fetchAll();

echo json_encode([
    "type" => $match_id ? "match_mvp" : "overall_fraggers",
    "players" => $fraggers
], JSON_PRETTY_PRINT);
?>
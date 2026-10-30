<?php
header('Content-Type: application/json'); header('Access-Control-Allow-Origin: *'); require '../db.php';
$sId = $_GET['stage_id'] ?? $pdo->query("SELECT tournament_stage_id FROM matches ORDER BY id DESC LIMIT 1")->fetchColumn();
if (!$sId) die(json_encode(["error"=>"No stage specified."]));

$stmt = $pdo->prepare("SELECT mts.team_name, mts.logo_path, SUM(mts.total_finishes) as total_kills, SUM(mts.placement_points) as total_placement, (SUM(mts.total_finishes) + SUM(mts.placement_points)) as grand_total, COUNT(mts.id) as matches_played FROM match_team_stats mts JOIN matches m ON mts.match_id=m.id WHERE m.tournament_stage_id=? AND m.is_active=FALSE GROUP BY mts.team_name, mts.logo_path ORDER BY grand_total DESC, total_placement DESC, total_kills DESC");
$stmt->execute([$sId]); $std = $stmt->fetchAll();
$rank = 1; foreach ($std as &$t) $t['rank'] = $rank++;

echo json_encode(["stage_id"=>$sId, "total_teams"=>count($std), "leaderboard"=>$std], JSON_PRETTY_PRINT);
?>
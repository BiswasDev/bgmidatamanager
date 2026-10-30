<?php
header('Content-Type: application/json'); header('Access-Control-Allow-Origin: *'); require '../db.php';
$mId = $_GET['match_id'] ?? $pdo->query("SELECT id FROM matches WHERE is_active=FALSE ORDER BY id DESC LIMIT 1")->fetchColumn();
if (!$mId) die(json_encode(["error"=>"No finalized match."]));

$res = [];
$stdQuery = $pdo->prepare("SELECT mts.team_name, mts.logo_path, mts.elimination_rank as rank, mts.total_finishes as kills, mts.placement_points as placement, (mts.total_finishes + mts.placement_points) as total_points, AVG(mps.survival_time) as avg_sec FROM match_team_stats mts JOIN match_player_stats mps ON mts.match_id=mps.match_id AND mts.team_name=mps.team_name WHERE mts.match_id=? GROUP BY mts.team_name, mts.logo_path, mts.elimination_rank, mts.total_finishes, mts.placement_points ORDER BY total_points DESC, rank ASC");
$stdQuery->execute([$mId]); $std = $stdQuery->fetchAll();
foreach ($std as &$t) { $s = round($t['avg_sec']); $t['avg_survival_formatted'] = sprintf('%02d:%02d', ($s/60), $s%60); }
$res['match_standings'] = $std;

$wwcdQuery = $pdo->prepare("SELECT * FROM match_team_stats WHERE match_id=? AND elimination_rank=1 LIMIT 1"); $wwcdQuery->execute([$mId]); $wwcd = $wwcdQuery->fetch();
if ($wwcd) {
    $pQuery = $pdo->prepare("SELECT mps.ign, mps.finishes as kills, tp.player_image_path, mps.survival_time FROM match_player_stats mps LEFT JOIN tournament_players tp ON mps.ign=tp.ign WHERE mps.match_id=? AND mps.team_name=?"); $pQuery->execute([$mId, $wwcd['team_name']]);
    $players = $pQuery->fetchAll(); foreach ($players as &$p) $p['survival_formatted'] = sprintf('%02d:%02d', ($p['survival_time']/60), $p['survival_time']%60);
    $res['wwcd_team'] = ["team_name"=>$wwcd['team_name'], "logo"=>$wwcd['logo_path'], "total_kills"=>$wwcd['total_finishes'], "roster"=>$players];
}

$fQuery = $pdo->prepare("SELECT mps.ign, mps.team_name, mps.finishes as kills, tp.player_image_path FROM match_player_stats mps LEFT JOIN tournament_players tp ON mps.ign=tp.ign WHERE mps.match_id=? ORDER BY mps.finishes DESC LIMIT 5"); $fQuery->execute([$mId]);
$res['top_fraggers'] = $fQuery->fetchAll();

echo json_encode($res, JSON_PRETTY_PRINT);
?>
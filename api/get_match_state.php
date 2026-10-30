<?php
header('Content-Type: application/json'); header('Access-Control-Allow-Origin: *'); require '../db.php';
$m = $pdo->query("SELECT m.*, ts.stage_name FROM matches m JOIN tournament_stages ts ON m.tournament_stage_id=ts.id WHERE m.is_active=TRUE LIMIT 1")->fetch();
if(!$m) { echo json_encode(["status" => "No active match"]); exit; }
$teams = $pdo->prepare("SELECT * FROM match_team_stats WHERE match_id=? ORDER BY total_finishes DESC, is_eliminated ASC"); $teams->execute([$m['id']]);
$ranking = [];
foreach ($teams->fetchAll() as $t) {
    $p = $pdo->prepare("SELECT ign, status, dead_timestamp, finishes FROM match_player_stats WHERE match_id=? AND team_name=?"); $p->execute([$m['id'], $t['team_name']]);
    $ranking[] = ["team_name"=>$t['team_name'], "logo"=>$t['logo_path'], "finishes"=>$t['total_finishes'], "status"=>$t['is_eliminated']?"eliminated":"alive", "players"=>$p->fetchAll()];
}
echo json_encode(["match_info"=>["match_id"=>$m['id'], "map"=>$m['map_name'], "stage"=>$m['zone_circle'], "blue_zone"=>(bool)$m['blue_zone_active'], "zone_closing_target"=>$m['zone_closing_target'], "recall_active"=>($m['map_name']==='Rondo'&&$m['zone_circle']>4)?false:(bool)$m['recall_active']], "alive_ranking"=>$ranking], JSON_PRETTY_PRINT);
?>
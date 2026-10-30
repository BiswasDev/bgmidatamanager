<?php
header('Content-Type: application/json');
require '../db.php';
$a = $_POST['action'] ?? ''; $mId = (int)($_POST['match_id'] ?? 0); $t = $_POST['team_name'] ?? ''; $ign = $_POST['ign'] ?? '';

$map_zone = ['Erangel'=>[1=>300,2=>200,3=>150,4=>120,5=>120,6=>90,7=>90,8=>60],'Sanhok'=>[1=>120,2=>120,3=>90,4=>45,5=>90,6=>45,7=>45,8=>30],'Rondo'=>[1=>300,2=>200,3=>150,4=>120,5=>120,6=>90,7=>90,8=>60],'Miramar'=>[1=>300,2=>200,3=>150,4=>120,5=>120,6=>90,7=>90,8=>60]];

try {
    switch ($a) {
        case 'start_match_clock':
            $pdo->prepare("UPDATE matches SET match_start_timestamp = NOW() WHERE id = ?")->execute([$mId]); echo json_encode(["status"=>"success"]); break;

        case 'add_player_finish':
            $pdo->prepare("UPDATE match_player_stats SET finishes = finishes + 1 WHERE match_id=? AND ign=?")->execute([$mId, $ign]);
            $pdo->prepare("UPDATE match_team_stats SET total_finishes = (SELECT COALESCE(SUM(finishes),0) FROM match_player_stats WHERE match_id=? AND team_name=?) WHERE match_id=? AND team_name=?")->execute([$mId, $t, $mId, $t]);
            echo json_encode(["status"=>"success"]); break;

        case 'sub_player_finish':
            $pdo->prepare("UPDATE match_player_stats SET finishes = GREATEST(0, finishes - 1) WHERE match_id=? AND ign=?")->execute([$mId, $ign]);
            $pdo->prepare("UPDATE match_team_stats SET total_finishes = (SELECT COALESCE(SUM(finishes),0) FROM match_player_stats WHERE match_id=? AND team_name=?) WHERE match_id=? AND team_name=?")->execute([$mId, $t, $mId, $t]);
            echo json_encode(["status"=>"success"]); break;

        case 'update_player_status':
            $s = $_POST['status'];
            if ($s === 'Dead') $pdo->prepare("UPDATE match_player_stats SET status=?, dead_timestamp=NOW(), survival_time = TIMESTAMPDIFF(SECOND, (SELECT match_start_timestamp FROM matches WHERE id=?), NOW()) WHERE match_id=? AND ign=?")->execute([$s, $mId, $mId, $ign]);
            else $pdo->prepare("UPDATE match_player_stats SET status=?, dead_timestamp=NULL, survival_time=0 WHERE match_id=? AND ign=?")->execute([$s, $mId, $ign]);
            echo json_encode(["status"=>"success"]); break;

        case 'eliminate_team':
            $rank = $pdo->prepare("SELECT COUNT(*) FROM match_team_stats WHERE match_id=? AND is_eliminated=FALSE"); $rank->execute([$mId]); $c = $rank->fetchColumn();
            $pdo->prepare("UPDATE match_team_stats SET is_eliminated=TRUE, elimination_rank=? WHERE match_id=? AND team_name=?")->execute([$c, $mId, $t]);
            $pdo->prepare("UPDATE match_player_stats SET status='Dead', dead_timestamp=NOW(), survival_time = TIMESTAMPDIFF(SECOND, (SELECT match_start_timestamp FROM matches WHERE id=?), NOW()) WHERE match_id=? AND team_name=? AND status!='Dead'")->execute([$mId, $mId, $t]);
            echo json_encode(["status"=>"success"]); break;

        case 'start_zone_shrink':
            $z = (int)$_POST['zone_circle']; $map = $_POST['map_name']; $dur = $map_zone[$map][$z] ?? 60;
            $pdo->prepare("UPDATE matches SET zone_circle=?, zone_closing_target=?, blue_zone_active=TRUE WHERE id=?")->execute([$z, date('Y-m-d H:i:s', time()+$dur), $mId]);
            echo json_encode(["status"=>"success"]); break;

        case 'stop_zone':
            $pdo->prepare("UPDATE matches SET blue_zone_active=FALSE, zone_closing_target=NULL WHERE id=?")->execute([$mId]); echo json_encode(["status"=>"success"]); break;

        case 'end_match':
            $pts = [1=>10,2=>6,3=>5,4=>4,5=>3,6=>2,7=>1,8=>1];
            $pdo->prepare("UPDATE match_team_stats SET elimination_rank=1 WHERE match_id=? AND is_eliminated=FALSE")->execute([$mId]);
            $pdo->prepare("UPDATE matches SET is_active=FALSE, blue_zone_active=FALSE, match_end_timestamp=NOW() WHERE id=?")->execute([$mId]);
            
            $teams = $pdo->prepare("SELECT id, elimination_rank FROM match_team_stats WHERE match_id=?"); $teams->execute([$mId]);
            $uPts = $pdo->prepare("UPDATE match_team_stats SET placement_points=? WHERE id=?");
            foreach($teams->fetchAll() as $team) $uPts->execute([$pts[$team['elimination_rank']] ?? 0, $team['id']]);
            
            $pdo->prepare("UPDATE match_player_stats SET survival_time = TIMESTAMPDIFF(SECOND, (SELECT match_start_timestamp FROM matches WHERE id=?), (SELECT match_end_timestamp FROM matches WHERE id=?)) WHERE match_id=? AND status='Alive'")->execute([$mId, $mId, $mId]);
            echo json_encode(["status"=>"success"]); break;
    }
} catch (\PDOException $e) { echo json_encode(["error"=>$e->getMessage()]); }
?>
<?php
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json; charset=utf-8');

register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== NULL) {
        echo json_encode(['success' => false, 'error' => 'FATAL: ' . $error['message']]);
        exit;
    }
});

require 'db.php';

$action = $_POST['action'] ?? null;
$match_id = $_POST['match_id'] ?? null;

if (!$action || !$match_id) {
    echo json_encode(['success' => false, 'error' => 'Missing Action or Match ID']);
    exit;
}

try {
    // 1. ENVIRONMENT UPDATES
    if ($action === 'update_match_info') {
        $circle = (int)$_POST['circle'];
        $recall = (int)$_POST['recall'];
        $pdo->prepare("UPDATE matches SET zone_circle = ?, blue_zone_active = ? WHERE id = ?")->execute([$circle, $recall, $match_id]);
        echo json_encode(['success' => true]);
        exit;
    }

    // 2. START MATCH (Resets the WWCD trigger)
    if ($action === 'start_match') {
        $pdo->prepare("UPDATE matches SET status = 'active', start_time = NOW(), wwcd_triggered = 0 WHERE id = ?")->execute([$match_id]);
        echo json_encode(['success' => true, 'msg' => 'MATCH STARTED']);
        exit;
    }

    // 3. KILLS
    if ($action === 'update_kill') {
        $ign = $_POST['ign'];
        $team = $_POST['team_name'];
        $change = (int)$_POST['change'];

        $pdo->prepare("UPDATE match_player_stats SET finishes = GREATEST(0, finishes + ?) WHERE match_id = ? AND ign = ?")->execute([$change, $match_id, $ign]);
        $pdo->prepare("UPDATE match_team_stats SET total_finishes = (SELECT COALESCE(SUM(finishes), 0) FROM match_player_stats WHERE match_id = ? AND team_name = ?) WHERE match_id = ? AND team_name = ?")->execute([$match_id, $team, $match_id, $team]);
        $pdo->prepare("UPDATE match_team_stats SET total_points = (total_finishes + placement_points) - penalty_points WHERE match_id = ? AND team_name = ?")->execute([$match_id, $team]);

        echo json_encode(['success' => true]);
        exit;
    }

    // 4. WIPE SQUAD
    if ($action === 'wipe_squad') {
        $team = $_POST['team_name'];
        
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM match_team_stats WHERE match_id = ? AND is_eliminated = 0");
        $stmt->execute([$match_id]);
        $alive = (int)$stmt->fetchColumn();
        
        $pts = [1=>10, 2=>6, 3=>5, 4=>4, 5=>3, 6=>2, 7=>1, 8=>1][$alive] ?? 0;

        $pdo->prepare("UPDATE match_team_stats SET is_eliminated = 1, elimination_rank = ?, placement_points = ? WHERE match_id = ? AND team_name = ?")
            ->execute([$alive, $pts, $match_id, $team]);
        
        // --- ADDED SURVIVAL TIME LOGIC ---
        $mStmt = $pdo->prepare("SELECT start_time FROM matches WHERE id = ?");
        $mStmt->execute([$match_id]);
        $m_data = $mStmt->fetch();
        $survival_time = !empty($m_data['start_time']) ? (time() - strtotime($m_data['start_time'])) : 0;

        $pdo->prepare("UPDATE match_player_stats SET status = 'Dead', dead_timestamp = CURRENT_TIMESTAMP, survival_time = ? WHERE match_id = ? AND team_name = ? AND status != 'Dead'")
            ->execute([$survival_time, $match_id, $team]);
        // ---------------------------------
        
        $pdo->prepare("UPDATE match_team_stats SET total_points = (total_finishes + placement_points) - penalty_points WHERE match_id = ? AND team_name = ?")
            ->execute([$match_id, $team]);

        // If 1 team is left, trigger the WWCD graphic, but DO NOT close the match
        if ($alive <= 2) {
            $pdo->prepare("UPDATE matches SET wwcd_triggered = 1 WHERE id = ?")->execute([$match_id]);
        }
        
        echo json_encode(['success' => true]);
        exit;
    }

    // 5. STATUS & RECALL
    if ($action === 'update_status') {
        $ign = $_POST['ign'];
        $status = $_POST['status'];
        $team = $_POST['team_name'];
        
        $stmt = $pdo->prepare("SELECT map_name, zone_circle, start_time FROM matches WHERE id = ?");
        $stmt->execute([$match_id]);
        $m = $stmt->fetch();
        
        $pStmt = $pdo->prepare("SELECT status FROM match_player_stats WHERE match_id = ? AND ign = ?");
        $pStmt->execute([$match_id, $ign]);
        $old_status = $pStmt->fetchColumn();

        if ($m['map_name'] === 'Rondo' && (int)$m['zone_circle'] < 4 && $old_status === 'Dead' && $status === 'Alive') {
            $pdo->prepare("UPDATE match_team_stats SET recalls = recalls + 1 WHERE match_id = ? AND team_name = ?")->execute([$match_id, $team]);
        }
        
        // --- ADDED SURVIVAL TIME LOGIC ---
        if ($status === 'Dead' && $old_status !== 'Dead') {
            $survival_time = !empty($m['start_time']) ? (time() - strtotime($m['start_time'])) : 0;
            $pdo->prepare("UPDATE match_player_stats SET status = ?, dead_timestamp = CURRENT_TIMESTAMP, survival_time = ? WHERE match_id = ? AND ign = ?")
                ->execute([$status, $survival_time, $match_id, $ign]);
        } else {
            $pdo->prepare("UPDATE match_player_stats SET status = ? WHERE match_id = ? AND ign = ?")->execute([$status, $match_id, $ign]);
        }
        // ---------------------------------
        
        echo json_encode(['success' => true]);
        exit;
    }

    // 6. MANUAL WWCD (Does not close match)
    if ($action === 'trigger_wwcd') {
        $pdo->prepare("UPDATE matches SET wwcd_triggered = 1 WHERE id = ?")->execute([$match_id]);
        echo json_encode(['success' => true, 'msg' => 'WWCD GRAPHIC TRIGGERED']);
        exit;
    }

    // 7. TRIGGER RECALL
    if ($action === 'trigger_recall') {
        $ign = $_POST['ign'];
        $team_name = $_POST['team_name'];
        
        // Mark player as recalled and set them to Alive
        $stmt = $pdo->prepare("UPDATE match_player_stats SET status = 'Alive', is_recalled = 1 WHERE ign = ? AND match_id = ?");
        $stmt->execute([$ign, $match_id]);
        
        // Add 1 to team's total_recalls and ensure the team is marked as NOT wiped
        $stmt2 = $pdo->prepare("UPDATE match_team_stats SET total_recalls = total_recalls + 1, is_eliminated = 0 WHERE team_name = ? AND match_id = ?");
        $stmt2->execute([$team_name, $match_id]);
        
        echo json_encode(['success' => true]);
        exit;
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
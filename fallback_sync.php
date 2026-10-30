<?php
require 'db.php';
header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true);

$match_id = $data['match_id'] ?? null;
$teams_data = $data['teams'] ?? []; 

if (!$match_id || empty($teams_data)) {
    echo json_encode(["success" => false, "error" => "Invalid Payload"]);
    exit;
}

try {
    $pdo->beginTransaction();

    // 1. Fetch match & group info
    $m_stmt = $pdo->prepare("SELECT group_name FROM matches WHERE id = ?");
    $m_stmt->execute([$match_id]);
    $group_name = $m_stmt->fetchColumn();

    // 2. Map Slot Numbers -> Real Team Names from tournament_groups (Starts at Slot 3)
    $slot_to_team_map = [];
    if ($group_name) {
        $g_stmt = $pdo->prepare("SELECT teams_list FROM tournament_groups WHERE LOWER(group_name) = LOWER(?) LIMIT 1");
        $g_stmt->execute([$group_name]);
        $teams_list = $g_stmt->fetchColumn();

        if ($teams_list) {
            $slot_index = 3; // Starts at 3 because slots 1 and 2 are locked
            foreach (explode("\n", str_replace("\r", "", $teams_list)) as $line) {
                $parts = explode('|', trim($line));
                $t_name = trim($parts[0] ?? '');
                if (!empty($t_name)) {
                    $slot_to_team_map[$slot_index] = $t_name;
                    $slot_to_team_map[(string)$slot_index] = $t_name;
                    $slot_index++;
                }
            }
        }
    }

    // 3. Fetch all teams registered in this match for direct name mapping fallback
    $registered_teams_stmt = $pdo->prepare("SELECT team_name FROM match_team_stats WHERE match_id = ?");
    $registered_teams_stmt->execute([$match_id]);
    $registered_teams = $registered_teams_stmt->fetchAll(PDO::FETCH_COLUMN);
    $registered_teams_lower = array_map('strtolower', $registered_teams);

    // Standard PUBG Mobile / BGMI Point System
    $placement_points_map = [1=>10, 2=>6, 3=>5, 4=>4, 5=>3, 6=>2, 7=>1, 8=>1];

    foreach ($teams_data as $team) {
        $raw_identifier = trim((string)($team['slot'] ?? $team['slot_no'] ?? $team['team_name'] ?? ''));
        $real_team_name = "";

        // Extract numeric slot index if string like "Slot 5", "Team 05", or "5"
        preg_match('/\d+/', $raw_identifier, $matches);
        $extracted_slot = isset($matches[0]) ? (int)$matches[0] : null;

        // A. Try mapping by Slot Number first
        if ($extracted_slot && isset($slot_to_team_map[$extracted_slot])) {
            $real_team_name = $slot_to_team_map[$extracted_slot];
        } 
        // B. Fallback: Check if it's already a direct Team Name in the database
        elseif (in_array(strtolower($raw_identifier), $registered_teams_lower)) {
            $match_idx = array_search(strtolower($raw_identifier), $registered_teams_lower);
            $real_team_name = $registered_teams[$match_idx];
        }

        if (empty($real_team_name)) {
            throw new Exception("Could not map identifier '$raw_identifier' (Slot/Team) to any team in Match ID $match_id.");
        }

        $rank = (int)$team['rank'];
        $placement_pts = $placement_points_map[$rank] ?? 0;
        $team_kills = 0;
        
        // 4. Update Players (Matches player IGN under the resolved team)
        if (isset($team['players']) && is_array($team['players'])) {
            foreach ($team['players'] as $player) {
                $ign = trim($player['ign']);
                $kills = (int)$player['kills'];
                $team_kills += $kills;

                // Update existing player record or insert if missing
                $p_stmt = $pdo->prepare("
                    UPDATE match_player_stats 
                    SET finishes = ?, status = 'Dead' 
                    WHERE match_id = ? AND LOWER(team_name) = LOWER(?) AND LOWER(ign) = LOWER(?)
                ");
                $p_stmt->execute([$kills, $match_id, $real_team_name, $ign]);

                // If player row was not updated (e.g. sub player), insert them
                if ($p_stmt->rowCount() == 0) {
                    $p_ins = $pdo->prepare("
                        INSERT INTO match_player_stats (match_id, team_name, ign, finishes, status) 
                        VALUES (?, ?, ?, ?, 'Dead')
                        ON DUPLICATE KEY UPDATE finishes = ?, status = 'Dead'
                    ");
                    $p_ins->execute([$match_id, $real_team_name, $ign, $kills, $kills]);
                }
            }
        }

        $total_pts = $team_kills + $placement_pts;

        // 5. Update Team Stats directly on the resolved team
        $t_stmt = $pdo->prepare("
            UPDATE match_team_stats 
            SET total_finishes = ?, total_kills = ?, placement_points = ?, total_points = ?, elimination_rank = ?, is_eliminated = 1
            WHERE match_id = ? AND LOWER(team_name) = LOWER(?)
        ");
        $t_stmt->execute([
            $team_kills, $team_kills, $placement_pts, $total_pts, $rank,
            $match_id, $real_team_name
        ]);
    }

    // 6. Close the match and set WWCD
    $pdo->prepare("UPDATE matches SET status = 'finished', wwcd_triggered = 1 WHERE id = ?")->execute([$match_id]);

    $pdo->commit();
    echo json_encode([
        "success" => true, 
        "msg" => "Match results synced successfully! All slot numbers mapped to real team names."
    ]);

} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}
?>
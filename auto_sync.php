<?php
require 'db.php';

$message = "";

// Auto-fetch the most recent match ID
$active_match = $pdo->query("SELECT id, group_name FROM matches WHERE status IN ('idle', 'active', 'scheduled') ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$default_match_id = $active_match['id'] ?? '';
$default_group = $active_match['group_name'] ?? 'UNKNOWN';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'sync') {
    $match_id = (int)$_POST['match_id'];

    try {
        $pdo->beginTransaction();
        
        // 1. Find which group this match is assigned to
        $m_stmt = $pdo->prepare("SELECT group_name FROM matches WHERE id = ?");
        $m_stmt->execute([$match_id]);
        $group_name = $m_stmt->fetchColumn();

        if (!$group_name) {
            throw new Exception("Match ID $match_id does not have a group assigned.");
        }

        // 2. Fetch the LATEST team list from that group
        $g_stmt = $pdo->prepare("SELECT teams_list FROM tournament_groups WHERE group_name = ?");
        $g_stmt->execute([$group_name]);
        $teams_list = $g_stmt->fetchColumn();

        if (empty($teams_list)) {
            throw new Exception("Group '$group_name' is empty in the database.");
        }

        // 3. Get the teams ALREADY in the match so we don't duplicate them
        $existing_stmt = $pdo->prepare("SELECT LOWER(team_name) FROM match_team_stats WHERE match_id = ?");
        $existing_stmt->execute([$match_id]);
        $existing_teams = $existing_stmt->fetchAll(PDO::FETCH_COLUMN);

        $teams_added = 0;
        $players_added = 0;

        // 4. Parse the latest group list and inject missing teams
        $lines = explode("\n", str_replace("\r", "", $teams_list));
        foreach ($lines as $line) {
            if (empty(trim($line))) continue;

            // Assuming format: TeamName | Player1, Player2, Player3
            $parts = explode('|', trim($line));
            $team_name = trim($parts[0]);
            
            if (empty($team_name)) continue;

            // If the team is NOT in the match yet, inject them!
            if (!in_array(strtolower($team_name), $existing_teams)) {
                
                // Inject Team
                $t_ins = $pdo->prepare("
                    INSERT INTO match_team_stats (match_id, team_name, total_finishes, total_kills, placement_points, total_points, is_eliminated) 
                    VALUES (?, ?, 0, 0, 0, 0, 0)
                ");
                $t_ins->execute([$match_id, $team_name]);
                $teams_added++;

                // Inject Players (if they are listed after the | symbol)
                if (isset($parts[1]) && !empty(trim($parts[1]))) {
                    $players = explode(',', $parts[1]);
                    $p_ins = $pdo->prepare("
                        INSERT INTO match_player_stats (match_id, team_name, ign, finishes, status) 
                        VALUES (?, ?, ?, 0, 'Alive')
                    ");

                    foreach ($players as $p) {
                        $ign = trim($p);
                        if (!empty($ign)) {
                            $p_ins->execute([$match_id, $team_name, $ign]);
                            $players_added++;
                        }
                    }
                }
            }
        }

        $pdo->commit();
        $message = "<div class='success'>✅ AUTO-SYNC COMPLETE!<br><br>Group <b>$group_name</b> was re-scanned.<br>Injected <b>$teams_added</b> missing teams and <b>$players_added</b> missing players.</div>";

    } catch (Exception $e) {
        $pdo->rollBack();
        $message = "<div class='error'>❌ ERROR: " . $e->getMessage() . "</div>";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>NexusForge | Auto-Sync Match Group</title>
    <style>
        body { background: #0b0b0f; color: #fff; font-family: monospace; padding: 20px; text-transform: uppercase; }
        .container { max-width: 600px; margin: 0 auto; background: #1a1a24; padding: 25px; border: 2px solid #3b82f6; }
        h2 { color: #3b82f6; margin-top: 0; font-size: 1.8rem;}
        label { display: block; margin-top: 15px; color: #aaa; font-size: 0.9rem;}
        input { 
            width: 100%; box-sizing: border-box; background: #000; color: #3b82f6; 
            border: 1px solid #444; padding: 12px; font-family: monospace; margin-top: 5px; font-size: 1.2rem; font-weight: bold;
        }
        button { 
            margin-top: 25px; padding: 15px 20px; background: #3b82f6; color: #fff; 
            border: none; font-weight: bold; cursor: pointer; width: 100%; font-family: monospace; font-size: 1.2rem;
        }
        button:hover { background: #2563eb; }
        .success { color: #10b981; margin-bottom: 20px; border: 1px solid #10b981; padding: 15px; background: rgba(16, 185, 129, 0.1); line-height: 1.5; }
        .error { color: #ef4444; margin-bottom: 20px; border: 1px solid #ef4444; padding: 15px; background: rgba(239, 68, 68, 0.1); line-height: 1.5; }
        .info-box { background: #000; padding: 15px; border: 1px dashed #555; margin-bottom: 20px; color: #ccc; text-transform: none; }
    </style>
</head>
<body>

<div class="container">
    <h2>🔄 AUTO-SYNC MATCH GROUP</h2>
    
    <div class="info-box">
        If you modified the Teams/Rosters for <strong><?= htmlspecialchars($default_group) ?></strong> <i>after</i> creating Match <?= $default_match_id ?>, click the button below. The system will scan the group list and safely inject any missing teams without resetting your current match progress.
    </div>
    
    <?= $message ?>

    <form method="POST" action="">
        <input type="hidden" name="action" value="sync">
        <label>Target Match ID:</label>
        <input type="number" name="match_id" value="<?= htmlspecialchars($default_match_id) ?>" required>

        <button type="submit" onclick="return confirm('Scan group and inject missing teams into this match?');">
            ⚡ SCAN & INJECT MISSING TEAMS
        </button>
    </form>
</div>

</body>
</html>
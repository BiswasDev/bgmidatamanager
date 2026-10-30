<?php
require 'db.php';

$message = "";

// Get the most recent active/idle match to auto-fill the Match ID
$active_match = $pdo->query("SELECT id, map_name FROM matches WHERE status IN ('idle', 'active', 'scheduled') ORDER BY id DESC LIMIT 1")->fetch();
$default_match_id = $active_match ? $active_match['id'] : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $match_id = (int)$_POST['match_id'];
    $team_name = trim($_POST['team_name']);
    $players = [
        trim($_POST['p1']), trim($_POST['p2']), trim($_POST['p3']), 
        trim($_POST['p4']), trim($_POST['p5']), trim($_POST['p6'])
    ];

    if ($match_id && $team_name) {
        try {
            $pdo->beginTransaction();
            
            // 1. Inject the Team into the match (Starting with 0 points, Alive)
            $stmt = $pdo->prepare("
                INSERT IGNORE INTO match_team_stats 
                (match_id, team_name, total_finishes, total_kills, placement_points, total_points, is_eliminated) 
                VALUES (?, ?, 0, 0, 0, 0, 0)
            ");
            $stmt->execute([$match_id, $team_name]);

            // 2. Inject the Players into the match (Starting with 0 finishes, Alive)
            $p_stmt = $pdo->prepare("
                INSERT IGNORE INTO match_player_stats 
                (match_id, team_name, ign, finishes, status) 
                VALUES (?, ?, ?, 0, 'Alive')
            ");
            
            foreach ($players as $p) {
                if (!empty($p)) {
                    $p_stmt->execute([$match_id, $team_name, $p]);
                }
            }
            
            $pdo->commit();
            $message = "<div class='success'>✅ SUCCESS! Team '<b>$team_name</b>' has been injected into Match $match_id. You can now refresh your Live Dashboard!</div>";
        } catch (Exception $e) {
            $pdo->rollBack();
            $message = "<div class='error'>❌ ERROR: " . $e->getMessage() . "</div>";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>NexusForge | Hot-Inject Team</title>
    <style>
        body { background: #0b0b0f; color: #fff; font-family: monospace; padding: 20px; text-transform: uppercase; }
        .container { max-width: 600px; margin: 0 auto; background: #1a1a24; padding: 20px; border: 2px solid #10b981; }
        h2 { color: #10b981; margin-top: 0; }
        label { display: block; margin-top: 15px; color: #aaa; font-size: 0.9rem;}
        input { 
            width: 100%; box-sizing: border-box; background: #000; color: #fff; 
            border: 1px solid #444; padding: 10px; font-family: monospace; margin-top: 5px; font-size: 1.1rem;
        }
        button { 
            margin-top: 20px; padding: 12px 20px; background: #3b82f6; color: #fff; 
            border: none; font-weight: bold; cursor: pointer; width: 100%; font-family: monospace; font-size: 1.1rem;
        }
        button:hover { background: #2563eb; }
        .player-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
        .success { color: #10b981; margin-bottom: 15px; border: 1px solid #10b981; padding: 10px; background: rgba(16, 185, 129, 0.1); }
        .error { color: #ef4444; margin-bottom: 15px; border: 1px solid #ef4444; padding: 10px; background: rgba(239, 68, 68, 0.1); }
    </style>
</head>
<body>

<div class="container">
    <h2>💉 HOT-INJECT MISSING TEAM</h2>
    <p style="color:#aaa; font-size:0.85rem; text-transform: none;">
        Forgot to add a team before creating the match? Enter their details here to instantly inject them into the live database so they appear on your dashboard.
    </p>
    
    <?= $message ?>

    <form method="POST" action="">
        <label>Target Match ID:</label>
        <input type="number" name="match_id" value="<?= htmlspecialchars($default_match_id) ?>" required>

        <label>Team Name (Exact Spelling):</label>
        <input type="text" name="team_name" placeholder="e.g. Team Nexus" required>

        <label style="margin-top: 25px; border-bottom: 1px solid #333; padding-bottom: 5px;">Player Roster (IGNs)</label>
        <div class="player-grid">
            <input type="text" name="p1" placeholder="Player 1 IGN" required>
            <input type="text" name="p2" placeholder="Player 2 IGN">
            <input type="text" name="p3" placeholder="Player 3 IGN">
            <input type="text" name="p4" placeholder="Player 4 IGN">
            <input type="text" name="p5" placeholder="Player 5 IGN (Sub)">
            <input type="text" name="p6" placeholder="Player 6 IGN (Sub)">
        </div>

        <button type="submit">INJECT TEAM INTO MATCH</button>
    </form>
</div>

</body>
</html>
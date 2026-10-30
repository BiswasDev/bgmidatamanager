<?php
require 'db.php';

$message = "";

// Handle Deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    $match_id = (int)$_POST['match_id'];
    $team_name = $_POST['team_name'];

    try {
        $pdo->beginTransaction();
        
        // 1. Delete players associated with this junk team in this match
        $p_stmt = $pdo->prepare("DELETE FROM match_player_stats WHERE match_id = ? AND team_name = ?");
        $p_stmt->execute([$match_id, $team_name]);
        $players_deleted = $p_stmt->rowCount();

        // 2. Delete the team itself from this match
        $t_stmt = $pdo->prepare("DELETE FROM match_team_stats WHERE match_id = ? AND team_name = ?");
        $t_stmt->execute([$match_id, $team_name]);
        
        $pdo->commit();
        $message = "<div class='success'>✅ Successfully deleted '<b>$team_name</b>' (and $players_deleted players) from Match $match_id.</div>";
    } catch (Exception $e) {
        $pdo->rollBack();
        $message = "<div class='error'>❌ Error deleting team: " . $e->getMessage() . "</div>";
    }
}

// Fetch teams if a Match ID is provided
$match_id = isset($_GET['match_id']) ? (int)$_GET['match_id'] : '';
$teams = [];
if ($match_id) {
    $stmt = $pdo->prepare("SELECT team_name, total_points, is_eliminated FROM match_team_stats WHERE match_id = ? ORDER BY team_name ASC");
    $stmt->execute([$match_id]);
    $teams = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>NexusForge | Match Data Cleanup</title>
    <style>
        body { background: #0b0b0f; color: #fff; font-family: monospace; padding: 20px; }
        .container { max-width: 600px; margin: 0 auto; background: #1a1a24; padding: 20px; border: 2px solid #ef4444; }
        h2 { color: #ef4444; margin-top: 0; text-transform: uppercase; }
        label { display: block; margin-top: 15px; color: #aaa; }
        input[type="number"] { 
            width: 100%; box-sizing: border-box; background: #000; color: #10b981; 
            border: 1px solid #444; padding: 10px; font-family: monospace; margin-top: 5px; font-size: 1.1rem;
        }
        button.search-btn { 
            margin-top: 15px; padding: 10px 20px; background: #3b82f6; color: #fff; 
            border: none; font-weight: bold; cursor: pointer; width: 100%; font-family: monospace; font-size: 1rem;
        }
        button.search-btn:hover { background: #2563eb; }
        .team-list { margin-top: 20px; border-top: 1px solid #444; padding-top: 20px; }
        .team-item { 
            display: flex; justify-content: space-between; align-items: center; 
            background: #000; padding: 10px; border: 1px solid #333; margin-bottom: 10px;
        }
        .del-btn { 
            background: #ef4444; color: white; border: none; padding: 5px 15px; 
            cursor: pointer; font-weight: bold; font-family: monospace; 
        }
        .del-btn:hover { background: #dc2626; }
        .success { color: #10b981; margin-bottom: 15px; border: 1px solid #10b981; padding: 10px; }
        .error { color: #ef4444; margin-bottom: 15px; border: 1px solid #ef4444; padding: 10px; }
    </style>
</head>
<body>

<div class="container">
    <h2>🗑️ Match Data Cleanup</h2>
    <p style="color:#aaa; font-size:0.9rem;">Enter a Match ID to view all registered teams. Delete junk entries like "Team 05".</p>
    
    <?= $message ?>

    <form method="GET" action="">
        <label>Match ID:</label>
        <input type="number" name="match_id" value="<?= htmlspecialchars($match_id) ?>" required>
        <button type="submit" class="search-btn">LOAD TEAMS</button>
    </form>

    <?php if ($match_id && empty($teams)): ?>
        <p style="color: #ef4444; margin-top: 20px;">No teams found for Match ID <?= $match_id ?>.</p>
    <?php elseif ($match_id && !empty($teams)): ?>
        <div class="team-list">
            <h3 style="color: #fff;">Teams in Match <?= $match_id ?>:</h3>
            <?php foreach ($teams as $t): ?>
                <div class="team-item">
                    <div>
                        <strong style="color: #3b82f6; font-size: 1.1rem;"><?= htmlspecialchars($t['team_name']) ?></strong><br>
                        <span style="color: #aaa; font-size: 0.8rem;">Points: <?= $t['total_points'] ?> | Eliminated: <?= $t['is_eliminated'] ? 'Yes' : 'No' ?></span>
                    </div>
                    <form method="POST" action="" onsubmit="return confirm('Are you sure you want to completely delete <?= htmlspecialchars($t['team_name']) ?> from this match? This cannot be undone.');">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="match_id" value="<?= $match_id ?>">
                        <input type="hidden" name="team_name" value="<?= htmlspecialchars($t['team_name']) ?>">
                        <button type="submit" class="del-btn">DELETE</button>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

</body>
</html>
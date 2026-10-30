<?php
require 'db.php';

// 1. DETERMINE MATCH TO LOAD (Live vs History Edit)
if (isset($_GET['match_id'])) {
    $match_id = (int)$_GET['match_id'];
    $stmt = $pdo->prepare("SELECT * FROM matches WHERE id = ?");
    $stmt->execute([$match_id]);
    $active_match = $stmt->fetch();
} else {
    $active_match = $pdo->query("SELECT * FROM matches WHERE is_active = 1 LIMIT 1")->fetch();
}

if (!$active_match) {
    die("<h2 style='color:white; font-family:monospace; padding: 40px;'>NO MATCH FOUND. GO TO SETUP.</h2>");
}
$match_id = $active_match['id'];

// 2. HANDLE SAVE & CLOSE
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_results'])) {
    $update_stmt = $pdo->prepare("UPDATE match_team_stats SET elimination_rank = ?, total_kills = ?, placement_points = ?, total_points = ? WHERE id = ? AND match_id = ?");
    
    // STRICT SERVER-SIDE POINT SYSTEM (Prevents any manual entry errors)
    $pointSystem = [1 => 10, 2 => 6, 3 => 5, 4 => 4, 5 => 3, 6 => 2, 7 => 1, 8 => 1];
    
    foreach ($_POST['teams'] as $team_id => $data) {
        $rank = (int)($data['rank'] ?? 0);
        $kills = (int)($data['kills'] ?? 0);
        
        // Force placement points based entirely on the rank provided
        $placement_points = $pointSystem[$rank] ?? 0; 
        
        $total_points = $kills + $placement_points;

        $update_stmt->execute([$rank, $kills, $placement_points, $total_points, $team_id, $match_id]);
    }

    // Lock match status
    $pdo->prepare("UPDATE matches SET status = 'finished', is_active = 0 WHERE id = ?")->execute([$match_id]);
    header("Location: setup.php");
    exit;
}

// 3. FETCH TEAM DATA (Prioritize saved kills, fallback to live player kills if 0)
$stmt = $pdo->prepare("
    SELECT 
        t.id, 
        t.team_name, 
        t.elimination_rank,
        t.placement_points,
        t.total_kills,
        COALESCE((SELECT SUM(finishes) FROM match_player_stats WHERE team_name = t.team_name AND match_id = t.match_id), 0) as live_player_kills
    FROM match_team_stats t 
    WHERE t.match_id = ? 
    ORDER BY t.id ASC
");
$stmt->execute([$match_id]);
$teams = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <title>NexusForge | Post Match Results</title>
    <style>
        body { background: #0b0b0f; color: #fff; font-family: monospace; padding: 40px; text-transform: uppercase; }
        .container { max-width: 900px; margin: 0 auto; }
        .header { background: #1a1a24; padding: 20px; border: 2px solid #ef4444; margin-bottom: 20px; text-align: center; }
        h1 { margin: 0; color: #ef4444; font-weight: 900; letter-spacing: 2px; }
        table { width: 100%; border-collapse: collapse; background: #111; margin-bottom: 30px; border: 1px solid #333; }
        th { background: #222; padding: 15px; text-align: left; color: #8b5cf6; }
        td { padding: 10px 15px; border-bottom: 1px solid #333; }
        
        input[type="number"] { width: 100%; box-sizing: border-box; background: #000; color: #fff; border: 1px solid #444; padding: 10px; font-family: monospace; font-size: 16px; }
        input[type="number"]:focus { border-color: #8b5cf6; outline: none; }
        
        .rescued-kills { color: #10b981; font-weight: bold; border-color: #10b981; background: rgba(16, 185, 129, 0.1) !important; }
        
        /* Stylizes the locked placement points box */
        .auto-points { color: #f59e0b; font-weight: bold; border-color: #f59e0b; background: rgba(245, 158, 11, 0.1) !important; }
        .locked-input { cursor: not-allowed; opacity: 0.6; pointer-events: none; }
        
        .btn-submit { background: #ef4444; color: #fff; border: none; padding: 20px; font-size: 20px; font-weight: 900; cursor: pointer; width: 100%; font-family: monospace; transition: 0.2s; }
        .btn-submit:hover { background: #dc2626; }
    </style>
</head>
<body>

<div class="container">
    <div class="header">
        <h1>POST MATCH RESULTS</h1>
        <div style="color: #aaa; margin-top: 10px;">MATCH ID: <?= $match_id ?> | MAP: <?= htmlspecialchars($active_match['map_name']) ?> | <?= $active_match['status'] === 'finished' ? '<span style="color:#10b981;">(EDITING HISTORY)</span>' : '<span style="color:#ef4444;">(LIVE MATCH)</span>' ?></div>
    </div>

    <form method="POST" action="" onsubmit="return confirm('Finalize and lock this match data?');">
        <table>
            <thead>
                <tr>
                    <th>TEAM NAME</th>
                    <th style="width: 15%;">RANK (#)</th>
                    <th style="width: 15%;">KILLS</th>
                    <th style="width: 20%;">PLACEMENT PTS</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($teams as $t): 
                    $display_kills = max((int)$t['total_kills'], (int)$t['live_player_kills']);
                ?>
                <tr>
                    <td style="font-size: 18px; font-weight: bold;"><?= htmlspecialchars($t['team_name']) ?></td>
                    <td>
                        <input type="number" name="teams[<?= $t['id'] ?>][rank]" class="rank-input" data-teamid="<?= $t['id'] ?>" value="<?= $t['elimination_rank'] > 0 ? $t['elimination_rank'] : '' ?>" placeholder="-">
                    </td>
                    <td>
                        <input type="number" name="teams[<?= $t['id'] ?>][kills]" class="rescued-kills" value="<?= $display_kills ?>">
                    </td>
                    <td>
                        <!-- Locked, Read-Only Placeholder -->
                        <input type="number" id="pts-<?= $t['id'] ?>" class="auto-points locked-input" value="<?= $t['placement_points'] > 0 ? $t['placement_points'] : '' ?>" placeholder="AUTO" readonly tabindex="-1">
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <button type="submit" name="submit_results" class="btn-submit">🔒 SAVE DATA & CLOSE MATCH</button>
    </form>
</div>

<script>
    const pointSystem = { 1: 10, 2: 6, 3: 5, 4: 4, 5: 3, 6: 2, 7: 1, 8: 1 };
    
    document.querySelectorAll('.rank-input').forEach(input => {
        
        // Function to calculate and update the box
        const syncPoints = (element) => {
            const rank = parseInt(element.value);
            const teamId = element.getAttribute('data-teamid');
            const pointsDisplay = document.getElementById('pts-' + teamId);
            
            if (!isNaN(rank) && rank > 0) {
                pointsDisplay.value = pointSystem[rank] || 0;
            } else {
                pointsDisplay.value = '';
            }
        };

        // 1. Instantly trigger on page load so existing ranks show their points
        if(input.value !== '') {
            syncPoints(input);
        }
        
        // 2. Trigger whenever you type a new rank
        input.addEventListener('input', function() {
            syncPoints(this);
        });
    });
</script>
</body>
</html>
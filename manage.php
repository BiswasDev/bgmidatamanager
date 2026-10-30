<?php require 'db.php'; $tid = $_GET['tournament_id'] ?? ''; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin Override</title>
    <style>
        body { background: #0b0b0f; color: #fff; font-family: monospace; padding: 30px; margin: 0; }
        .nav { margin-bottom: 20px; } .nav a { color: #8b5cf6; text-decoration: none; font-weight: bold; margin-right: 15px; border: 1px solid #8b5cf6; padding: 8px 15px; }
        select, input { padding: 8px; background: #13131a; border: 1px solid #333; color: #fff; }
        button { background: #f59e0b; color: #000; border: none; padding: 8px 12px; cursor: pointer; font-weight: bold; }
        .btn-danger { background: #ef4444; color: #fff; }
        .team-block { background: #13131a; border-left: 4px solid #8b5cf6; padding: 15px; margin-bottom: 20px; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; } th, td { padding: 8px; border-bottom: 1px solid #262633; text-align: left; }
    </style>
</head>
<body>
    <div class="nav"><a href="setup.php">← Setup</a><a href="dashboard.php" style="color: #10b981; border-color: #10b981;">Live Dashboard →</a></div>
    <h1 style="border-bottom: 2px solid #f59e0b; padding-bottom: 10px; color: #f59e0b;">ADMIN OVERRIDE CONSOLE</h1>
    
    <form method="GET" style="margin-bottom: 30px;">
        <select name="tournament_id" onchange="this.form.submit()"><option value="">Select Event...</option>
            <?php $s = $pdo->query("SELECT id, name FROM tournaments"); while($r = $s->fetch()) { $sel = $tid==$r['id']?'selected':''; echo "<option value='{$r['id']}' $sel>{$r['name']}</option>"; } ?>
        </select>
    </form>

    <?php if ($tid): $ts = $pdo->prepare("SELECT * FROM tournament_teams WHERE tournament_id=?"); $ts->execute([$tid]); foreach($ts->fetchAll() as $t): ?>
        <div class="team-block">
            <div style="display: flex; justify-content: space-between; border-bottom: 1px solid #333; padding-bottom: 10px;">
                <form action="manage_action.php" method="POST" style="display:flex; gap:5px;">
                    <input type="hidden" name="action" value="edit_team"><input type="hidden" name="team_id" value="<?php echo $t['id']; ?>"><input type="hidden" name="tournament_id" value="<?php echo $tid; ?>">
                    <input type="text" name="team_name" value="<?php echo htmlspecialchars($t['team_name']); ?>" required><input type="text" name="group_name" value="<?php echo htmlspecialchars($t['group_name']??''); ?>">
                    <button type="submit">Update</button>
                </form>
                <form action="manage_action.php" method="POST" onsubmit="return confirm('Delete team?');">
                    <input type="hidden" name="action" value="delete_team"><input type="hidden" name="team_id" value="<?php echo $t['id']; ?>"><input type="hidden" name="tournament_id" value="<?php echo $tid; ?>">
                    <button type="submit" class="btn-danger">Delete</button>
                </form>
            </div>
            <table>
                <tr><th>IGN</th><th>Primary UID</th><th>Alt UID</th><th>Action</th></tr>
                <?php $ps = $pdo->prepare("SELECT * FROM tournament_players WHERE team_id=?"); $ps->execute([$t['id']]); while($p = $ps->fetch()): ?>
                    <tr>
                        <form action="manage_action.php" method="POST">
                            <input type="hidden" name="action" value="edit_player"><input type="hidden" name="player_id" value="<?php echo $p['id']; ?>"><input type="hidden" name="tournament_id" value="<?php echo $tid; ?>">
                            <td><input type="text" name="ign" value="<?php echo htmlspecialchars($p['ign']); ?>" required></td>
                            <td><input type="text" name="bgmi_id" value="<?php echo htmlspecialchars($p['bgmi_id']); ?>"></td>
                            <td><input type="text" name="alt_bgmi_id" value="<?php echo htmlspecialchars($p['alt_bgmi_id']??''); ?>"></td>
                            <td><button type="submit">Save</button> <button type="submit" name="action" value="delete_player" class="btn-danger">X</button></td>
                        </form>
                    </tr>
                <?php endwhile; ?>
            </table>
        </div>
    <?php endforeach; endif; ?>
</body>
</html>
<?php
require 'db.php';

// --- FACTORY RESET TOURNAMENT DATA ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reset_database') {
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0; 
                TRUNCATE TABLE match_player_stats; 
                TRUNCATE TABLE match_team_stats; 
                TRUNCATE TABLE matches; 
                SET FOREIGN_KEY_CHECKS = 1;");
    header("Location: setup.php");
    exit;
}

// --- RESET DAILY SCHEDULE OVERLAY ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reset_schedule') {
    try {
        $pdo->exec("UPDATE matches SET show_on_schedule = 0");
    } catch (Exception $e) {
        // Safe fallback in case the SQL column hasn't been added yet
    }
    header("Location: setup.php");
    exit;
}

// --- HANDLE REMOTE CONTROL BUTTONS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'set_live_group') {
    $target_group = $_POST['target_group'];
    $pdo->prepare("UPDATE broadcast_settings SET active_group_standings = ? WHERE id = 1")->execute([$target_group]);
    header("Location: setup.php");
    exit;
}

// 1. Handle Group Creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_group') {
    $new_group = trim($_POST['new_group_name']);
    $names = explode("\n", str_replace("\r", "", trim($_POST['team_names'])));
    $tags = explode("\n", str_replace("\r", "", trim($_POST['team_tags'])));
    
    $combined_list = [];
    foreach ($names as $index => $name) {
        $name = trim($name);
        $tag = isset($tags[$index]) ? trim($tags[$index]) : '';
        if (empty($tag)) $tag = $name;
        if (!empty($name)) $combined_list[] = $name . '|' . $tag; 
    }
    
    $teams_list = implode("\n", $combined_list);
    if (!empty($new_group) && !empty($teams_list)) {
        $stmt = $pdo->prepare("INSERT INTO tournament_groups (group_name, teams_list) VALUES (?, ?) ON DUPLICATE KEY UPDATE teams_list = ?");
        $stmt->execute([$new_group, $teams_list, $teams_list]);
    }
    header("Location: setup.php");
    exit;
}

// 2. Handle Match Creation & Scheduling
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_match') {
    $round_name = trim($_POST['round_name']);
    $match_no = trim($_POST['match_no']);
    $map_name = trim($_POST['map_name']);
    $scheduled_time = $_POST['scheduled_time'];
    
    $selected_groups = $_POST['playing_groups'] ?? [];
    $group_name_string = !empty($selected_groups) ? implode(' vs ', $selected_groups) : 'TBD';
    
    // Automatically flags new matches with show_on_schedule = 1 (if column exists)
    try {
        $stmt = $pdo->prepare("INSERT INTO matches (round_name, group_name, match_no, map_name, scheduled_time, status, is_active, show_on_schedule) VALUES (?, ?, ?, ?, ?, 'scheduled', 0, 1)");
        $stmt->execute([$round_name, $group_name_string, $match_no, $map_name, $scheduled_time]);
    } catch (Exception $e) {
        $stmt = $pdo->prepare("INSERT INTO matches (round_name, group_name, match_no, map_name, scheduled_time, status, is_active) VALUES (?, ?, ?, ?, ?, 'scheduled', 0)");
        $stmt->execute([$round_name, $group_name_string, $match_no, $map_name, $scheduled_time]);
    }
    $new_match_id = $pdo->lastInsertId();

    $teams_input = [];
    if (!empty($selected_groups)) {
        $inQuery = implode(',', array_fill(0, count($selected_groups), '?'));
        $grp_stmt = $pdo->prepare("SELECT teams_list FROM tournament_groups WHERE group_name IN ($inQuery)");
        $grp_stmt->execute($selected_groups);
        $fetched_lists = $grp_stmt->fetchAll(PDO::FETCH_COLUMN);
        
        foreach ($fetched_lists as $list) {
            $lines = explode("\n", str_replace("\r", "", $list));
            foreach ($lines as $line) {
                $team_data = trim($line);
                if (!empty($team_data) && !in_array($team_data, $teams_input)) {
                    $teams_input[] = $team_data; 
                }
            }
        }
    }

    foreach ($teams_input as $team_string) {
        $parts = explode('|', $team_string);
        $team_name = $parts[0];
        $team_tag = $parts[1] ?? $team_name;

        $stmt_team = $pdo->prepare("INSERT INTO match_team_stats (match_id, team_name, team_tag) VALUES (?, ?, ?)");
        $stmt_team->execute([$new_match_id, $team_name, $team_tag]);

        $stmt_player = $pdo->prepare("INSERT INTO match_player_stats (match_id, team_name, ign) VALUES (?, ?, ?)");
        for ($i = 1; $i <= 4; $i++) {
            $stmt_player->execute([$new_match_id, $team_name, $team_tag . " P" . $i]);
        }
    }
    
    header("Location: setup.php");
    exit;
}

// 3. Handle Initializing the Lobby
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'initialize_match') {
    $init_id = $_POST['match_id'];
    
    $stmt = $pdo->prepare("SELECT group_name FROM matches WHERE id = ?");
    $stmt->execute([$init_id]);
    $match_group_string = $stmt->fetchColumn();
    $first_group = explode(' vs ', $match_group_string)[0];

    $pdo->prepare("UPDATE matches SET is_active = 0 WHERE is_active = 1")->execute();
    $pdo->prepare("UPDATE matches SET status = 'idle', is_active = 1 WHERE id = ?")->execute([$init_id]);
    
    if (!empty($first_group) && $first_group !== 'TBD') {
        $pdo->prepare("UPDATE broadcast_settings SET active_group_standings = ? WHERE id = 1")->execute([$first_group]);
    }
    
    header("Location: setup.php");
    exit;
}

// 4. HANDLE INDIVIDUAL MATCH DELETION
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_match') {
    $del_id = $_POST['match_id'];
    
    $pdo->prepare("DELETE FROM match_player_stats WHERE match_id = ?")->execute([$del_id]);
    $pdo->prepare("DELETE FROM match_team_stats WHERE match_id = ?")->execute([$del_id]);
    $pdo->prepare("DELETE FROM matches WHERE id = ?")->execute([$del_id]);
    
    header("Location: setup.php");
    exit;
}

// 5. HANDLE TEAM RENAMING GLOBALLY
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'rename_team') {
    $old_name = trim($_POST['old_team_name']);
    $new_name = trim($_POST['new_team_name']);
    $new_tag = trim($_POST['new_team_tag']);

    if (!empty($old_name) && !empty($new_name)) {
        if (empty($new_tag)) $new_tag = $new_name;

        $pdo->prepare("UPDATE match_team_stats SET team_name = ?, team_tag = ? WHERE LOWER(team_name) = LOWER(?)")->execute([$new_name, $new_tag, $old_name]);
        $pdo->prepare("UPDATE match_player_stats SET team_name = ? WHERE LOWER(team_name) = LOWER(?)")->execute([$new_name, $old_name]);

        $groups = $pdo->query("SELECT id, teams_list FROM tournament_groups")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($groups as $g) {
            $lines = explode("\n", str_replace("\r", "", $g['teams_list']));
            $updated_lines = [];
            $changed = false;
            foreach ($lines as $line) {
                $parts = explode('|', $line);
                $t_name = trim($parts[0]);
                
                if (strcasecmp($t_name, $old_name) === 0) { 
                    $updated_lines[] = $new_name . '|' . $new_tag;
                    $changed = true;
                } else {
                    if (!empty(trim($line))) $updated_lines[] = trim($line);
                }
            }
            if ($changed) {
                $new_list = implode("\n", $updated_lines);
                $pdo->prepare("UPDATE tournament_groups SET teams_list = ? WHERE id = ?")->execute([$new_list, $g['id']]);
            }
        }
    }
    header("Location: setup.php");
    exit;
}

// Fetch Data for UI
$groups_stmt = $pdo->query("SELECT group_name, teams_list FROM tournament_groups ORDER BY id ASC");
$all_groups = $groups_stmt->fetchAll(PDO::FETCH_ASSOC);

$rounds_stmt = $pdo->query("SELECT DISTINCT round_name FROM matches WHERE round_name IS NOT NULL AND round_name != '' ORDER BY round_name ASC");
$existing_rounds = $rounds_stmt->fetchAll(PDO::FETCH_COLUMN);

$maps_stmt = $pdo->query("SELECT DISTINCT map_name FROM matches WHERE map_name IS NOT NULL AND map_name != '' ORDER BY map_name ASC");
$existing_maps = $maps_stmt->fetchAll(PDO::FETCH_COLUMN);

$schedule_stmt = $pdo->query("SELECT * FROM matches ORDER BY id DESC LIMIT 15");
$matches = array_reverse($schedule_stmt->fetchAll(PDO::FETCH_ASSOC));

$settings_stmt = $pdo->query("SELECT active_group_standings FROM broadcast_settings WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
$live_group = $settings_stmt['active_group_standings'] ?? '';

$active_match_stmt = $pdo->query("SELECT group_name FROM matches WHERE is_active = 1 LIMIT 1");
$active_match_group_str = $active_match_stmt->fetchColumn();
$active_groups_array = $active_match_group_str ? explode(' vs ', $active_match_group_str) : [];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>BROADCAST SCHEDULE | NexusForge</title>
    <style>
        @import url("https://fonts.googleapis.com/css2?family=Oswald:wght@400;700;900&display=swap");
        body { background: #050505; color: #fff; font-family: "Oswald", sans-serif; margin: 0; padding: 40px; text-transform: uppercase; }
        
        .top-nav { display: flex; gap: 15px; margin-bottom: 20px; }
        .nav-btn { background: #333; color: #fff; padding: 10px 20px; text-decoration: none; font-weight: bold; border: 2px solid #555; transition: 0.2s; display: inline-block; }
        .nav-btn:hover { background: #8b5cf6; border-color: #8b5cf6; }

        .remote-panel { background: #1a1a1a; padding: 20px; border: 2px solid #ef4444; border-top: 8px solid #ef4444; margin-bottom: 40px; display: flex; flex-direction: column; gap: 15px; }
        .remote-buttons { display: flex; gap: 15px; flex-wrap: wrap; }
        .btn-grp { background: #333; color: #fff; border: 2px solid #555; padding: 10px 20px; font-family: "Oswald", sans-serif; font-weight: bold; font-size: 16px; cursor: pointer; transition: 0.2s; }
        .btn-grp:hover { background: #555; }
        .btn-grp.active { background: #ef4444; border-color: #ef4444; color: #fff; box-shadow: 0 0 15px rgba(239, 68, 68, 0.5); }
        .btn-grp.playing { border-color: #10b981; border-width: 3px; }

        .grid-container { display: grid; grid-template-columns: 2fr 1fr; gap: 40px; }
        .panel { background: #111; border: 2px solid #333; padding: 30px; border-top: 8px solid #8b5cf6; margin-bottom: 20px; }
        h1, h2 { margin-top: 0; font-weight: 900; letter-spacing: 1px; color: #8b5cf6; }
        
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th { background: #222; padding: 10px; text-align: left; }
        td { padding: 12px 10px; border-bottom: 1px solid #222; font-family: monospace; font-size: 16px; vertical-align: middle; }
        
        .status-badge { padding: 4px 8px; font-weight: bold; font-size: 12px; background: #333; }
        .status-scheduled { background: #475569; color: #fff; }
        .status-idle { background: #eab308; color: #000; }
        .status-active { background: #10b981; color: #000; }
        .status-finished { background: #ef4444; color: #fff; }
        
        .init-btn { background: #10b981; color: #000; font-weight: bold; border: none; padding: 6px 12px; cursor: pointer; font-family: "Oswald", sans-serif; transition: 0.2s; }
        .init-btn:hover { background: #059669; }
        .delete-btn { background: #ef4444; color: #fff; border: none; padding: 6px 10px; font-weight: bold; cursor: pointer; border-radius: 3px; font-family: "Oswald", sans-serif; margin-left: 5px; }
        .delete-btn:hover { background: #dc2626; }
        
        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 5px; font-weight: bold; color: #aaa; font-size: 14px; }
        input[type="text"], input[type="time"], select, textarea { width: 100%; box-sizing: border-box; background: #000; color: #fff; border: 2px solid #444; padding: 12px; font-family: "Oswald", sans-serif; font-size: 16px; }
        input:focus, select:focus, textarea:focus { border-color: #8b5cf6; outline: none; }
        
        .ta-groups { resize: vertical; min-height: 150px; white-space: pre; }
        
        .cb-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(120px, 1fr)); gap: 10px; background: #000; padding: 15px; border: 2px solid #444; }
        .cb-label { display: flex; align-items: center; gap: 8px; cursor: pointer; font-weight: bold; color: #fff; }
        .cb-label input[type="checkbox"] { width: 20px; height: 20px; accent-color: #8b5cf6; cursor: pointer; }
        
        .submit-btn { width: 100%; background: #8b5cf6; color: #fff; padding: 15px; font-size: 20px; font-weight: 900; border: none; cursor: pointer; transition: 0.2s; }
        .submit-btn:hover { background: #7c3aed; }
        .winner-text { color: #f59e0b; font-weight: bold; }
    </style>
</head>
<body>

    <div class="top-nav" style="display: flex; justify-content: space-between; align-items: center;">
        <div style="display: flex; gap: 10px;">
            <a href="dashboard.php" class="nav-btn">LIVE DASHBOARD</a>
            <a href="match_history.php" class="nav-btn">MATCH HISTORY</a>
            <a href="roster_sync.php" class="nav-btn" style="border-color: #3b82f6;">ROSTER SYNC</a>
            <a href="upload_result.php" class="nav-btn" style="border-color: #10b981;">UPLOAD RESULT</a>
        </div>
        <div style="display: flex; gap: 10px;">
            <form method="POST" style="margin:0;" onsubmit="return confirm('Clear the daily schedule graphic overlay? The matches will still exist in the database and history.');">
                <input type="hidden" name="action" value="reset_schedule">
                <button type="submit" class="nav-btn" style="background: #f59e0b; border-color: #d97706; color: #000;">🔄 RESET DAY SCHEDULE</button>
            </form>
            <form method="POST" style="margin:0;" onsubmit="return confirm('⚠️ WARNING: This will permanently delete ALL matches and points from the database! Your Groups will be saved. Continue?');">
                <input type="hidden" name="action" value="reset_database">
                <button type="submit" class="nav-btn" style="background: #ef4444; border-color: #dc2626;">⚠️ WIPE ALL MATCH DATA</button>
            </form>
        </div>
    </div>

    <!-- LIVE GRAPHIC REMOTE CONTROL -->
    <div class="remote-panel">
        <h2 style="color: #ef4444; margin: 0; display: flex; justify-content: space-between;">
            <span>🔴 VMIX REMOTE: ACTIVE GROUP STANDINGS</span>
            <span style="font-size: 16px; color: #aaa;">(🟢 = Playing in Active Match)</span>
        </h2>
        <div class="remote-buttons">
            <?php foreach($all_groups as $g): 
                $is_playing = in_array($g['group_name'], $active_groups_array);
            ?>
                <form method="POST" style="margin:0;">
                    <input type="hidden" name="action" value="set_live_group">
                    <input type="hidden" name="target_group" value="<?= htmlspecialchars($g['group_name']) ?>">
                    <button type="submit" class="btn-grp <?= ($live_group === $g['group_name']) ? 'active' : '' ?> <?= $is_playing ? 'playing' : '' ?>">
                        <?= htmlspecialchars($g['group_name']) ?> <?= $is_playing ? '🟢' : '' ?>
                    </button>
                </form>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="grid-container">
        
        <!-- LEFT PANEL: DAILY SCHEDULE -->
        <div class="panel">
            <h2>DAILY SCHEDULE & MATCH LOG</h2>
            <table>
                <thead>
                    <tr>
                        <th>MATCH</th>
                        <th>ROUND & GROUPS</th>
                        <th>MAP</th>
                        <th>TIME</th>
                        <th>STATUS</th>
                        <th>ACTION / RESULT</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($matches as $m): 
                        $wwcd = "---";
                        if ($m['status'] === 'finished') {
                            $win_stmt = $pdo->prepare("SELECT team_name, team_tag FROM match_team_stats WHERE match_id = ? AND elimination_rank = 1 LIMIT 1");
                            $win_stmt->execute([$m['id']]);
                            $win_data = $win_stmt->fetch(PDO::FETCH_ASSOC);
                            if ($win_data) $wwcd = "🏆 " . ($win_data['team_tag'] ?: $win_data['team_name']);
                        }
                    ?>
                    <tr style="<?= $m['is_active'] == 1 ? 'background: rgba(139, 92, 246, 0.15); border-left: 4px solid #8b5cf6;' : '' ?>">
                        <td style="font-weight: bold; color: #fff;"><?= htmlspecialchars($m['match_no']) ?></td>
                        <td><?= htmlspecialchars($m['round_name']) ?> | <span style="color:#8b5cf6; font-weight:bold;"><?= htmlspecialchars($m['group_name']) ?></span></td>
                        <td><?= htmlspecialchars($m['map_name']) ?></td>
                        <td><?= date("H:i", strtotime($m['scheduled_time'] ?? '00:00')) ?></td>
                        <td><span class="status-badge status-<?= $m['status'] ?>"><?= $m['status'] ?></span></td>
                        <td style="display: flex; align-items: center;">
                            <?php if ($m['status'] === 'scheduled'): ?>
                                <form method="POST" style="margin:0;">
                                    <input type="hidden" name="action" value="initialize_match">
                                    <input type="hidden" name="match_id" value="<?= $m['id'] ?>">
                                    <button type="submit" class="init-btn" onclick="return confirm('Initialize this lobby for the broadcast?')">INITIALIZE LOBBY</button>
                                </form>
                            <?php elseif ($m['status'] === 'idle'): ?>
                                <span style="color:#aaa; font-size: 14px; flex-grow: 1;">(Dashboard)</span>
                            <?php elseif ($m['status'] === 'active'): ?>
                                <span style="color:#10b981; font-weight:bold; flex-grow: 1;">LIVE NOW</span>
                            <?php elseif ($m['status'] === 'finished'): ?>
                                <span class="winner-text" style="flex-grow: 1;"><?= $wwcd ?></span>
                            <?php endif; ?>

                            <!-- INDIVIDUAL DELETE BUTTON -->
                            <form method="POST" style="margin:0;" onsubmit="return confirm('Are you sure you want to delete this match? All points associated with it will be permanently lost.');">
                                <input type="hidden" name="action" value="delete_match">
                                <input type="hidden" name="match_id" value="<?= $m['id'] ?>">
                                <button type="submit" class="delete-btn" title="Delete Match">X</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- RIGHT SIDE: CONTROLS -->
        <div>
            <!-- GROUP MANAGER -->
            <div class="panel" style="border-top-color: #10b981;">
                <h2>1. CREATE / EDIT GROUPS</h2>
                <form method="POST">
                    <input type="hidden" name="action" value="create_group">
                    <div class="form-group">
                        <label>GROUP NAME</label>
                        <input type="text" name="new_group_name" placeholder="e.g. Group A" required>
                    </div>
                    
                    <div style="display: grid; grid-template-columns: 3fr 1fr; gap: 10px;">
                        <div class="form-group">
                            <label>FULL TEAM NAMES (For Broadcast)</label>
                            <textarea name="team_names" class="ta-groups" placeholder="Team Soul&#10;GodLike Esports&#10;Global Esports..." required></textarea>
                        </div>
                        <div class="form-group">
                            <label>SHORT TAG (For Web)</label>
                            <textarea name="team_tags" class="ta-groups" placeholder="SOUL&#10;GODL&#10;GE..." required></textarea>
                        </div>
                    </div>

                    <button type="submit" class="submit-btn" style="background: #10b981; font-size: 16px;">SAVE GROUP & TEAMS</button>
                </form>
            </div>

            <!-- MATCH CREATOR -->
            <div class="panel">
                <h2>2. ADD MATCH TO SCHEDULE</h2>
                <form method="POST">
                    <input type="hidden" name="action" value="create_match">
                    
                    <div class="form-group">
                        <label>SELECT PLAYING GROUPS (Teams will Auto-Pull)</label>
                        <div class="cb-grid">
                            <?php if(empty($all_groups)): ?>
                                <span style="color:#ef4444; font-size:14px;">No groups exist yet. Create them above!</span>
                            <?php else: ?>
                                <?php foreach($all_groups as $g): ?>
                                    <label class="cb-label">
                                        <input type="checkbox" name="playing_groups[]" value="<?= htmlspecialchars($g['group_name']) ?>">
                                        <?= htmlspecialchars($g['group_name']) ?>
                                    </label>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                        <div class="form-group">
                            <label>ROUND NAME (Used as Stage/Phase)</label>
                            <input type="text" name="round_name" list="round-suggestions" placeholder="e.g. Semi-Finals" required>
                            <datalist id="round-suggestions">
                                <?php foreach($existing_rounds as $r): ?>
                                    <option value="<?= htmlspecialchars($r) ?>">
                                <?php endforeach; ?>
                            </datalist>
                        </div>
                        <div class="form-group">
                            <label>MATCH NO</label>
                            <input type="text" name="match_no" placeholder="e.g. M1" required>
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                        <div class="form-group">
                            <label>MAP</label>
                            <input type="text" name="map_name" list="map-suggestions" placeholder="Select Map" required>
                            <datalist id="map-suggestions">
                                <option value="Erangel"><option value="Miramar"><option value="Sanhok">
                                <?php foreach($existing_maps as $map): 
                                    if(!in_array($map, ['Erangel', 'Miramar', 'Sanhok'])): ?>
                                        <option value="<?= htmlspecialchars($map) ?>">
                                <?php endif; endforeach; ?>
                            </datalist>
                        </div>
                        <div class="form-group">
                            <label>TIME</label>
                            <input type="time" name="scheduled_time" required>
                        </div>
                    </div>

                    <button type="submit" class="submit-btn">+ ADD MATCH TO SCHEDULE</button>
                </form>
            </div>

            <!-- GLOBAL TEAM RENAMER -->
            <div class="panel" style="border-top-color: #f59e0b;">
                <h2>3. GLOBAL TEAM RENAMER</h2>
                <form method="POST">
                    <input type="hidden" name="action" value="rename_team">
                    
                    <div class="form-group">
                        <label style="color: #f59e0b;">OLD TEAM NAME</label>
                        <input type="text" name="old_team_name" placeholder="e.g. Team Name1" required>
                    </div>
                    
                    <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 15px;">
                        <div class="form-group">
                            <label>NEW TEAM NAME</label>
                            <input type="text" name="new_team_name" placeholder="e.g. Team Name New1" required>
                        </div>
                        <div class="form-group">
                            <label>NEW SHORT TAG</label>
                            <input type="text" name="new_team_tag" placeholder="e.g. NEW1" required>
                        </div>
                    </div>

                    <button type="submit" class="submit-btn" style="background: #f59e0b; color: #000; font-size: 16px;">UPDATE ACROSS ENTIRE DATABASE</button>
                </form>
            </div>

        </div>
    </div>
</body>
</html>
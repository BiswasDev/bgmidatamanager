<?php require 'db.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>NexusForge // Registration</title>
    <style>
        :root { --bg: #000; --border: #333; --accent: #8b5cf6; }
        body { background: var(--bg); color: #fff; font-family: monospace; padding: 20px; display: flex; justify-content: center; }
        .container { max-width: 700px; width: 100%; background: #111; padding: 30px; border: 1px solid var(--border); box-shadow: 8px 8px 0px var(--accent); }
        h1 { border-bottom: 2px solid var(--accent); padding-bottom: 10px; text-transform: uppercase; }
        input, select { width: 100%; padding: 10px; margin-bottom: 10px; background: var(--bg); border: 1px solid var(--border); color: #fff; box-sizing: border-box; }
        .player-block { border: 1px solid var(--border); padding: 15px; margin-bottom: 15px; }
        .player-row { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px; }
        button { background: #fff; color: var(--bg); padding: 15px; width: 100%; font-weight: bold; cursor: pointer; text-transform: uppercase; border: none; }
    </style>
</head>
<body>
<div class="container">
    <h1>TEAM REGISTRATION</h1>
    <form action="process_registration.php" method="POST" enctype="multipart/form-data">
        <select name="tournament_id" required><option value="">Select Tournament</option>
            <?php $s = $pdo->query("SELECT id, name FROM tournaments"); while($r = $s->fetch()) echo "<option value='{$r['id']}'>{$r['name']}</option>"; ?>
        </select>
        <input type="text" name="team_name" placeholder="Team Name" required>
        <label style="font-size: 0.8rem; color: #aaa;">Team Logo (PNG):</label> <input type="file" name="team_logo" accept="image/png" required>
        
        <h4 style="color: #aaa;">Core Roster (1-4)</h4>
        <?php for($i=1; $i<=4; $i++) { ?>
            <div class="player-block">
                <div class="player-row"><input type="text" name="ign[]" placeholder="IGN" required><input type="text" name="bgmi_id[]" placeholder="Primary UID" required><input type="text" name="alt_bgmi_id[]" placeholder="Alt UID (Opt)"></div>
                <label style="font-size: 0.8rem; color: #aaa;">Headshot (Transparent PNG):</label> <input type="file" name="player_image[]" accept="image/png">
            </div>
        <?php } ?>
        
        <h4 style="color: #aaa;">Substitutes (5-6) - Optional</h4>
        <?php for($i=5; $i<=6; $i++) { ?>
            <div class="player-block" style="opacity: 0.7;">
                <div class="player-row"><input type="text" name="ign[]" placeholder="IGN (Opt)"><input type="text" name="bgmi_id[]" placeholder="UID"><input type="text" name="alt_bgmi_id[]" placeholder="Alt UID"></div>
                <label style="font-size: 0.8rem; color: #aaa;">Headshot (Transparent PNG):</label> <input type="file" name="player_image[]" accept="image/png">
            </div>
        <?php } ?>
        <button type="submit">Submit Roster</button>
    </form>
</div>
</body>
</html>
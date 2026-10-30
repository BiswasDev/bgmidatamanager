<?php
require 'db.php';

$active_match = $pdo->query("SELECT * FROM matches WHERE status IN ('idle', 'active') ORDER BY id DESC LIMIT 1")->fetch();
if (!$active_match) die("<h2 style='color:white; font-family:monospace; padding: 40px;'>NO ONGOING MATCH. GO TO SETUP.PHP.</h2>");

$match_id = $active_match['id'];
$match_status = $active_match['status'];

// Check if map is Rondo (case insensitive)
$is_rondo = (strtolower(trim($active_match['map_name'] ?? '')) === 'rondo');

$stmt = $pdo->prepare("SELECT * FROM match_team_stats WHERE match_id = ? ORDER BY id ASC");
$stmt->execute([$match_id]);
$teams = $stmt->fetchAll();

$stmt_players = $pdo->prepare("SELECT * FROM match_player_stats WHERE match_id = ?");
$stmt_players->execute([$match_id]);
$all_players = $stmt_players->fetchAll();

$players_by_team = [];
foreach ($all_players as $p) {
    $players_by_team[$p['team_name']][] = $p;
}

// Calculate Alive Teams
$total_teams = count($teams);
$alive_teams = 0;
foreach ($teams as $t) {
    if ($t['is_eliminated'] == 0) {
        $alive_teams++;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <title>NexusForge | Live Match Ops (25-Slot Ready)</title>
    <style>
        body { background: #0b0b0f; color: #fff; font-family: monospace; padding: 15px; text-transform: uppercase; }
        .top-bar { display: flex; justify-content: space-between; align-items: center; background: #1a1a24; padding: 15px 20px; border: 2px solid #8b5cf6; margin-bottom: 15px; }
        .controls { display: flex; gap: 10px; align-items: center; }
        button { padding: 6px 12px; font-weight: bold; cursor: pointer; border: none; font-family: monospace; text-transform: uppercase; }
        select { padding: 6px; background: #000; color: #fff; border: 1px solid #444; font-family: monospace; }
        .btn-start { background: #10b981; color: #000; font-size: 1rem; }
        .btn-action { background: #3b82f6; color: #fff; }
        .btn-wipe { background: #ef4444; color: white; padding: 4px 8px; font-size: 0.8rem;}
        
        /* OPTIMIZED GRID FOR 25 TEAMS: Smaller minimum width so more fit on screen */
        .team-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(290px, 1fr)); gap: 10px; }
        
        .team-card { background: #1a1a24; border-left: 4px solid #8b5cf6; padding: 10px; }
        .team-card.eliminated { border-color: #ef4444; opacity: 0.5; filter: grayscale(1); }
        .team-header { display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #333; padding-bottom: 8px; margin-bottom: 8px; font-size: 1.1rem; font-weight: bold;}
        
        /* DENSER PLAYER ROWS */
        .player-row { display: flex; justify-content: space-between; align-items: center; background: #000; padding: 5px 8px; margin-bottom: 4px; border: 1px solid #222; }
        .player-info { display: flex; flex-direction: column; width: 100px; }
        .player-ign { font-weight: bold; font-size: 0.85rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .player-status { font-size: 0.65rem; color: #aaa; margin-top: 1px;}
        
        .p-controls { display: flex; gap: 4px; align-items: center;}
        .btn-k { background: #eab308; color: #000; padding: 3px 6px; font-size: 0.75rem; }
        .btn-d { background: #ef4444; color: #fff; padding: 3px 6px; font-size: 0.75rem; }
        .btn-a { background: #10b981; color: #000; padding: 3px 6px; font-size: 0.75rem; }
        .btn-r { background: #8b5cf6; color: #fff; padding: 3px 6px; font-size: 0.75rem; } 
        .btn-kill { background: #444; color: #fff; padding: 3px 8px; font-size: 0.85rem; }
        
        .stat-Alive { color: #10b981; }
        .stat-Knocked { color: #eab308; }
        .stat-Dead { color: #ef4444; }
        
        .alive-badge { display: inline-block; margin-top: 5px; padding: 4px 10px; font-weight: bold; font-size: 1rem; border: 2px solid; }
        .alive-safe { background: #10b981; color: #000; border-color: #059669; }
        .alive-danger { background: #ef4444; color: #fff; border-color: #dc2626; }
    </style>
</head>
<body>

    <div class="top-bar">
        <div>
            <h1 style="margin:0; font-size: 1.5rem; color:#8b5cf6;">LIVE MATCH OPS</h1>
            <div style="color:#aaa; font-size: 0.9rem;">MAP: <span style="color:#fff; font-weight:bold;"><?= htmlspecialchars($active_match['map_name'] ?? 'UNKNOWN') ?></span> | MATCH ID: <?= $match_id ?></div>
            
            <div class="alive-badge <?= ($alive_teams <= 4) ? 'alive-danger' : 'alive-safe' ?>">
                <?= ($alive_teams <= 4) ? '🔥' : '🟢' ?> ALIVE TEAMS: <?= $alive_teams ?> / <?= $total_teams ?>
            </div>
        </div>
        
        <div class="controls">
            <div style="background: #000; padding: 8px; border: 1px solid #444; display: flex; gap: 8px; align-items: center;">
                <label style="font-size: 0.9rem;">CIRCLE:</label>
                <select id="circle-select">
                    <?php for($i=1; $i<=9; $i++): ?>
                        <option value="<?= $i ?>" <?= ($active_match['zone_circle'] ?? 1) == $i ? 'selected' : '' ?>>STAGE <?= $i ?></option>
                    <?php endfor; ?>
                </select>
                
                <button class="btn-action" onclick="updateMatchInfo()">UPDATE ENV</button>
            </div>

            <?php if($match_status === 'idle'): ?>
                <button class="btn-start" onclick="startMatchTimer()">▶ START MATCH</button>
            <?php else: ?>
                <button class="btn-action" style="background:#8b5cf6;" onclick="triggerWWCD()">🏆 FORCE WWCD</button>
                <button class="btn-wipe" style="padding: 6px 12px;" onclick="forceFinish()">⏹ FINISH & AUDIT</button>
            <?php endif; ?>
        </div>
    </div>

    <div class="team-grid">
        <?php $slot_no = 3; ?>
        <?php foreach ($teams as $team): ?>
            <div class="team-card <?= $team['is_eliminated'] ? 'eliminated' : '' ?>">
                <div class="team-header">
                    <div style="display: flex; flex-direction: column;">
                        <div>
                            <span style="color: #eab308; font-weight: 900; margin-right: 5px;">#<?= $slot_no++ ?></span>
                            <span><?= htmlspecialchars($team['team_name']) ?></span>
                        </div>
                        <span style="font-size: 0.75rem; color:#aaa; font-weight: normal;">
                            FINISHES: <?= $team['total_finishes'] ?> 
                            <?php if($is_rondo): ?>
                                | <span style="color:#8b5cf6;">RECALLS: <?= $team['total_recalls'] ?? 0 ?></span>
                            <?php endif; ?>
                        </span>
                    </div>
                    <?php if(!$team['is_eliminated']): ?>
                        <button class="btn-wipe" onclick="wipeSquad('<?= htmlspecialchars($team['team_name'], ENT_QUOTES) ?>')">WIPE</button>
                    <?php endif; ?>
                </div>

                <div class="player-list">
                    <?php 
                    $players = $players_by_team[$team['team_name']] ?? [];
                    foreach ($players as $player): 
                    ?>
                        <div class="player-row">
                            <div class="player-info">
                                <span class="player-ign">
                                    <?= htmlspecialchars($player['ign']) ?>
                                    <?= (!empty($player['is_recalled']) && $is_rondo) ? '<span title="Recalled" style="color:#8b5cf6;">🔄</span>' : '' ?>
                                </span>
                                <span class="player-status stat-<?= $player['status'] ?>"><?= $player['status'] ?></span>
                            </div>
                            
                            <div class="p-controls" style="margin-right: 10px;">
                                <button class="btn-kill" onclick="updateKill('<?= htmlspecialchars($player['ign'], ENT_QUOTES) ?>', '<?= htmlspecialchars($team['team_name'], ENT_QUOTES) ?>', -1)">-</button>
                                <span style="font-weight: bold; width: 16px; text-align: center; font-size: 0.9rem;"><?= $player['finishes'] ?></span>
                                <button class="btn-kill" onclick="updateKill('<?= htmlspecialchars($player['ign'], ENT_QUOTES) ?>', '<?= htmlspecialchars($team['team_name'], ENT_QUOTES) ?>', 1)">+</button>
                            </div>

                            <div class="p-controls">
                                <button class="btn-a" onclick="updateStatus('<?= htmlspecialchars($player['ign'], ENT_QUOTES) ?>', '<?= htmlspecialchars($team['team_name'], ENT_QUOTES) ?>', 'Alive')">A</button>
                                <button class="btn-k" onclick="updateStatus('<?= htmlspecialchars($player['ign'], ENT_QUOTES) ?>', '<?= htmlspecialchars($team['team_name'], ENT_QUOTES) ?>', 'Knocked')">K</button>
                                <button class="btn-d" onclick="updateStatus('<?= htmlspecialchars($player['ign'], ENT_QUOTES) ?>', '<?= htmlspecialchars($team['team_name'], ENT_QUOTES) ?>', 'Dead')">D</button>
                                
                                <?php if(empty($player['is_recalled']) && $is_rondo): ?>
                                    <button class="btn-r" title="Recall Player" onclick="triggerRecall('<?= htmlspecialchars($player['ign'], ENT_QUOTES) ?>', '<?= htmlspecialchars($team['team_name'], ENT_QUOTES) ?>')">R</button>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <script>
        const MATCH_ID = <?= $match_id ?>;
        const API_ENDPOINT = 'api_update.php'; 

        function sendUpdate(dataObject, redirect = false) {
            const formData = new FormData();
            formData.append('match_id', MATCH_ID);
            for (const key in dataObject) formData.append(key, dataObject[key]);

            fetch(API_ENDPOINT, { method: 'POST', body: formData })
            .then(res => res.text())
            .then(text => {
                if (text.trim() === "") return;
                try {
                    const data = JSON.parse(text);
                    if (data.success) {
                        if(redirect) window.location.href = redirect;
                        else window.location.reload(); 
                    }
                } catch (e) { alert("API ERROR"); }
            });
        }

        function startMatchTimer() { sendUpdate({ action: 'start_match' }); }
        
        function updateMatchInfo() {
            const circle = document.getElementById('circle-select').value;
            sendUpdate({ action: 'update_match_info', circle: circle, recall: 0 });
        }
        
        function wipeSquad(teamName) {
            if(confirm(`Are you sure you want to WIPE ${teamName}?`)) sendUpdate({ action: 'wipe_squad', team_name: teamName });
        }
        function updateKill(ign, teamName, change) {
            sendUpdate({ action: 'update_kill', ign: ign, team_name: teamName, change: change });
        }
        function updateStatus(ign, teamName, newStatus) {
            sendUpdate({ action: 'update_status', ign: ign, team_name: teamName, status: newStatus });
        }
        
        function triggerRecall(ign, teamName) {
            if(confirm(`Trigger a RECALL for ${ign}? \nThis will set them ALIVE and deduct 1 recall from the team.`)) {
                sendUpdate({ action: 'trigger_recall', ign: ign, team_name: teamName });
            }
        }

        function triggerWWCD() {
            if(confirm('Trigger WWCD Graphic?')) sendUpdate({ action: 'trigger_wwcd' });
        }
        function forceFinish() {
            if(confirm('Proceed to Post-Match Audit?')) window.location.href = `post_match.php?match_id=${MATCH_ID}`;
        }
    </script>
</body>
</html>
<?php
require 'db.php';

// Fetch all matches (newest first)
$match_stmt = $pdo->query("SELECT * FROM matches ORDER BY id DESC");
$history_matches = $match_stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>MATCH HISTORY | NexusForge</title>
    <style>
        @import url("https://fonts.googleapis.com/css2?family=Oswald:wght@400;700;900&display=swap");
        
        body { 
            background: #050505; color: #fff; font-family: "Oswald", sans-serif; 
            margin: 0; padding: 40px; text-transform: uppercase; 
        }
        
        /* Navigation & Header */
        .top-nav { display: flex; gap: 15px; margin-bottom: 20px; }
        .nav-btn {
            background: #333; color: #fff; padding: 10px 20px; text-decoration: none; 
            font-weight: bold; border: 2px solid #555; transition: 0.2s;
        }
        .nav-btn:hover { background: #8b5cf6; border-color: #8b5cf6; }
        
        .header-box {
            background: #8b5cf6; color: #000; padding: 20px;
            border: 4px solid #fff; box-shadow: 8px 8px 0px #fff;
            margin-bottom: 40px; display: flex; justify-content: space-between; align-items: center;
        }
        h1 { margin: 0; font-size: 40px; font-weight: 900; letter-spacing: 2px; }
        
        /* Match Cards */
        .match-container { display: flex; flex-direction: column; gap: 40px; }
        .match-card {
            background: #111; border: 2px solid #333; border-left: 8px solid #8b5cf6;
            padding: 20px; position: relative;
        }
        .match-header { display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 20px; border-bottom: 2px solid #333; padding-bottom: 10px; }
        .match-id { font-size: 28px; font-weight: 900; color: #8b5cf6; }
        .match-meta { font-size: 14px; color: #aaa; text-align: right; }
        
        .edit-match-btn {
            display: inline-block; background: #fff; color: #000; padding: 6px 12px; margin-top: 10px;
            text-decoration: none; font-weight: 900; transition: 0.2s; box-shadow: 4px 4px 0px #8b5cf6;
        }
        .edit-match-btn:hover { transform: translate(2px, 2px); box-shadow: 2px 2px 0px #8b5cf6; }
        
        /* Data Tables */
        table { width: 100%; border-collapse: collapse; background: #0a0a0a; }
        th { background: #222; color: #fff; padding: 10px; font-size: 14px; text-align: center; border-bottom: 2px solid #444; }
        td { padding: 8px; border-bottom: 1px solid #222; text-align: center; font-size: 16px; font-family: monospace; }
        .team-col { text-align: left; font-weight: bold; padding-left: 15px; font-family: "Oswald", sans-serif; font-size: 18px; }
        
        /* Highlighting */
        tr.wwcd-row { background: rgba(139, 92, 246, 0.15); border-bottom: 2px solid #8b5cf6; }
        tr.wwcd-row .team-col { color: #8b5cf6; }
        tr.wwcd-row td { font-weight: bold; }
        .stat-pts { color: #10b981; font-weight: bold; font-size: 18px; }
        .stat-pen { color: #ef4444; }
        .empty-state { text-align: center; padding: 50px; font-size: 24px; color: #555; border: 2px dashed #333; }
    </style>
</head>
<body>

    <div class="top-nav">
        <a href="setup.php" class="nav-btn">← BACK TO SETUP</a>
        <a href="dashboard.php" class="nav-btn">LIVE DASHBOARD</a>
    </div>

    <div class="header-box">
        <div>
            <h1>MATCH ARCHIVE</h1>
            <div style="font-size: 18px; font-weight: bold;">HISTORICAL DATA & FINALIZED POINTS</div>
        </div>
        <div style="font-size: 24px; font-weight: 900;">NEXUSFORGE</div>
    </div>

    <div class="match-container">
        <?php if (empty($history_matches)): ?>
            <div class="empty-state">NO MATCHES FOUND.</div>
        <?php else: ?>
            
            <?php foreach ($history_matches as $match): 
                $m_id = $match['id'];
                
                $stmt = $pdo->prepare("SELECT * FROM match_team_stats WHERE match_id = ? ORDER BY total_points DESC, placement_points DESC");
                $stmt->execute([$m_id]);
                $team_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);
            ?>
                
            <div class="match-card">
                <div class="match-header">
                    <div>
                        <div class="match-id">MATCH #<?= $m_id ?> <span style="font-size: 14px; color: #fff; background: #333; padding: 2px 8px; margin-left: 10px;"><?= $match['status'] ?></span></div>
                        <div style="font-size: 18px; font-weight: bold; letter-spacing: 1px;">MAP: <?= htmlspecialchars($match['map_name']) ?></div>
                    </div>
                    <div class="match-meta">
                        <div>STARTED: <?= $match['start_time'] ?? 'N/A' ?></div>
                        <div>FINISHED: <?= $match['end_time'] ?? 'N/A' ?></div>
                        <a href="post_match.php?match_id=<?= $m_id ?>" class="edit-match-btn">✏️ EDIT MATCH</a>
                    </div>
                </div>

                <table>
                    <thead>
                        <tr>
                            <th style="text-align: left; padding-left: 15px;">TEAM</th>
                            <th>ELIM RANK</th>
                            <th>KILLS</th>
                            <th>PLACE PTS</th>
                            <th>PENALTY</th>
                            <th>TOTAL PTS</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $rank_counter = 1;
                        foreach ($team_stats as $stat): 
                            $is_wwcd = ($rank_counter === 1);
                        ?>
                        <tr class="<?= $is_wwcd ? 'wwcd-row' : '' ?>">
                            <td class="team-col">
                                <?= $is_wwcd ? '🏆 ' : '' ?><?= htmlspecialchars($stat['team_name']) ?>
                            </td>
                            <td>#<?= $stat['elimination_rank'] ?></td>
                            <td><?= $stat['total_finishes'] ?></td>
                            <td><?= $stat['placement_points'] ?></td>
                            <td class="<?= $stat['penalty_points'] > 0 ? 'stat-pen' : '' ?>">
                                <?= $stat['penalty_points'] > 0 ? '-' . $stat['penalty_points'] : '0' ?>
                            </td>
                            <td class="stat-pts"><?= $stat['total_points'] ?></td>
                        </tr>
                        <?php 
                        $rank_counter++;
                        endforeach; 
                        ?>
                    </tbody>
                </table>
            </div>

            <?php endforeach; ?>
        <?php endif; ?>
    </div>

</body>
</html>
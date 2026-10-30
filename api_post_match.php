<?php
require 'db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $match = $pdo->query("SELECT id FROM matches WHERE is_active = 1 LIMIT 1")->fetch();
    $match_id = $match['id'];

    foreach ($_POST['kills'] as $team_name => $kills) {
        $placement = $_POST['placement'][$team_name];
        $penalty = $_POST['penalty'][$team_name];
        $total = ($kills + $placement) - $penalty;

        $stmt = $pdo->prepare("
            UPDATE match_team_stats 
            SET total_finishes = ?, placement_points = ?, penalty_points = ?, total_points = ? 
            WHERE match_id = ? AND team_name = ?
        ");
        $stmt->execute([$kills, $placement, $penalty, $total, $match_id, $team_name]);
    }

    // Set match to inactive (Finalized)
    $pdo->prepare("UPDATE matches SET is_active = 0 WHERE id = ?")->execute([$match_id]);
    
    echo "<h1>MATCH FINALIZED SUCCESSFULLY</h1><a href='post_match.php'>Go Back</a>";
}
?>
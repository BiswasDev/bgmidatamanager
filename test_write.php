<?php
// test_write.php - Surgical Database Diagnostic
ini_set('display_errors', 1);
error_reporting(E_ALL);

require 'db.php'; // Ensure this points to your actual connection file

echo "<h1>NexusForge DB Diagnostic</h1>";

try {
    echo "1. Testing Database Connection... <strong>OK</strong><br>";

    // Find the active match
    $stmt = $pdo->query("SELECT id FROM matches WHERE is_active = 1 ORDER BY id DESC LIMIT 1");
    $match = $stmt->fetch();

    if (!$match) {
        die("<h3 style='color:red;'>ERROR: No active match found in the 'matches' table!</h3>");
    }
    
    $match_id = $match['id'];
    echo "2. Found Active Match ID: <strong>" . $match_id . "</strong><br>";

    // Grab the first player we can find in this match
    $playerStmt = $pdo->prepare("SELECT ign, team_name, status FROM match_player_stats WHERE match_id = ? LIMIT 1");
    $playerStmt->execute([$match_id]);
    $player = $playerStmt->fetch();

    if (!$player) {
        die("<h3 style='color:red;'>ERROR: No players found for Match ID $match_id! Did you spawn the lobby correctly?</h3>");
    }

    $ign = $player['ign'];
    echo "3. Target Player Found: <strong>" . $ign . "</strong> (Current Status: " . $player['status'] . ")<br>";

    // Attempt the exact same UPDATE query your dashboard uses
    echo "4. Attempting to force status to 'Dead'...<br>";
    
    $updateStmt = $pdo->prepare("UPDATE match_player_stats SET status = 'Dead' WHERE match_id = ? AND ign = ?");
    $success = $updateStmt->execute([$match_id, $ign]);

    if ($success) {
        echo "<h3 style='color:green;'>SUCCESS! The database successfully updated $ign to 'Dead'.</h3>";
        echo "<p>If your dashboard still isn't working, the bug is in your dashboard's JavaScript payload, NOT the database.</p>";
    } else {
        echo "<h3 style='color:red;'>FAILED: The query executed, but nothing was changed.</h3>";
    }

} catch (PDOException $e) {
    echo "<h3 style='color:red;'>CRITICAL SQL ERROR:</h3>";
    echo "<pre>" . $e->getMessage() . "</pre>";
    echo "<p>This is exactly why your dashboard is failing. Read the error above.</p>";
}
?>
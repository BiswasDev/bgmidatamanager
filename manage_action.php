<?php
require 'db.php';
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $a = $_POST['action'] ?? ''; $tid = $_POST['tournament_id'] ?? '';
    try {
        if ($a === 'edit_team') $pdo->prepare("UPDATE tournament_teams SET team_name=?, group_name=? WHERE id=?")->execute([$_POST['team_name'], $_POST['group_name'], $_POST['team_id']]);
        elseif ($a === 'delete_team') $pdo->prepare("DELETE FROM tournament_teams WHERE id=?")->execute([$_POST['team_id']]);
        elseif ($a === 'edit_player') $pdo->prepare("UPDATE tournament_players SET ign=?, bgmi_id=?, alt_bgmi_id=? WHERE id=?")->execute([$_POST['ign'], $_POST['bgmi_id'], !empty($_POST['alt_bgmi_id'])?$_POST['alt_bgmi_id']:NULL, $_POST['player_id']]);
        elseif ($a === 'delete_player') $pdo->prepare("DELETE FROM tournament_players WHERE id=?")->execute([$_POST['player_id']]);
        header("Location: manage.php?tournament_id=" . urlencode($tid)); exit;
    } catch (\PDOException $e) { die("Error: " . $e->getMessage()); }
}
?>
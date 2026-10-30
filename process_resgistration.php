<?php
require 'db.php';
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $tid = $_POST['tournament_id'];
    $tname = htmlspecialchars($_POST['team_name']);
    $logo = 'assets/default_logo.png';
    
    if (!is_dir('assets/logos/')) mkdir('assets/logos/', 0755, true);
    if (!is_dir('assets/players/')) mkdir('assets/players/', 0755, true);

    $clean_tname = preg_replace('/[^a-zA-Z0-9]/', '_', strtolower($tname));

    if (isset($_FILES['team_logo']) && $_FILES['team_logo']['error'] === UPLOAD_ERR_OK) {
        $target = 'assets/logos/' . $clean_tname . '_' . time() . '.png';
        if (move_uploaded_file($_FILES['team_logo']['tmp_name'], $target)) $logo = $target;
    }

    $pdo->beginTransaction();
    $stmt = $pdo->prepare("INSERT INTO tournament_teams (tournament_id, team_name, logo_path) VALUES (?, ?, ?)");
    $stmt->execute([$tid, $tname, $logo]);
    $team_id = $pdo->lastInsertId();

    $igns = $_POST['ign']; $uids = $_POST['bgmi_id']; $alts = $_POST['alt_bgmi_id'];
    $pStmt = $pdo->prepare("INSERT INTO tournament_players (team_id, ign, bgmi_id, alt_bgmi_id, player_image_path) VALUES (?, ?, ?, ?, ?)");
    
    for ($i = 0; $i < 6; $i++) {
        if (!empty($igns[$i])) {
            $p_img = 'assets/default_player.png';
            if (isset($_FILES['player_image']['name'][$i]) && $_FILES['player_image']['error'][$i] === UPLOAD_ERR_OK) {
                if ($_FILES['player_image']['size'][$i] <= 2000000) {
                    $p_target = 'assets/players/' . $clean_tname . '_' . time() . "_{$i}.png";
                    if (move_uploaded_file($_FILES['player_image']['tmp_name'][$i], $p_target)) $p_img = $p_target;
                }
            }
            $pStmt->execute([$team_id, htmlspecialchars($igns[$i]), htmlspecialchars($uids[$i]), !empty($alts[$i])?htmlspecialchars($alts[$i]):NULL, $p_img]);
        }
    }
    $pdo->commit();
    echo "<h2 style='color:green; font-family:monospace; text-align:center; padding: 50px;'>Registration Complete!</h2>";
}
?>
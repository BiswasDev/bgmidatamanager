<?php
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json; charset=utf-8');
require 'db.php';

try {
    // Determine Base URL for Image Paths
    $base_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]" . dirname($_SERVER['PHP_SELF']) . "/";

    // Helper Function for File Matching
    function getImagePath($base_dir, $file_name, $base_url) {
        $clean_name = trim($file_name);
        if (empty($clean_name)) return $base_url . $base_dir . '/default.png';
        $name_variations = [ $clean_name, strtolower($clean_name), strtoupper($clean_name) ];
        $extensions = ['.png', '.jpg', '.jpeg', '.PNG', '.JPG', '.JPEG'];
        foreach ($name_variations as $name) {
            $physical_base = __DIR__ . '/' . $base_dir . '/' . $name;
            $url_base = $base_url . $base_dir . '/' . rawurlencode($name);
            foreach ($extensions as $ext) {
                if (file_exists($physical_base . $ext)) return $url_base . $ext; 
            }
        }
        return $base_url . $base_dir . '/default.png'; 
    }

    $action = $_GET['action'] ?? '';

    // ====================================================================
    // 1. ENDPOINT: DYNAMIC SCHEDULE OVERLAY DATA
    // ====================================================================
    if ($action === 'schedule_data') {
        try {
            $stmt = $pdo->query("SELECT id, match_no, map_name, group_name, status, scheduled_time FROM matches WHERE show_on_schedule = 1 ORDER BY match_no ASC LIMIT 10");
            $html_matches = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            $html_matches = $pdo->query("SELECT id, match_no, map_name, group_name, status, scheduled_time FROM matches ORDER BY match_no ASC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
        }

        $output_schedule = [];
        foreach ($html_matches as $m) {
            $match_data = [
                "match_no" => $m['match_no'],
                "map_name" => $m['map_name'],
                "group_name" => $m['group_name'] ?? 'UNKNOWN',
                "status" => strtolower(trim($m['status'])),
                "time" => !empty($m['scheduled_time']) ? date("H:i", strtotime($m['scheduled_time'])) : "TBD",
                "winner_name" => "",
                "winner_logo" => "",
                "winner_finishes" => "0"
            ];

            if ($match_data['status'] === 'finished') {
                $win_stmt = $pdo->prepare("SELECT team_name, team_tag, GREATEST(COALESCE(total_finishes, 0), COALESCE(total_kills, 0)) as finishes FROM match_team_stats WHERE match_id = ? AND elimination_rank = 1 LIMIT 1");
                $win_stmt->execute([$m['id']]);
                $win = $win_stmt->fetch(PDO::FETCH_ASSOC);

                if ($win) {
                    $match_data['winner_name'] = $win['team_name'];
                    $match_data['winner_finishes'] = (string)$win['finishes'];
                    $tag = !empty($win['team_tag']) ? $win['team_tag'] : $win['team_name'];
                    $match_data['winner_logo'] = getImagePath('logos', $tag, $base_url);
                }
            }
            $output_schedule[] = $match_data;
        }
        
        echo json_encode($output_schedule);
        exit;
    }

    // ====================================================================
    // 2. ENDPOINT: NEXT MATCH TEAMS PLAYING (NO LIMIT - SUPPORTS 16 to 25+ TEAMS)
    // ====================================================================
    if ($action === 'next_match_teams') {
        $next_stmt = $pdo->query("SELECT id, group_name FROM matches WHERE status = 'scheduled' ORDER BY match_no ASC LIMIT 1");
        $next_m = $next_stmt->fetch(PDO::FETCH_ASSOC);

        $output_teams = [];
        if ($next_m) {
            // Fetches all participating teams for the upcoming match without hard limits
            $team_stmt = $pdo->prepare("SELECT team_name, team_tag FROM match_team_stats WHERE match_id = ? ORDER BY id ASC");
            $team_stmt->execute([$next_m['id']]);
            $teams = $team_stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($teams as $t) {
                $tag = !empty($t['team_tag']) ? $t['team_tag'] : $t['team_name'];
                $output_teams[] = [
                    "team_name" => $t['team_name'],
                    "team_tag" => strtoupper($tag),
                    "logo" => getImagePath('logos', $tag, $base_url)
                ];
            }
        }
        
        echo json_encode($output_teams);
        exit;
    }

    // ====================================================================
    // 3. GLOBAL STANDINGS & MATCH ENGINE
    // ====================================================================
    $match = $pdo->query("SELECT id, status, wwcd_triggered, round_name, group_name, match_no, map_name FROM matches WHERE status IN ('active', 'finished', 'idle') ORDER BY is_active DESC, id DESC LIMIT 1")->fetch();
    $match_id = $match['id'] ?? 0;
    $match_status = $match['status'] ?? 'idle';
    $wwcd_triggered = $match['wwcd_triggered'] ?? 0;
    $current_round = $match['round_name'] ?? ''; 
    $current_group = $match['group_name'] ?? '';

    // Schedule Next Match Preview
    $next_stmt = $pdo->prepare("SELECT group_name, match_no, map_name, scheduled_time FROM matches WHERE id > ? AND status = 'scheduled' ORDER BY id ASC LIMIT 1");
    $next_stmt->execute([$match_id]);
    $next_match = $next_stmt->fetch(PDO::FETCH_ASSOC) ?: $pdo->query("SELECT group_name, match_no, map_name, scheduled_time FROM matches ORDER BY id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $next_map_name = $next_match ? $next_match['map_name'] : '';

    // Fetch Active Match Stats
    $stmt = $pdo->prepare("SELECT * FROM match_team_stats WHERE match_id = ?");
    $stmt->execute([$match_id]);
    $all_teams = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $p_stmt = $pdo->prepare("SELECT * FROM match_player_stats WHERE match_id = ?");
    $p_stmt->execute([$match_id]);
    $all_players = $p_stmt->fetchAll(PDO::FETCH_ASSOC);

    $is_group_match = (!empty($current_group) && !in_array(strtolower($current_group), ['overall', 'finals', 'grand finals']));

    // Historical Points
    $history_query = "SELECT t.team_name, SUM(t.total_points) as past_pts FROM match_team_stats t JOIN matches m ON t.match_id = m.id WHERE t.match_id != ? AND m.round_name = ? AND m.status = 'finished'";
    $history_params = [$match_id, $current_round];

    if ($is_group_match) {
        $history_query .= " AND m.group_name = ?";
        $history_params[] = $current_group;
    } else {
        $history_query .= " AND (m.group_name IS NULL OR m.group_name = '' OR LOWER(m.group_name) IN ('overall', 'finals', 'grand finals'))";
    }
    $history_query .= " GROUP BY t.team_name";
    
    $history_stmt = $pdo->prepare($history_query);
    $history_stmt->execute($history_params);
    $past_points = $history_stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    // Overall Standings
    $overall_stmt = $pdo->prepare("
        SELECT t.team_name, MAX(t.team_tag) as team_tag, 
               SUM(GREATEST(COALESCE(t.total_finishes, 0), COALESCE(t.total_kills, 0))) as total_finishes, 
               SUM(t.placement_points) as total_placement_points, 
               SUM(t.total_points) as overall_pts 
        FROM match_team_stats t JOIN matches m ON t.match_id = m.id
        WHERE m.round_name = ? AND m.status = 'finished' 
          AND (m.group_name IS NULL OR m.group_name = '' OR LOWER(m.group_name) IN ('overall', 'finals', 'grand finals'))
        GROUP BY t.team_name 
        ORDER BY overall_pts DESC, total_placement_points DESC, total_finishes DESC, t.team_name ASC
    ");
    $overall_stmt->execute([$current_round]);
    $overall_standings = $overall_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Standard Schedule List
    $sched_stmt = $pdo->query("SELECT id, match_no, round_name, group_name, map_name, scheduled_time, status FROM matches WHERE status != 'finished' ORDER BY id ASC LIMIT 10");
    $raw_schedule = $sched_stmt->fetchAll(PDO::FETCH_ASSOC);
    $final_schedule = [];
    
    $settings_stmt = $pdo->query("SELECT active_group_standings FROM broadcast_settings WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
    $live_group = $settings_stmt['active_group_standings'] ?? '';

    foreach($raw_schedule as $s) {
        $winner_name = "";
        $winner_logo = "";
        
        if ($s['status'] === 'finished') {
            $win_stmt = $pdo->prepare("SELECT team_name, team_tag FROM match_team_stats WHERE match_id = ? AND elimination_rank = 1 LIMIT 1");
            $win_stmt->execute([$s['id']]);
            $win_data = $win_stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($win_data) {
                $winner_name = $win_data['team_name']; 
                $winner_logo = getImagePath('logos', $win_data['team_tag'] ?: $win_data['team_name'], $base_url);
            }
        }

        $display_sched_group = strtoupper($s['group_name']);
        if (strpos($display_sched_group, ' - ') !== false) {
            $parts = explode(' - ', $display_sched_group);
            $display_sched_group = trim(end($parts));
        }
        
        $final_schedule[] = [
            "match_no" => $s['match_no'], "round" => $s['round_name'], "group" => $display_sched_group,
            "map" => $s['map_name'], "map_image" => getImagePath('maps', $s['map_name'], $base_url),
            "time" => date("H:i", strtotime($s['scheduled_time'] ?? '00:00')),
            "status" => $s['status'], "winner_name" => $winner_name, "winner_logo" => $winner_logo
        ];
    }

    $display_current_group = strtoupper($match['group_name'] ?? '');
    if (strpos($display_current_group, ' - ') !== false) {
        $parts = explode(' - ', $display_current_group);
        $display_current_group = trim(end($parts));
    }

    $display_next_group = strtoupper($next_match ? $next_match['group_name'] : '');
    if (strpos($display_next_group, ' - ') !== false) {
        $parts = explode(' - ', $display_next_group);
        $display_next_group = trim(end($parts));
    }

    $output = [
        "match_info" => [
            "round" => $match['round_name'] ?? '', 
            "group" => $display_current_group,
            "match_no" => $match['match_no'] ?? '', "map" => $match['map_name'] ?? '',
            "next_match_no" => $next_match ? $next_match['match_no'] : '',
            "next_group" => $display_next_group,
            "next_map" => $next_map_name, "next_map_image" => getImagePath('maps', $next_map_name, $base_url),
            "next_time" => $next_match ? date("H:i", strtotime($next_match['scheduled_time'])) : ''
        ],
        "schedule" => $final_schedule, 
        "match_standings" => [], "overall_standings" => [], 
        "group_standings" => [], "wwcd_winner" => [],
        "match_top_fraggers" => [], "overall_top_fraggers" => []
    ];

    // Live Match Standings
    $slot_mapping = [];
    if (!empty($current_group)) {
        $group_lookup_stmt = $pdo->prepare("SELECT teams_list FROM tournament_groups WHERE LOWER(group_name) = LOWER(?) LIMIT 1");
        $group_lookup_stmt->execute([$current_group]);
        $group_row = $group_lookup_stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($group_row && !empty($group_row['teams_list'])) {
            $slot_index = 3; 
            foreach (explode("\n", str_replace("\r", "", $group_row['teams_list'])) as $line) {
                $parts = explode('|', trim($line));
                $t_name = trim($parts[0]);
                if (!empty($t_name)) {
                    $slot_mapping[strtolower($t_name)] = $slot_index;
                    $slot_index++;
                }
            }
        }
    }

    $alive_teams = [];
    $fallback_slot_counter = 3;

    foreach ($all_teams as $t) {
        $team_name = $t['team_name'];
        $team_tag = !empty($t['team_tag']) ? $t['team_tag'] : $team_name;
        $team_players = array_filter($all_players, fn($p) => $p['team_name'] === $team_name);
        
        $actual_finishes = max((int)($t['total_finishes'] ?? 0), (int)($t['total_kills'] ?? 0));
        $placement_points = (int)$t['placement_points'];
        $current_pts = $actual_finishes + $placement_points; 
        $historical_pts = isset($past_points[$team_name]) ? (int)$past_points[$team_name] : 0;
        
        $lower_name = strtolower($team_name);
        $assigned_slot = $slot_mapping[$lower_name] ?? $fallback_slot_counter;

        $team_data = [
            "slot" => (string)$assigned_slot, 
            "team_name" => $team_name,
            "team_tag" => $team_tag, 
            "logo" => getImagePath('logos', $team_tag, $base_url),
            "finishes" => $actual_finishes, 
            "placement_points" => $placement_points,
            "match_points" => $current_pts, 
            "total_points" => $historical_pts + $current_pts,
            "is_alive" => ($t['is_eliminated'] == 0), 
            "players" => array_values(array_map(fn($p) => ["ign" => $p['ign'], "finishes" => (int)$p['finishes'], "status" => $p['status']], $team_players))
        ];

        $output['match_standings'][] = $team_data;
        if ($t['is_eliminated'] == 0) $alive_teams[] = $team_data;
        $fallback_slot_counter++;
    }

    usort($output['match_standings'], function($a, $b) {
        if ($b['total_points'] !== $a['total_points']) return $b['total_points'] <=> $a['total_points'];
        if ($b['placement_points'] !== $a['placement_points']) return $b['placement_points'] <=> $a['placement_points'];
        if ($b['finishes'] !== $a['finishes']) return $b['finishes'] <=> $a['finishes'];
        return strcmp($a['team_name'], $b['team_name']); 
    });

    if ($wwcd_triggered == 1 || $match_status === 'finished') {
        $winner = count($alive_teams) === 1 ? reset($alive_teams) : ($output['match_standings'][0] ?? []);
        $output['wwcd_winner'] = [$winner]; 
    }

    // Top Fraggers Formatting Helper
    function format_fragger($row, $base_url, $is_aggregate = false) {
        $finishes = $is_aggregate ? (int)$row['total_finishes'] : (int)$row['finishes'];
        $team_finishes = $is_aggregate ? (int)$row['team_total_finishes'] : (int)$row['team_finishes'];
        
        $contrib = $team_finishes > 0 ? round(($finishes / $team_finishes) * 100) . '%' : '0%';
        $survival_secs = $is_aggregate ? (int)$row['total_survival'] : (int)$row['survival_time'];
        
        $m = floor($survival_secs / 60);
        $s = $survival_secs % 60;
        $survival_str = sprintf("%02d:%02d", $m, $s);

        $tag = !empty($row['team_tag']) ? $row['team_tag'] : $row['team_name'];

        return [
            "ign" => $row['ign'],
            "team_name" => $row['team_name'],
            "finishes" => $finishes,
            "contribution" => $contrib,
            "survival_time" => $survival_str,
            "player_image" => getImagePath('players', $row['ign'], $base_url),
            "team_logo" => getImagePath('logos', $tag, $base_url)
        ];
    }

    // Match Top Fraggers
    $match_frag_stmt = $pdo->prepare("
        SELECT p.ign, p.team_name, p.finishes, p.survival_time,
               t.team_tag, GREATEST(COALESCE(t.total_finishes, 0), COALESCE(t.total_kills, 0)) as team_finishes
        FROM match_player_stats p
        LEFT JOIN match_team_stats t ON p.match_id = t.match_id AND p.team_name = t.team_name
        WHERE p.match_id = ?
        ORDER BY p.finishes DESC, p.survival_time DESC
        LIMIT 5
    ");
    $match_frag_stmt->execute([$match_id]);
    while ($row = $match_frag_stmt->fetch(PDO::FETCH_ASSOC)) {
        $output['match_top_fraggers'][] = format_fragger($row, $base_url, false);
    }

    // Overall Top Fraggers
    $overall_frag_stmt = $pdo->prepare("
        SELECT p.ign, p.team_name, 
               SUM(p.finishes) as total_finishes, SUM(p.survival_time) as total_survival, MAX(t.team_tag) as team_tag,
               (SELECT SUM(GREATEST(COALESCE(mts.total_finishes, 0), COALESCE(mts.total_kills, 0))) 
                FROM match_team_stats mts JOIN matches m2 ON mts.match_id = m2.id 
                WHERE mts.team_name = p.team_name AND m2.round_name = ? AND m2.status = 'finished'
                  AND (m2.group_name IS NULL OR m2.group_name = '' OR LOWER(m2.group_name) IN ('overall', 'finals', 'grand finals'))
               ) as team_total_finishes
        FROM match_player_stats p
        JOIN matches m ON p.match_id = m.id
        LEFT JOIN match_team_stats t ON p.match_id = t.match_id AND p.team_name = t.team_name
        WHERE m.round_name = ? AND m.status = 'finished'
          AND (m.group_name IS NULL OR m.group_name = '' OR LOWER(m.group_name) IN ('overall', 'finals', 'grand finals'))
        GROUP BY p.ign, p.team_name
        ORDER BY total_finishes DESC, total_survival DESC
        LIMIT 5
    ");
    $overall_frag_stmt->execute([$current_round, $current_round]);
    while ($row = $overall_frag_stmt->fetch(PDO::FETCH_ASSOC)) {
        $output['overall_top_fraggers'][] = format_fragger($row, $base_url, true);
    }

    // Standings Column Helper
    function format_standings_columns($standings_array) {
        $c1_names = []; $c1_fin = []; $c1_place = []; $c1_total = [];
        $c2_names = []; $c2_fin = []; $c2_place = []; $c2_total = [];
        $count = 0;
        $midpoint = ceil(count($standings_array) / 2);
        
        foreach ($standings_array as $ot) {
            $name = $ot['team_name'] ?? $ot['name'];
            $fin = (int)($ot['total_finishes'] ?? $ot['finishes']);
            $plc = (int)($ot['total_placement_points'] ?? $ot['placement']);
            $tot = (int)($ot['overall_pts'] ?? $ot['totals']);

            if ($count < $midpoint) {
                $c1_names[] = $name; $c1_fin[] = $fin; $c1_place[] = $plc; $c1_total[] = $tot;
            } else {
                $c2_names[] = $name; $c2_fin[] = $fin; $c2_place[] = $plc; $c2_total[] = $tot;
            }
            $count++;
        }
        return [
            "team_names_1_8" => implode("\r\n", $c1_names), "finishes_1_8" => implode("\r\n", $c1_fin),
            "placement_1_8" => implode("\r\n", $c1_place), "totals_1_8" => implode("\r\n", $c1_total),
            "team_names_9_16" => implode("\r\n", $c2_names), "finishes_9_16" => implode("\r\n", $c2_fin),
            "placement_9_16" => implode("\r\n", $c2_place), "totals_9_16" => implode("\r\n", $c2_total)
        ];
    }

    $output['overall_standings'] = [format_standings_columns($overall_standings)];

    // Group Standings Isolation
    $groups_stmt = $pdo->query("SELECT group_name, teams_list FROM tournament_groups");
    $all_dashboard_groups = $groups_stmt->fetchAll(PDO::FETCH_ASSOC);

    $sorted_groups = [];
    foreach ($all_dashboard_groups as $g) {
        $g_name = $g['group_name'];
        $team_names = [];
        
        foreach (explode("\n", str_replace("\r", "", $g['teams_list'])) as $line) {
            $parts = explode('|', trim($line));
            if (!empty(trim($parts[0]))) {
                $team_names[] = trim($parts[0]);
            }
        }
        
        if (empty($team_names)) continue;

        $g_stmt = $pdo->prepare("
            SELECT t.team_name, MAX(t.team_tag) as team_tag, 
                   SUM(GREATEST(COALESCE(t.total_finishes, 0), COALESCE(t.total_kills, 0))) as total_finishes, 
                   SUM(t.placement_points) as total_placement_points, 
                   SUM(t.total_points) as overall_pts 
            FROM match_team_stats t 
            JOIN matches m ON t.match_id = m.id
            WHERE m.round_name = ? AND m.status = 'finished' AND m.group_name = ?
            GROUP BY t.team_name
        ");
        $g_stmt->execute([$current_round, $g_name]);
        $team_stats = $g_stmt->fetchAll(PDO::FETCH_ASSOC);

        $stats_by_name = [];
        foreach($team_stats as $ts) {
            $stats_by_name[strtolower($ts['team_name'])] = $ts;
        }

        $final_group_teams = [];
        foreach($team_names as $tn) {
            $lower_tn = strtolower($tn);
            if(isset($stats_by_name[$lower_tn])) {
                $ts = $stats_by_name[$lower_tn];
                $ts['team_name'] = $tn; 
                $final_group_teams[] = $ts;
            } else {
                $final_group_teams[] = [
                    'team_name' => $tn,
                    'total_finishes' => 0,
                    'total_placement_points' => 0,
                    'overall_pts' => 0
                ];
            }
        }

        usort($final_group_teams, function($a, $b) {
            if ($b['overall_pts'] !== $a['overall_pts']) return $b['overall_pts'] <=> $a['overall_pts'];
            if ($b['total_placement_points'] !== $a['total_placement_points']) return $b['total_placement_points'] <=> $a['total_placement_points'];
            if ($b['total_finishes'] !== $a['total_finishes']) return $b['total_finishes'] <=> $a['total_finishes'];
            return strcmp($a['team_name'], $b['team_name']); 
        });

        $bucket = format_standings_columns($final_group_teams);
        
        $display_group_name = strtoupper($g_name);
        if (strpos($display_group_name, ' - ') !== false) {
            $parts = explode(' - ', $display_group_name);
            $display_group_name = trim(end($parts));
        }
        $bucket['group_name'] = $display_group_name;

        // Group Top Fraggers
        $group_frag_stmt = $pdo->prepare("
            SELECT p.ign, p.team_name, 
                   SUM(p.finishes) as total_finishes, SUM(p.survival_time) as total_survival, MAX(t.team_tag) as team_tag,
                   (SELECT SUM(GREATEST(COALESCE(mts.total_finishes, 0), COALESCE(mts.total_kills, 0))) 
                    FROM match_team_stats mts JOIN matches m2 ON mts.match_id = m2.id 
                    WHERE mts.team_name = p.team_name AND m2.round_name = ? AND m2.group_name = ? AND m2.status = 'finished'
                   ) as team_total_finishes
            FROM match_player_stats p
            JOIN matches m ON p.match_id = m.id
            LEFT JOIN match_team_stats t ON p.match_id = t.match_id AND p.team_name = t.team_name
            WHERE m.round_name = ? AND m.group_name = ? AND m.status = 'finished'
            GROUP BY p.ign, p.team_name
            ORDER BY total_finishes DESC, total_survival DESC
            LIMIT 5
        ");
        $group_frag_stmt->execute([$current_round, $g_name, $current_round, $g_name]);
        $g_fraggers = [];
        while ($row = $group_frag_stmt->fetch(PDO::FETCH_ASSOC)) {
            $g_fraggers[] = format_fragger($row, $base_url, true);
        }
        $bucket['top_fraggers'] = $g_fraggers;

        if (strtolower($g_name) === strtolower($live_group)) {
            array_unshift($sorted_groups, $bucket);
        } else {
            $sorted_groups[] = $bucket;
        }
    }
    
    $output['group_standings'] = $sorted_groups;

    // Response Routing
    $requested_table = $_GET['table'] ?? 'all';
    if ($requested_table === 'match_standings') echo json_encode($output['match_standings']);
    elseif ($requested_table === 'overall_standings') echo json_encode($output['overall_standings']);
    elseif ($requested_table === 'group_standings') echo json_encode($output['group_standings']); 
    elseif ($requested_table === 'wwcd_winner') echo json_encode($output['wwcd_winner']);
    elseif ($requested_table === 'match_top_fraggers') echo json_encode($output['match_top_fraggers']);
    elseif ($requested_table === 'overall_top_fraggers') echo json_encode($output['overall_top_fraggers']);
    elseif ($requested_table === 'match_info') echo json_encode([$output['match_info']]);
    elseif ($requested_table === 'schedule') echo json_encode($output['schedule']); 
    else echo json_encode($output);

} catch (Exception $e) { 
    echo json_encode(["error" => $e->getMessage()]); 
}
?>
<?php
// ==========================================
// NEXUSFORGE DASHBOARD - ROSTER SYNC MODULE
// ==========================================

$status = null;
$message = "";

// Database Configuration
$db_host = '127.0.0.1';
$db_name = 'nexusforge_bgmi';
$db_user = 'root';
$db_pass = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['gemini_roster_data'])) {
    $raw_json = trim($_POST['gemini_roster_data']);
    $rosters = json_decode($raw_json, true);

    if (json_last_error() === JSON_ERROR_NONE && is_array($rosters)) {
        try {
            $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // 1. Find the currently active match
            $match_stmt = $pdo->query("SELECT id FROM matches WHERE status = 'active' ORDER BY id DESC LIMIT 1");
            $active_match_id = $match_stmt->fetchColumn();

            if (!$active_match_id) {
                throw new Exception("No active match found in the database. Start a match first.");
            }

            // 2. Fetch all team-player slots grouped sequentially for this active match
            $stmt = $pdo->prepare("SELECT id, team_name FROM match_player_stats WHERE match_id = :match_id ORDER BY id ASC");
            $stmt->execute([':match_id' => $active_match_id]);
            $all_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($all_rows)) {
                throw new Exception("No player stat slots found for Match #$active_match_id. Initialize match slots first.");
            }

            // Group rows into chunks of 4 players per team block
            $team_blocks = array_chunk($all_rows, 4);
            $update_count = 0;
            $team_count = 0;

            // 3. Loop through the pasted JSON and match sequentially by index order
            foreach ($rosters as $index => $team_data) {
                if (!isset($team_blocks[$index])) {
                    continue; // Skip if index exceeds database team blocks
                }

                $players = $team_data['players'] ?? [];
                $target_rows = $team_blocks[$index]; // The 4 database rows for this team slot

                $team_count++;
                foreach ($target_rows as $p_idx => $db_row) {
                    if (isset($players[$p_idx])) {
                        $new_ign = trim($players[$p_idx]);
                        
                        // Execute raw update preserving exact characters/tags
                        $update_stmt = $pdo->prepare("UPDATE match_player_stats SET ign = :ign WHERE id = :id");
                        $update_stmt->execute([
                            ':ign' => $new_ign,
                            ':id' => $db_row['id']
                        ]);
                        $update_count++;
                    }
                }
            }

            $status = "success";
            $message = "SYNC COMPLETE: Successfully updated $update_count player IGNs across $team_count teams for Match #$active_match_id.";

        } catch (PDOException $e) {
            $status = "error";
            $message = "DATABASE ERROR: " . $e->getMessage();
        } catch (Exception $e) {
            $status = "warning";
            $message = "SYNC ABORTED: " . $e->getMessage();
        }
    } else {
        $status = "error";
        $message = "PARSE ERROR: Invalid JSON format. Ensure Gemini output is strictly formatted.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Roster Sync | NexusForge</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { background-color: #050505; color: #f3f4f6; }
        .brutal-border { border: 2px solid #3b0764; box-shadow: 4px 4px 0px #7e22ce; transition: all 0.2s ease; }
        .brutal-border:focus-within { box-shadow: 6px 6px 0px #a855f7; transform: translate(-2px, -2px); }
        .brutal-btn { border: 2px solid #a855f7; background-color: #1a1a1a; box-shadow: 4px 4px 0px #7e22ce; }
        .brutal-btn:hover { background-color: #7e22ce; color: #fff; box-shadow: 2px 2px 0px #9333ea; transform: translate(2px, 2px); }
    </style>
</head>
<body class="min-h-screen p-8 font-mono">

    <div class="max-w-4xl mx-auto">
        <header class="mb-10 border-b-2 border-purple-900 pb-4">
            <h1 class="text-4xl font-black tracking-tighter text-white uppercase flex items-center gap-3">
                <span class="text-purple-600">NEXUSFORGE</span> // Roster Sync
            </h1>
            <p class="text-gray-400 mt-2 text-sm uppercase tracking-widest">Live Player IGN Injection Module</p>
        </header>

        <?php if ($status === 'success'): ?>
            <div class="mb-8 p-4 border-l-4 border-green-500 bg-green-900/20 text-green-400 font-bold uppercase">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php elseif ($status === 'error'): ?>
            <div class="mb-8 p-4 border-l-4 border-red-500 bg-red-900/20 text-red-400 font-bold uppercase">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php elseif ($status === 'warning'): ?>
            <div class="mb-8 p-4 border-l-4 border-yellow-500 bg-yellow-900/20 text-yellow-400 font-bold uppercase">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
            <div class="lg:col-span-1 space-y-6">
                <div class="bg-zinc-900 p-6 border border-zinc-800">
                    <h2 class="text-purple-500 font-bold mb-4 uppercase tracking-wider">Protocol</h2>
                    <ol class="list-decimal list-inside text-sm text-gray-400 space-y-3">
                        <li>Start the match in your backend/broadcast software (sets status to 'active').</li>
                        <li>Capture lobby screenshot.</li>
                        <li>Pass to Gemini using the exact-name prompt.</li>
                        <li>Paste output into the data terminal and execute.</li>
                    </ol>
                </div>
            </div>

            <div class="lg:col-span-2">
                <form method="POST" action="" class="space-y-6">
                    <div class="brutal-border bg-black">
                        <div class="bg-zinc-900 border-b border-zinc-800 p-2 px-4 flex justify-between items-center">
                            <span class="text-xs text-purple-500 font-bold tracking-widest">DATA TERMINAL</span>
                            <span class="text-xs text-zinc-500">JSON ONLY</span>
                        </div>
                        <textarea 
                            name="gemini_roster_data" 
                            rows="12" 
                            class="w-full bg-black text-green-400 p-4 font-mono text-sm focus:outline-none resize-y"
                            placeholder="Paste parsed JSON array here..."
                            required
                        ></textarea>
                    </div>

                    <div class="flex justify-end">
                        <button type="submit" class="brutal-btn text-purple-400 font-bold uppercase tracking-widest px-8 py-4 transition-all duration-150">
                            Execute IGN Overwrite
                        </button>
                    </div>
                </form>
            </div>
            
        </div>
    </div>

</body>
</html>
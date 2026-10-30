<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>NexusForge | Fallback Match Sync</title>
    <style>
        body { background: #0b0b0f; color: #fff; font-family: monospace; padding: 20px; text-transform: uppercase; }
        .container { max-width: 800px; margin: 0 auto; background: #1a1a24; padding: 20px; border: 2px solid #8b5cf6; }
        h2 { color: #8b5cf6; margin-top: 0; }
        label { display: block; margin-top: 15px; color: #aaa; }
        input[type="number"], textarea { 
            width: 100%; box-sizing: border-box; background: #000; color: #10b981; 
            border: 1px solid #444; padding: 10px; font-family: monospace; margin-top: 5px;
        }
        textarea { height: 300px; resize: vertical; }
        button { 
            margin-top: 20px; padding: 10px 20px; background: #3b82f6; color: #fff; 
            border: none; font-weight: bold; cursor: pointer; font-family: monospace; font-size: 1.1rem;
        }
        button:hover { background: #2563eb; }
        #response-box { margin-top: 20px; padding: 10px; border: 1px solid #444; display: none; }
        .success { border-color: #10b981 !important; color: #10b981; }
        .error { border-color: #ef4444 !important; color: #ef4444; }
    </style>
</head>
<body>

<div class="container">
    <h2>⚠️ Fallback Match Sync</h2>
    <p style="color:#aaa; font-size:0.9rem; text-transform:none;">
        Use this tool if the live data tracker failed. Paste the final match results as JSON. The system will calculate placement points, sum up kills, and close the match automatically.
    </p>

    <label>Target Match ID:</label>
    <input type="number" id="match_id" placeholder="e.g. 5" required>

    <label>Raw JSON Data (Teams & Players):</label>
    <textarea id="json_payload">{
  "teams": [
    {
      "rank": 1,
      "team_name": "NexusForge",
      "players": [
        {"ign": "NXF_Subham", "kills": 4},
        {"ign": "NXF_Arnab", "kills": 2}
      ]
    },
    {
      "rank": 2,
      "team_name": "BIZElevate",
      "players": [
        {"ign": "BIZ_Uttara", "kills": 1}
      ]
    }
  ]
}</textarea>

    <button onclick="syncData()">FORCE SYNC MATCH</button>

    <div id="response-box"></div>
</div>

<script>
    function syncData() {
        const matchId = document.getElementById('match_id').value;
        const jsonText = document.getElementById('json_payload').value;
        const resBox = document.getElementById('response-box');

        if (!matchId) {
            alert("Please enter a Match ID.");
            return;
        }

        let parsedData;
        try {
            parsedData = JSON.parse(jsonText);
            parsedData.match_id = parseInt(matchId); // Inject match ID into the payload
        } catch (e) {
            resBox.style.display = "block";
            resBox.className = "error";
            resBox.innerText = "❌ Invalid JSON Format. Please check your brackets and quotes.";
            return;
        }

        resBox.style.display = "block";
        resBox.className = "";
        resBox.innerText = "⏳ Syncing to database...";

        fetch('fallback_sync.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(parsedData)
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                resBox.className = "success";
                resBox.innerText = "✅ " + data.msg;
            } else {
                resBox.className = "error";
                resBox.innerText = "❌ ERROR: " + data.error;
            }
        })
        .catch(err => {
            resBox.className = "error";
            resBox.innerText = "❌ FATAL ERROR: Could not connect to API.";
        });
    }
</script>

</body>
</html>
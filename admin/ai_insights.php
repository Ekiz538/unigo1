<?php
require_once __DIR__ . '/../config/config.php';
require_role(['admin','operator']);
$pageTitle = 'AI insights';

// "Run the model": pull recent average speed per route and derive a
// congestion score, then persist it to ai_insights (Section 11.1: data
// layer -> model -> decision layer -> feedback loop).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'run_forecast') {
    verify_csrf();

    $routes = $pdo->query('SELECT id, route_name FROM routes')->fetchAll();
    $computed = 0;
    $skipped  = [];
    foreach ($routes as $r) {
        $speedStmt = $pdo->prepare(
            "SELECT AVG(g.speed_kmh) FROM gps_pings g
             JOIN trips t ON t.id = g.trip_id
             WHERE t.route_id = ? AND g.recorded_at >= (NOW() - INTERVAL 2 HOUR)"
        );
        $speedStmt->execute([$r['id']]);
        $avgSpeed = $speedStmt->fetchColumn();

        // Never fabricate a prediction. A route with no observations in the
        // window is reported as insufficient rather than scored (defect D-03).
        if ($avgSpeed === null) {
            $skipped[] = $r['route_name'];
            continue;
        }

        $result = estimate_congestion_score((float) $avgSpeed);
        $ins = $pdo->prepare('INSERT INTO ai_insights (route_id, insight_type, score, summary) VALUES (?,?,?,?)');
        $ins->execute([$r['id'], 'congestion_forecast', $result['score'], $result['summary']]);
        $computed++;
    }

    if ($computed === 0) {
        flash('error', 'Forecast not generated: no GPS observations in the last 2 hours. Report positions before running the model.');
    } elseif ($skipped) {
        flash('success', "Forecast run complete for $computed route(s). Insufficient data (skipped): " . implode(', ', $skipped) . '.');
    } else {
        flash('success', "Forecast run complete for $computed route(s).");
    }
    redirect('/admin/ai_insights.php');
}

$insights = $pdo->query(
    "SELECT ai.*, r.route_name FROM ai_insights ai
     JOIN routes r ON r.id = ai.route_id
     ORDER BY ai.generated_at DESC LIMIT 20"
)->fetchAll();

require __DIR__ . '/../includes/header.php';
?>
<h2>AI insight layer</h2>
<p class="hint">Prediction (congestion &amp; demand), the first and highest-impact AI role in the case study.
   This demo model averages recent GPS speed pings per route against a free-flow baseline; a production
   version would train on historical time-series data.</p>

<form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="run_forecast">
    <button type="submit" class="cta">Run congestion forecast now</button>
</form>

<table class="data-table">
    <thead><tr><th>Route</th><th>Type</th><th>Score</th><th>Summary</th><th>Generated</th></tr></thead>
    <tbody>
    <?php foreach ($insights as $i): ?>
        <tr>
            <td><?= clean($i['route_name']) ?></td>
            <td><?= clean($i['insight_type']) ?></td>
            <td><?= number_format($i['score'],1) ?></td>
            <td><?= clean($i['summary']) ?></td>
            <td><?= clean($i['generated_at']) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php require __DIR__ . '/../includes/footer.php'; ?>

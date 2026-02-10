<?php
declare(strict_types=1);

date_default_timezone_set('UTC');
@ini_set('max_execution_time', '300');
@set_time_limit(300);

function safe_str(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function imap_count_by_criteria($connection, string $criteria): int
{
    $ids = imap_search($connection, $criteria, SE_UID);
    if ($ids === false) {
        return 0;
    }
    return count($ids);
}

function open_imap_with_fallback(
    string $server,
    int $port,
    string $security,
    string $mailboxName,
    string $username,
    string $password
) {
    $tryMailboxes = [];
    $add = static function (array &$list, string $mailbox, bool $insecure = false): void {
        foreach ($list as $item) {
            if ($item['mailbox'] === $mailbox) {
                return;
            }
        }
        $list[] = ['mailbox' => $mailbox, 'insecure' => $insecure];
    };

    $primaryFlags = '/imap' . ($security === 'ssl' ? '/ssl' : '/tls');
    $add($tryMailboxes, sprintf('{%s:%d%s}%s', $server, $port, $primaryFlags, $mailboxName));
    $add($tryMailboxes, sprintf('{%s:%d%s/novalidate-cert}%s', $server, $port, $primaryFlags, $mailboxName));

    // Cross-try the other secure mode because some hosts only work with one handshake style.
    $add($tryMailboxes, sprintf('{%s:143/imap/tls}%s', $server, $mailboxName));
    $add($tryMailboxes, sprintf('{%s:143/imap/tls/novalidate-cert}%s', $server, $mailboxName));
    $add($tryMailboxes, sprintf('{%s:993/imap/ssl}%s', $server, $mailboxName));
    $add($tryMailboxes, sprintf('{%s:993/imap/ssl/novalidate-cert}%s', $server, $mailboxName));

    // Last-resort insecure fallback.
    $add($tryMailboxes, sprintf('{%s:%d/imap/notls}%s', $server, $port, $mailboxName), true);
    $add($tryMailboxes, sprintf('{%s:143/imap/notls}%s', $server, $mailboxName), true);

    $lastError = null;
    foreach ($tryMailboxes as $candidate) {
        $connection = @imap_open($candidate['mailbox'], $username, $password, OP_SILENT, 1);
        if ($connection !== false) {
            return [
                'connection' => $connection,
                'mailbox' => $candidate['mailbox'],
                'insecure' => (bool)$candidate['insecure'],
            ];
        }
        $errs = imap_errors();
        if (is_array($errs) && $errs !== []) {
            $lastError = implode(' | ', $errs);
        } else {
            $lastError = imap_last_error() ?: $lastError;
        }
        imap_alerts();
    }

    return $lastError;
}

$errors = [];
$notices = [];
$result = null;
$didSubmit = ($_SERVER['REQUEST_METHOD'] === 'POST');
$utc = new DateTimeZone('UTC');
$defaultEnd = new DateTimeImmutable('today', $utc);
$defaultStart = $defaultEnd->sub(new DateInterval('P6D'));
$formStartDate = (string)($_POST['start_date'] ?? $defaultStart->format('Y-m-d'));
$formEndDate = (string)($_POST['end_date'] ?? $defaultEnd->format('Y-m-d'));

if ($didSubmit) {
    if (!function_exists('imap_open')) {
        $errors[] = 'PHP IMAP extension is not enabled.';
    } else {
        $server = trim((string)($_POST['server'] ?? ''));
        $port = (int)($_POST['port'] ?? 0);
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $security = strtolower(trim((string)($_POST['security'] ?? 'ssl')));
        $mailboxName = trim((string)($_POST['mailbox'] ?? 'INBOX'));
        $startDateRaw = trim((string)($_POST['start_date'] ?? ''));
        $endDateRaw = trim((string)($_POST['end_date'] ?? ''));

        if ($server === '') {
            $errors[] = 'Mail server is required.';
        }
        if ($port <= 0) {
            $errors[] = 'Connection port must be a positive number.';
        }
        if ($username === '') {
            $errors[] = 'Username is required.';
        }
        if ($password === '') {
            $errors[] = 'Password is required.';
        }
        if (!in_array($security, ['ssl', 'starttls'], true)) {
            $errors[] = 'Security must be SSL or STARTTLS.';
        }
        if ($mailboxName === '') {
            $mailboxName = 'INBOX';
        }

        $startDate = DateTimeImmutable::createFromFormat('Y-m-d', $startDateRaw, $utc);
        $endDate = DateTimeImmutable::createFromFormat('Y-m-d', $endDateRaw, $utc);
        if (!$startDate) {
            $errors[] = 'Start date is invalid.';
        }
        if (!$endDate) {
            $errors[] = 'End date is invalid.';
        }
        if ($startDate && $endDate && $startDate > $endDate) {
            $errors[] = 'Start date must be before or equal to end date.';
        }

        if (!$errors) {
            $openResult = open_imap_with_fallback($server, $port, $security, $mailboxName, $username, $password);
            if (!is_array($openResult)) {
                $hint = ' Check host/port/security. For SSL use port 993, for STARTTLS use port 143.';
                $errors[] = 'Connection failed: ' . ($openResult ?: 'Unknown IMAP error.') . $hint;
            } else {
                $connection = $openResult['connection'];
                if (!empty($openResult['insecure'])) {
                    $notices[] = 'Connected using plain IMAP (no TLS). This is insecure; use SSL or STARTTLS if possible.';
                } else {
                    $notices[] = 'Connected using ' . $openResult['mailbox'];
                }
                try {
                    $today = new DateTimeImmutable('today', $utc);
                    $windowStart = $startDate ?: $defaultStart;
                    $windowEnd = $endDate ?: $defaultEnd;
                    $daySpan = (int)$windowStart->diff($windowEnd)->format('%a') + 1;

                    if ($daySpan > 180) {
                        throw new RuntimeException('Date range too large. Please use 180 days or less.');
                    }

                    $dailyCounts = [];
                    for ($i = 0; $i < $daySpan; $i++) {
                        $day = $windowStart->add(new DateInterval('P' . $i . 'D'));
                        $dailyCounts[$day->format('Y-m-d')] = 0;
                    }

                    for ($i = 0; $i < $daySpan; $i++) {
                        $dayStart = $windowStart->add(new DateInterval('P' . $i . 'D'));
                        $dayEnd = $dayStart->add(new DateInterval('P1D'));
                        $criteria = sprintf(
                            'SINCE "%s" BEFORE "%s"',
                            $dayStart->format('d-M-Y'),
                            $dayEnd->format('d-M-Y')
                        );
                        $dailyCounts[$dayStart->format('Y-m-d')] = imap_count_by_criteria($connection, $criteria);
                    }

                    $total7Days = array_sum($dailyCounts);
                    $todayCount = imap_count_by_criteria(
                        $connection,
                        sprintf(
                            'SINCE "%s" BEFORE "%s"',
                            $today->format('d-M-Y'),
                            $today->add(new DateInterval('P1D'))->format('d-M-Y')
                        )
                    );
                    $now = new DateTimeImmutable('now', $utc);
                    $elapsed = $today->diff($now);
                    $minutesElapsedToday = ((int)$elapsed->format('%h') * 60) + (int)$elapsed->format('%i');
                    $minutesElapsedToday = max($minutesElapsedToday, 1);
                    $todayPerMinute = $todayCount / $minutesElapsedToday;

                    $result = [
                        'window_start' => $windowStart->format('Y-m-d'),
                        'window_end' => $windowEnd->format('Y-m-d'),
                        'total_in_range' => $total7Days,
                        'days_in_range' => $daySpan,
                        'daily_counts' => $dailyCounts,
                        'today_count' => $todayCount,
                        'today_per_minute' => $todayPerMinute,
                    ];
                } catch (Throwable $e) {
                    $errors[] = 'Processing failed: ' . $e->getMessage();
                } finally {
                    @imap_close($connection);
                    imap_errors();
                    imap_alerts();
                }
            }
        }
    }
}

if ($didSubmit && !$result && !$errors) {
    $errors[] = 'Request completed but no data was returned. Check mailbox, date range, and PHP error log.';
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Email Stats (IMAP)</title>
    <style>
        :root {
            --bg: #f6f7fb;
            --card: #ffffff;
            --text: #1e2330;
            --muted: #61708a;
            --primary: #0f5ef0;
            --danger: #b42318;
            --ok: #0f7a34;
            --border: #d9dfeb;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: "Segoe UI", Tahoma, sans-serif;
            background: linear-gradient(180deg, #eef2ff 0%, var(--bg) 40%);
            color: var(--text);
        }
        .container {
            max-width: 980px;
            margin: 40px auto;
            padding: 0 16px;
        }
        .card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 10px 24px rgba(20, 32, 56, 0.07);
        }
        h1 { margin-top: 0; font-size: 24px; }
        .sub { margin-top: -8px; color: var(--muted); }
        .grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
        }
        @media (max-width: 700px) {
            .grid { grid-template-columns: 1fr; }
        }
        label {
            display: block;
            font-size: 13px;
            margin-bottom: 6px;
            color: var(--muted);
        }
        input, select {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid var(--border);
            border-radius: 8px;
            font-size: 14px;
            outline: none;
            background: #fff;
        }
        input:focus, select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(15, 94, 240, 0.15);
        }
        .full { grid-column: 1 / -1; }
        .btn {
            margin-top: 14px;
            border: 0;
            background: var(--primary);
            color: #fff;
            font-weight: 600;
            padding: 11px 14px;
            border-radius: 8px;
            cursor: pointer;
        }
        .btn[disabled] { opacity: .75; cursor: wait; }
        .loading {
            display: none;
            margin-top: 10px;
            color: var(--muted);
            font-size: 13px;
        }
        .loading.show { display: block; }
        .error {
            margin-top: 14px;
            padding: 10px 12px;
            border: 1px solid #f2c1bc;
            border-radius: 8px;
            background: #fff1f0;
            color: var(--danger);
        }
        .notice {
            margin-top: 14px;
            padding: 10px 12px;
            border: 1px solid #bfd4ff;
            border-radius: 8px;
            background: #eef4ff;
            color: #1b4fa3;
        }
        .report {
            margin-top: 20px;
            border-top: 1px solid var(--border);
            padding-top: 16px;
        }
        .kpis {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 12px;
            margin-bottom: 14px;
        }
        @media (max-width: 700px) {
            .kpis { grid-template-columns: 1fr; }
        }
        .kpi {
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 14px;
            background: #fbfcff;
        }
        .kpi .label {
            font-size: 12px;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: .4px;
        }
        .kpi .value {
            margin-top: 6px;
            font-size: 24px;
            font-weight: 700;
            color: var(--ok);
        }
        table {
            width: 100%;
            border-collapse: collapse;
            border: 1px solid var(--border);
            border-radius: 8px;
            overflow: hidden;
        }
        th, td {
            text-align: left;
            padding: 10px 12px;
            border-bottom: 1px solid var(--border);
            font-size: 14px;
        }
        th { background: #f7f9ff; font-weight: 600; }
        tr:last-child td { border-bottom: 0; }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <h1>IMAP Email Stats</h1>
            <p class="sub">Connect to a mailbox and generate incoming-mail report for the last 7 days.</p>

            <form id="reportForm" method="post" autocomplete="off">
                <div class="grid">
                    <div>
                        <label for="server">Mail Server</label>
                        <input id="server" name="server" required value="<?= safe_str((string)($_POST['server'] ?? '')) ?>" placeholder="imap.example.com">
                    </div>
                    <div>
                        <label for="port">Port</label>
                        <input id="port" name="port" type="number" required min="1" value="<?= safe_str((string)($_POST['port'] ?? '993')) ?>">
                    </div>
                    <div>
                        <label for="username">Username</label>
                        <input id="username" name="username" required value="<?= safe_str((string)($_POST['username'] ?? '')) ?>">
                    </div>
                    <div>
                        <label for="password">Password</label>
                        <input id="password" name="password" type="password" required>
                    </div>
                    <div>
                        <label for="security">Security</label>
                        <select id="security" name="security" required>
                            <?php $sec = strtolower((string)($_POST['security'] ?? 'ssl')); ?>
                            <option value="ssl" <?= $sec === 'ssl' ? 'selected' : '' ?>>SSL</option>
                            <option value="starttls" <?= $sec === 'starttls' ? 'selected' : '' ?>>STARTTLS</option>
                        </select>
                    </div>
                    <div>
                        <label for="mailbox">Mailbox</label>
                        <input id="mailbox" name="mailbox" value="<?= safe_str((string)($_POST['mailbox'] ?? 'INBOX')) ?>" placeholder="INBOX">
                    </div>
                    <div>
                        <label for="start_date">Start Date (UTC)</label>
                        <input id="start_date" name="start_date" type="date" required value="<?= safe_str($formStartDate) ?>">
                    </div>
                    <div>
                        <label for="end_date">End Date (UTC)</label>
                        <input id="end_date" name="end_date" type="date" required value="<?= safe_str($formEndDate) ?>">
                    </div>
                </div>
                <button id="submitBtn" class="btn" type="submit">Load Report</button>
                <div id="loadingState" class="loading">Loading mailbox data, please wait...</div>
            </form>

            <?php foreach ($errors as $error): ?>
                <div class="error"><?= safe_str($error) ?></div>
            <?php endforeach; ?>
            <?php foreach ($notices as $notice): ?>
                <div class="notice"><?= safe_str($notice) ?></div>
            <?php endforeach; ?>

            <?php if ($result): ?>
                <div class="report">
                    <div class="kpis">
                        <div class="kpi">
                            <div class="label">Total Incoming (Selected Range)</div>
                            <div class="value"><?= safe_str((string)$result['total_in_range']) ?></div>
                        </div>
                        <div class="kpi">
                            <div class="label">Today Incoming</div>
                            <div class="value"><?= safe_str((string)$result['today_count']) ?></div>
                        </div>
                        <div class="kpi">
                            <div class="label">Today Average / Minute</div>
                            <div class="value"><?= number_format((float)$result['today_per_minute'], 4) ?></div>
                        </div>
                    </div>
                    <p class="sub">Range: <?= safe_str((string)$result['window_start']) ?> to <?= safe_str((string)$result['window_end']) ?> (<?= safe_str((string)$result['days_in_range']) ?> days)</p>

                    <table>
                        <thead>
                            <tr>
                                <th>Date (UTC)</th>
                                <th>Incoming Mails</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($result['daily_counts'] as $date => $count): ?>
                                <tr>
                                    <td><?= safe_str($date) ?></td>
                                    <td><?= safe_str((string)$count) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <script>
        (function () {
            var form = document.getElementById('reportForm');
            var btn = document.getElementById('submitBtn');
            var loading = document.getElementById('loadingState');
            if (!form || !btn || !loading) {
                return;
            }
            form.addEventListener('submit', function () {
                btn.disabled = true;
                btn.textContent = 'Loading...';
                loading.classList.add('show');
            });
        })();
    </script>
</body>
</html>

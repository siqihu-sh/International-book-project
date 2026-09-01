<?php
declare(strict_types=1);

require_once __DIR__ . '/include/mysqli_connect.php';

function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function getRows(mysqli $conn, string $module): array
{
    $queries = [
        'requests' => <<<'SQL'
            SELECT
                r.request_id,
                r.request_date,
                CONCAT(rec.contact_name, ' - ', rec.organization_name) AS recipient,
                CONCAT(u.first_name, ' ', u.last_name) AS created_by,
                COALESCE(
                    GROUP_CONCAT(
                        CONCAT(i.item_name, ' x ', ri.quantity)
                        ORDER BY i.item_name SEPARATOR ', '
                    ),
                    ''
                ) AS requested_items
            FROM `request` r
            JOIN recipient rec ON rec.recipient_id = r.recipient_id
            JOIN system_user u ON u.user_id = r.user_id
            LEFT JOIN request_item ri ON ri.request_id = r.request_id
            LEFT JOIN item i ON i.item_id = ri.item_id
            GROUP BY r.request_id, r.request_date, rec.contact_name,
                     rec.organization_name, u.first_name, u.last_name
            ORDER BY r.request_id DESC
        SQL,
        'shipments' => <<<'SQL'
            SELECT
                s.shipment_id,
                s.shipment_date,
                s.status,
                s.track_number,
                s.shipment_cost,
                s.request_id,
                rec.organization_name AS recipient
            FROM shipment s
            JOIN `request` r ON r.request_id = s.request_id
            JOIN recipient rec ON rec.recipient_id = r.recipient_id
            ORDER BY s.shipment_id DESC
        SQL,
        'returns' => <<<'SQL'
            SELECT
                ret.return_id,
                ret.return_date,
                ret.reason,
                ret.return_cost,
                COALESCE(GROUP_CONCAT(s.shipment_id ORDER BY s.shipment_id), '') AS shipment_ids
            FROM `return` ret
            LEFT JOIN shipment s ON s.return_id = ret.return_id
            GROUP BY ret.return_id, ret.return_date, ret.reason, ret.return_cost
            ORDER BY ret.return_id DESC
        SQL,
        'recipients' => <<<'SQL'
            SELECT
                rec.recipient_id,
                rec.contact_name,
                rec.organization_name,
                rec.phone_number,
                rec.email_address,
                COALESCE(
                    GROUP_CONCAT(
                        CONCAT(a.street, ', ', a.city, ', ', a.country)
                        SEPARATOR '; '
                    ),
                    ''
                ) AS addresses
            FROM recipient rec
            LEFT JOIN address a ON a.recipient_id = rec.recipient_id
            GROUP BY rec.recipient_id, rec.contact_name, rec.organization_name,
                     rec.phone_number, rec.email_address
            ORDER BY rec.recipient_id DESC
        SQL,
        'inventory' => <<<'SQL'
            SELECT item_id, item_name, available_quantity
            FROM item
            ORDER BY item_id DESC
        SQL,
    ];

    if (!isset($queries[$module])) {
        throw new InvalidArgumentException('Unknown business section.');
    }

    $result = $conn->query($queries[$module]);
    if (!$result) {
        throw new RuntimeException('Unable to load data: ' . $conn->error);
    }

    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    $result->free();
    return $rows;
}

$modules = [
    'requests' => ['label' => 'Requests', 'manage' => 'request'],
    'shipments' => ['label' => 'Shipments', 'manage' => 'shipment'],
    'returns' => ['label' => 'Returns', 'manage' => 'return'],
    'recipients' => ['label' => 'Recipients', 'manage' => 'recipient'],
    'inventory' => ['label' => 'Inventory', 'manage' => 'item'],
];

$selectedModule = is_string($_GET['module'] ?? null) ? $_GET['module'] : 'requests';
if (!isset($modules[$selectedModule])) {
    $selectedModule = 'requests';
}

$message = is_string($_GET['message'] ?? null) ? $_GET['message'] : '';
$error = '';
$rows = [];

try {
    $rows = getRows($conn, $selectedModule);
} catch (Throwable $exception) {
    $error = $exception->getMessage();
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>International Book Project</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <header>
        <h1>International Book Project</h1>
        <p>Book distribution management</p>
    </header>

    <main>

    <?php if ($message !== ''): ?>
        <p class="message" role="status" aria-live="polite"><?= h($message) ?></p>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <p class="error" role="alert"><?= h($error) ?></p>
    <?php endif; ?>

    <nav aria-label="Business sections">
        <ul class="tabs">
            <?php foreach ($modules as $moduleKey => $module): ?>
                <li>
                    <a class="<?= $moduleKey === $selectedModule ? 'active' : '' ?>"
                       href="?module=<?= h($moduleKey) ?>"
                       <?= $moduleKey === $selectedModule ? 'aria-current="page"' : '' ?>>
                        <?= h($module['label']) ?>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    </nav>

    <h2><?= h($modules[$selectedModule]['label']) ?></h2>

    <?php if ($selectedModule === 'requests'): ?>
        <p><a class="primary-action" href="request_create.php">Create Request</a></p>
    <?php elseif ($selectedModule === 'shipments'): ?>
        <p><a class="primary-action" href="shipment_create.php">Create Shipment</a></p>
    <?php elseif ($selectedModule === 'returns'): ?>
        <p><a class="primary-action" href="return_create.php">Process Return</a></p>
    <?php endif; ?>
    <p><a href="manage.php?type=<?= h($modules[$selectedModule]['manage']) ?>">Manage Records</a></p>

    <?php if ($rows === []): ?>
        <p>No records found.</p>
    <?php else: ?>
        <div class="table-scroll" role="region" tabindex="0" aria-label="<?= h($modules[$selectedModule]['label']) ?> data table">
        <table>
            <caption><?= h($modules[$selectedModule]['label']) ?> data</caption>
            <thead>
            <tr>
                <?php foreach (array_keys($rows[0]) as $column): ?>
                    <th><?= h(ucwords(str_replace('_', ' ', $column))) ?></th>
                <?php endforeach; ?>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <?php foreach ($row as $value): ?>
                        <td><?= $value === null || $value === '' ? '<em>None</em>' : h($value) ?></td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
    </main>
</body>
</html>

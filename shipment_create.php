<?php
declare(strict_types=1);

require_once __DIR__ . '/include/mysqli_connect.php';

function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$message = '';
$error = '';
$old = [
    'shipment_date' => date('Y-m-d\\TH:i'),
    'status' => 'pending',
    'user_id' => '',
    'track_number' => '',
    'request_id' => '',
    'shipment_cost' => '0.00',
];

$requests = [];
$users = [];

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        foreach ($old as $field => $default) {
            $value = $_POST[$field] ?? $default;
            if (is_array($value)) {
                throw new InvalidArgumentException('Invalid field value.');
            }
            $old[$field] = trim((string) $value);
        }

        $requestId = filter_var($old['request_id'], FILTER_VALIDATE_INT);
        $userId = filter_var($old['user_id'], FILTER_VALIDATE_INT);
        $shipmentCost = filter_var($old['shipment_cost'], FILTER_VALIDATE_FLOAT);
        $allowedStatuses = ['pending', 'shipped', 'delivered'];

        if ($requestId === false || $requestId < 1) {
            throw new InvalidArgumentException('Please select a request.');
        }
        if ($userId === false || $userId < 1) {
            throw new InvalidArgumentException('Please select a user.');
        }
        if (!in_array($old['status'], $allowedStatuses, true)) {
            throw new InvalidArgumentException('Please select a valid shipment status.');
        }
        if ($shipmentCost === false || $shipmentCost < 0) {
            throw new InvalidArgumentException('Shipment cost must be zero or greater.');
        }

        $shipmentDate = str_replace('T', ' ', $old['shipment_date']);
        if ($shipmentDate === '') {
            throw new InvalidArgumentException('Please enter a shipment date.');
        }

        $conn->begin_transaction();
        try {
            $requestCheck = $conn->prepare(
                'SELECT request_id FROM `request` WHERE request_id = ? FOR UPDATE'
            );
            if (!$requestCheck) {
                throw new RuntimeException('Unable to check request: ' . $conn->error);
            }
            $requestCheck->bind_param('i', $requestId);
            if (!$requestCheck->execute()) {
                throw new RuntimeException($requestCheck->error);
            }
            $requestResult = $requestCheck->get_result();
            if ($requestResult->num_rows === 0) {
                $requestCheck->close();
                throw new InvalidArgumentException('The selected request does not exist.');
            }
            $requestCheck->close();

            $shipmentCheck = $conn->prepare(
                'SELECT shipment_id FROM shipment WHERE request_id = ? FOR UPDATE'
            );
            if (!$shipmentCheck) {
                throw new RuntimeException('Unable to check existing shipment: ' . $conn->error);
            }
            $shipmentCheck->bind_param('i', $requestId);
            if (!$shipmentCheck->execute()) {
                throw new RuntimeException($shipmentCheck->error);
            }
            $shipmentResult = $shipmentCheck->get_result();
            if ($shipmentResult->num_rows > 0) {
                $shipmentCheck->close();
                throw new InvalidArgumentException('This request already has a shipment.');
            }
            $shipmentCheck->close();

            $trackNumber = $old['track_number'] === '' ? null : $old['track_number'];
            $insert = $conn->prepare(
                'INSERT INTO shipment
                    (shipment_date, status, user_id, track_number, request_id, shipment_cost)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            if (!$insert) {
                throw new RuntimeException('Unable to prepare shipment: ' . $conn->error);
            }
            $insert->bind_param(
                'ssisid',
                $shipmentDate,
                $old['status'],
                $userId,
                $trackNumber,
                $requestId,
                $shipmentCost
            );
            if (!$insert->execute()) {
                throw new RuntimeException($insert->error);
            }
            $insert->close();
            $conn->commit();

            header('Location: index.php?module=shipments&message=' . rawurlencode('Shipment created successfully.'));
            exit;
        } catch (Throwable $exception) {
            $conn->rollback();
            throw $exception;
        }
    }

    $requestResult = $conn->query(
        'SELECT
            r.request_id,
            r.request_date,
            CONCAT(rec.contact_name, " - ", rec.organization_name) AS recipient,
            COALESCE(
                GROUP_CONCAT(
                    CONCAT(i.item_name, " x ", ri.quantity)
                    ORDER BY i.item_name SEPARATOR ", "
                ),
                ""
            ) AS requested_items
         FROM `request` r
         JOIN recipient rec ON rec.recipient_id = r.recipient_id
         LEFT JOIN shipment s ON s.request_id = r.request_id
         JOIN request_item ri ON ri.request_id = r.request_id
         JOIN item i ON i.item_id = ri.item_id
         WHERE s.shipment_id IS NULL
         GROUP BY r.request_id, r.request_date, rec.contact_name,
                  rec.organization_name
         ORDER BY r.request_id DESC'
    );
    if (!$requestResult) {
        throw new RuntimeException('Unable to load available requests: ' . $conn->error);
    }
    while ($row = $requestResult->fetch_assoc()) {
        $requests[] = $row;
    }
    $requestResult->free();

    $userResult = $conn->query(
        'SELECT user_id, first_name, last_name
         FROM system_user
         ORDER BY last_name, first_name'
    );
    if (!$userResult) {
        throw new RuntimeException('Unable to load users: ' . $conn->error);
    }
    while ($row = $userResult->fetch_assoc()) {
        $users[] = $row;
    }
    $userResult->free();
} catch (Throwable $exception) {
    $error = $exception->getMessage();
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Create Shipment</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <h1>Create Shipment</h1>
    <p><a href="index.php?module=shipments">Back to Shipments</a></p>

    <?php if ($message !== ''): ?><p><?= h($message) ?></p><?php endif; ?>
    <?php if ($error !== ''): ?><p>Error: <?= h($error) ?></p><?php endif; ?>

    <?php if ($requests === []): ?>
        <p>No requests are available for shipment.</p>
    <?php else: ?>
        <form method="post" action="">
            <p>
                <label for="request_id">Request</label><br>
                <select id="request_id" name="request_id" required>
                    <option value="">Select a request</option>
                    <?php foreach ($requests as $request): ?>
                        <option value="<?= h($request['request_id']) ?>"
                            <?= $old['request_id'] === (string) $request['request_id'] ? 'selected' : '' ?>>
                            Request #<?= h($request['request_id']) ?> -
                            <?= h($request['recipient']) ?> -
                            <?= h($request['requested_items']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </p>

            <p>
                <label for="shipment_date">Shipment date</label><br>
                <input type="datetime-local" id="shipment_date" name="shipment_date"
                       value="<?= h($old['shipment_date']) ?>" required>
            </p>

            <p>
                <label for="status">Status</label><br>
                <select id="status" name="status" required>
                    <?php foreach (['pending', 'shipped', 'delivered'] as $status): ?>
                        <option value="<?= h($status) ?>" <?= $old['status'] === $status ? 'selected' : '' ?>>
                            <?= h(ucfirst($status)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </p>

            <p>
                <label for="user_id">Created by</label><br>
                <select id="user_id" name="user_id" required>
                    <option value="">Select a user</option>
                    <?php foreach ($users as $user): ?>
                        <?php $userName = $user['first_name'] . ' ' . $user['last_name']; ?>
                        <option value="<?= h($user['user_id']) ?>"
                            <?= $old['user_id'] === (string) $user['user_id'] ? 'selected' : '' ?>>
                            <?= h($userName) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </p>

            <p>
                <label for="track_number">Tracking number</label><br>
                <input type="text" id="track_number" name="track_number"
                       value="<?= h($old['track_number']) ?>">
            </p>

            <p>
                <label for="shipment_cost">Shipment cost</label><br>
                <input type="number" id="shipment_cost" name="shipment_cost"
                       min="0" step="0.01" value="<?= h($old['shipment_cost']) ?>" required>
            </p>

            <button type="submit">Create Shipment</button>
        </form>
    <?php endif; ?>
</body>
</html>

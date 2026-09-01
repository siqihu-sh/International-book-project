<?php
declare(strict_types=1);

require_once __DIR__ . '/include/mysqli_connect.php';

function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$error = '';
$old = [
    'return_date' => date('Y-m-d\\TH:i'),
    'shipment_id' => '',
    'reason' => '',
    'return_cost' => '0.00',
];

$shipments = [];

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        foreach ($old as $field => $default) {
            $value = $_POST[$field] ?? $default;
            if (is_array($value)) {
                throw new InvalidArgumentException('Invalid field value.');
            }
            $old[$field] = trim((string) $value);
        }

        $shipmentId = filter_var($old['shipment_id'], FILTER_VALIDATE_INT);
        $returnCost = filter_var($old['return_cost'], FILTER_VALIDATE_FLOAT);
        $reason = $old['reason'];

        if ($shipmentId === false || $shipmentId < 1) {
            throw new InvalidArgumentException('Please select a shipment.');
        }
        if ($reason === '') {
            throw new InvalidArgumentException('Please enter a return reason.');
        }
        if (strlen($reason) > 200) {
            throw new InvalidArgumentException('Return reason must be 200 characters or fewer.');
        }
        if ($returnCost === false || $returnCost < 0) {
            throw new InvalidArgumentException('Return cost must be zero or greater.');
        }

        $returnDate = str_replace('T', ' ', $old['return_date']);
        if ($returnDate === '') {
            throw new InvalidArgumentException('Please enter a return date.');
        }

        $conn->begin_transaction();
        try {
            $shipmentCheck = $conn->prepare(
                'SELECT shipment_id, request_id
                 FROM shipment
                 WHERE shipment_id = ? AND return_id IS NULL
                 FOR UPDATE'
            );
            if (!$shipmentCheck) {
                throw new RuntimeException('Unable to check shipment: ' . $conn->error);
            }
            $shipmentCheck->bind_param('i', $shipmentId);
            if (!$shipmentCheck->execute()) {
                throw new RuntimeException($shipmentCheck->error);
            }
            $shipmentResult = $shipmentCheck->get_result();
            $shipment = $shipmentResult->fetch_assoc();
            $shipmentCheck->close();

            if ($shipment === null) {
                throw new InvalidArgumentException(
                    'The selected shipment does not exist or already has a return.'
                );
            }

            $returnInsert = $conn->prepare(
                'INSERT INTO `return` (return_date, reason, return_cost)
                 VALUES (?, ?, ?)'
            );
            if (!$returnInsert) {
                throw new RuntimeException('Unable to prepare return: ' . $conn->error);
            }
            $returnInsert->bind_param('ssd', $returnDate, $reason, $returnCost);
            if (!$returnInsert->execute()) {
                throw new RuntimeException($returnInsert->error);
            }
            $returnId = $conn->insert_id;
            $returnInsert->close();

            $itemLookup = $conn->prepare(
                'SELECT item_id, quantity
                 FROM request_item
                 WHERE request_id = ?
                 FOR UPDATE'
            );
            if (!$itemLookup) {
                throw new RuntimeException('Unable to load request items: ' . $conn->error);
            }
            $requestId = (int) $shipment['request_id'];
            $itemLookup->bind_param('i', $requestId);
            if (!$itemLookup->execute()) {
                throw new RuntimeException($itemLookup->error);
            }
            $itemResult = $itemLookup->get_result();

            $inventoryRestore = $conn->prepare(
                'UPDATE item
                 SET available_quantity = available_quantity + ?
                 WHERE item_id = ?'
            );
            if (!$inventoryRestore) {
                $itemLookup->close();
                throw new RuntimeException('Unable to prepare inventory restore: ' . $conn->error);
            }
            while ($item = $itemResult->fetch_assoc()) {
                $quantity = (int) $item['quantity'];
                $itemId = (int) $item['item_id'];
                $inventoryRestore->bind_param('ii', $quantity, $itemId);
                if (!$inventoryRestore->execute()) {
                    throw new RuntimeException('Unable to restore inventory: ' . $inventoryRestore->error);
                }
            }
            $inventoryRestore->close();
            $itemLookup->close();

            $shipmentUpdate = $conn->prepare(
                'UPDATE shipment SET return_id = ? WHERE shipment_id = ?'
            );
            if (!$shipmentUpdate) {
                throw new RuntimeException('Unable to update shipment: ' . $conn->error);
            }
            $shipmentUpdate->bind_param('ii', $returnId, $shipmentId);
            if (!$shipmentUpdate->execute()) {
                throw new RuntimeException($shipmentUpdate->error);
            }
            $shipmentUpdate->close();

            $conn->commit();
            header('Location: index.php?module=returns&message=' . rawurlencode('Return processed successfully.'));
            exit;
        } catch (Throwable $exception) {
            $conn->rollback();
            throw $exception;
        }
    }

    $shipmentResult = $conn->query(
        'SELECT
            s.shipment_id,
            s.shipment_date,
            s.status,
            s.track_number,
            s.request_id,
            CONCAT(rec.contact_name, " - ", rec.organization_name) AS recipient,
            GROUP_CONCAT(
                CONCAT(i.item_name, " x ", ri.quantity)
                ORDER BY i.item_name SEPARATOR ", "
            ) AS shipped_items
         FROM shipment s
         JOIN `request` r ON r.request_id = s.request_id
         JOIN recipient rec ON rec.recipient_id = r.recipient_id
         JOIN request_item ri ON ri.request_id = r.request_id
         JOIN item i ON i.item_id = ri.item_id
         WHERE s.return_id IS NULL
         GROUP BY s.shipment_id, s.shipment_date, s.status, s.track_number,
                  s.request_id, rec.contact_name, rec.organization_name
         ORDER BY s.shipment_id DESC'
    );
    if (!$shipmentResult) {
        throw new RuntimeException('Unable to load available shipments: ' . $conn->error);
    }
    while ($row = $shipmentResult->fetch_assoc()) {
        $shipments[] = $row;
    }
    $shipmentResult->free();
} catch (Throwable $exception) {
    $error = $exception->getMessage();
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Process Return</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <h1>Process Return</h1>
    <p><a href="index.php?module=returns">Back to Returns</a></p>

    <?php if ($error !== ''): ?><p>Error: <?= h($error) ?></p><?php endif; ?>

    <?php if ($shipments === []): ?>
        <p>No shipments are available for return.</p>
    <?php else: ?>
        <form method="post" action="">
            <p>
                <label for="shipment_id">Shipment</label><br>
                <select id="shipment_id" name="shipment_id" required>
                    <option value="">Select a shipment</option>
                    <?php foreach ($shipments as $shipment): ?>
                        <option value="<?= h($shipment['shipment_id']) ?>"
                            <?= $old['shipment_id'] === (string) $shipment['shipment_id'] ? 'selected' : '' ?>>
                            Shipment #<?= h($shipment['shipment_id']) ?> -
                            Request #<?= h($shipment['request_id']) ?> -
                            <?= h($shipment['recipient']) ?> -
                            <?= h($shipment['shipped_items']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </p>

            <p>
                <label for="return_date">Return date</label><br>
                <input type="datetime-local" id="return_date" name="return_date"
                       value="<?= h($old['return_date']) ?>" required>
            </p>

            <p>
                <label for="reason">Reason</label><br>
                <input type="text" id="reason" name="reason" maxlength="200"
                       value="<?= h($old['reason']) ?>" required>
            </p>

            <p>
                <label for="return_cost">Return cost</label><br>
                <input type="number" id="return_cost" name="return_cost"
                       min="0" step="0.01" value="<?= h($old['return_cost']) ?>" required>
            </p>

            <button type="submit">Process Return</button>
        </form>
    <?php endif; ?>
</body>
</html>

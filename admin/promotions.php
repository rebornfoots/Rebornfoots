<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
requireAdmin();

function promotionDate(mixed $value): ?string
{
    $text = trim((string) $value);
    if ($text === '') {
        return null;
    }
    return DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $text)?->format('Y-m-d H:i:s');
}

function optionalNumber(mixed $value): float|int|null|false
{
    $text = trim((string) $value);
    return $text === '' ? null : filter_var($text, FILTER_VALIDATE_FLOAT);
}

function validImageUrl(string $value): bool
{
    if ($value === '') {
        return true;
    }
    if (str_starts_with($value, '/') && !str_starts_with($value, '//')) {
        return mb_strlen($value) <= 500;
    }
    return mb_strlen($value) <= 500
        && filter_var($value, FILTER_VALIDATE_URL) !== false
        && in_array(strtolower((string) parse_url($value, PHP_URL_SCHEME)), ['http', 'https'], true);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validCsrf($_POST['csrf_token'] ?? null)) {
        flash('error', 'Your session expired. Please try again.');
        header('Location: /admin/promotions.php');
        exit;
    }
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'toggle_coupon') {
            $code = strtoupper(trim((string) ($_POST['code'] ?? '')));
            $active = ($_POST['active'] ?? '') === '1' ? 1 : 0;
            if (!preg_match('/^[A-Z0-9_-]{3,30}$/', $code)) {
                throw new DomainException('Invalid coupon code.');
            }
            $statement = database()->prepare('UPDATE coupons SET active = ? WHERE code = ?');
            $statement->bind_param('is', $active, $code);
            $statement->execute();
            $statement->close();
            flash('success', $active ? "{$code} activated." : "{$code} paused.");
        } elseif ($action === 'save_coupon') {
            $code = strtoupper(trim((string) ($_POST['code'] ?? '')));
            $description = mb_substr(trim((string) ($_POST['description'] ?? '')), 0, 150);
            $type = (string) ($_POST['discount_type'] ?? '');
            $value = filter_var($_POST['discount_value'] ?? null, FILTER_VALIDATE_FLOAT);
            $minimum = filter_var($_POST['min_subtotal'] ?? 0, FILTER_VALIDATE_FLOAT);
            $maximum = optionalNumber($_POST['max_discount'] ?? '');
            $limit = optionalNumber($_POST['usage_limit'] ?? '');
            $startsAt = promotionDate($_POST['starts_at'] ?? '');
            $endsAt = promotionDate($_POST['ends_at'] ?? '');
            if (!preg_match('/^[A-Z0-9_-]{3,30}$/', $code)
                || !in_array($type, ['percent', 'fixed'], true)
                || $value === false || $value <= 0 || ($type === 'percent' && $value > 100)
                || $minimum === false || $minimum < 0
                || $maximum === false || ($maximum !== null && $maximum <= 0)
                || $limit === false || ($limit !== null && ($limit < 1 || floor($limit) !== $limit))
                || ($startsAt !== null && $endsAt !== null && $startsAt >= $endsAt)
            ) {
                throw new DomainException('Enter valid coupon values, limits and dates.');
            }
            $usageLimit = $limit === null ? null : (int) $limit;
            $statement = database()->prepare(
                'INSERT INTO coupons
                 (code, description, discount_type, discount_value, min_subtotal, max_discount,
                  usage_limit, starts_at, ends_at, active)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
                 ON DUPLICATE KEY UPDATE description = VALUES(description),
                    discount_type = VALUES(discount_type), discount_value = VALUES(discount_value),
                    min_subtotal = VALUES(min_subtotal), max_discount = VALUES(max_discount),
                    usage_limit = VALUES(usage_limit), starts_at = VALUES(starts_at),
                    ends_at = VALUES(ends_at), active = 1'
            );
            $statement->bind_param(
                'sssdddiss',
                $code, $description, $type, $value, $minimum, $maximum,
                $usageLimit, $startsAt, $endsAt
            );
            $statement->execute();
            $statement->close();
            flash('success', "Coupon {$code} saved.");
        } elseif ($action === 'toggle_combo') {
            $comboId = filter_var($_POST['combo_id'] ?? null, FILTER_VALIDATE_INT);
            $active = ($_POST['active'] ?? '') === '1' ? 1 : 0;
            if ($comboId === false || $comboId < 1) {
                throw new DomainException('Invalid combo offer.');
            }
            $statement = database()->prepare('UPDATE combo_offers SET active = ? WHERE id = ?');
            $statement->bind_param('ii', $active, $comboId);
            $statement->execute();
            $statement->close();
            flash('success', $active ? 'Combo activated.' : 'Combo paused.');
        } elseif ($action === 'save_combo_image') {
            $comboId = filter_var($_POST['combo_id'] ?? null, FILTER_VALIDATE_INT);
            $imageUrl = trim((string) ($_POST['image_url'] ?? ''));
            if ($comboId === false || $comboId < 1 || !validImageUrl($imageUrl)) {
                throw new DomainException('Enter a valid combo image URL or site-relative image path.');
            }
            $statement = database()->prepare('UPDATE combo_offers SET image_url = ? WHERE id = ?');
            $statement->bind_param('si', $imageUrl, $comboId);
            $statement->execute();
            $statement->close();
            flash('success', 'Combo image updated.');
        } elseif ($action === 'save_combo') {
            $name = mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 120);
            $imageUrl = trim((string) ($_POST['image_url'] ?? ''));
            $type = (string) ($_POST['discount_type'] ?? '');
            $value = filter_var($_POST['discount_value'] ?? null, FILTER_VALIDATE_FLOAT);
            $priority = filter_var($_POST['priority'] ?? 100, FILTER_VALIDATE_INT);
            $startsAt = promotionDate($_POST['starts_at'] ?? '');
            $endsAt = promotionDate($_POST['ends_at'] ?? '');
            $lines = preg_split('/[\r\n,]+/', (string) ($_POST['items'] ?? '')) ?: [];
            $items = [];
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                if (!preg_match('/^([a-z0-9-]{2,64})\s*:\s*([1-9]\d{0,2})$/', $line, $match)) {
                    throw new DomainException('Combo items must use product-id:quantity format.');
                }
                $items[$match[1]] = (int) $match[2];
            }
            if (mb_strlen($name) < 3 || count($items) < 2 || !validImageUrl($imageUrl)
                || !in_array($type, ['percent', 'fixed'], true)
                || $value === false || $value <= 0 || ($type === 'percent' && $value > 100)
                || $priority === false || $priority < 1 || $priority > 65535
                || ($startsAt !== null && $endsAt !== null && $startsAt >= $endsAt)
            ) {
                throw new DomainException('Enter a name, at least two valid items, discount and dates.');
            }
            $productIds = array_keys($items);
            $placeholders = implode(',', array_fill(0, count($productIds), '?'));
            $check = database()->prepare("SELECT id FROM products WHERE id IN ({$placeholders})");
            $types = str_repeat('s', count($productIds));
            $check->bind_param($types, ...$productIds);
            $check->execute();
            $found = $check->get_result()->num_rows;
            $check->close();
            if ($found !== count($items)) {
                throw new DomainException('One or more combo product IDs do not exist.');
            }
            database()->begin_transaction();
            try {
                $statement = database()->prepare(
                    'INSERT INTO combo_offers
                     (name, image_url, discount_type, discount_value, priority, starts_at, ends_at, active)
                     VALUES (?, ?, ?, ?, ?, ?, ?, 1)'
                );
                $statement->bind_param('sssdiss', $name, $imageUrl, $type, $value, $priority, $startsAt, $endsAt);
                $statement->execute();
                $comboId = database()->insert_id;
                $statement->close();
                $itemStatement = database()->prepare(
                    'INSERT INTO combo_offer_items (combo_id, product_id, quantity) VALUES (?, ?, ?)'
                );
                foreach ($items as $productId => $quantity) {
                    $itemStatement->bind_param('isi', $comboId, $productId, $quantity);
                    $itemStatement->execute();
                }
                $itemStatement->close();
                database()->commit();
            } catch (Throwable $error) {
                database()->rollback();
                throw $error;
            }
            flash('success', "Combo {$name} created.");
        }
    } catch (DomainException $error) {
        flash('error', $error->getMessage());
    } catch (Throwable $error) {
        error_log('InbornFoot promotion admin error: ' . $error->getMessage());
        flash('error', 'The promotion could not be saved.');
    }
    header('Location: /admin/promotions.php');
    exit;
}

$coupons = $combos = $products = [];
$databaseError = '';
try {
    $coupons = database()->query(
        'SELECT code, description, discount_type, discount_value, min_subtotal, max_discount,
                usage_limit, used_count, starts_at, ends_at, active
         FROM coupons ORDER BY created_at DESC'
    )->fetch_all(MYSQLI_ASSOC);
    $combos = database()->query(
        "SELECT combo_offers.id, combo_offers.name, combo_offers.image_url, combo_offers.discount_type,
                combo_offers.discount_value, combo_offers.priority, combo_offers.active,
                GROUP_CONCAT(CONCAT(combo_offer_items.product_id, ':', combo_offer_items.quantity)
                    ORDER BY combo_offer_items.product_id SEPARATOR ', ') AS items
         FROM combo_offers
         JOIN combo_offer_items ON combo_offer_items.combo_id = combo_offers.id
         GROUP BY combo_offers.id
         ORDER BY combo_offers.priority, combo_offers.id DESC"
    )->fetch_all(MYSQLI_ASSOC);
    $products = database()->query(
        'SELECT id, name, variant FROM products WHERE active = 1 ORDER BY name'
    )->fetch_all(MYSQLI_ASSOC);
} catch (Throwable $error) {
    error_log('InbornFoot promotions page error: ' . $error->getMessage());
    $databaseError = 'Promotions are unavailable. Run database/migrate_promotions.sql first.';
}
$flash = takeFlash();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex,nofollow,noarchive">
  <title>Promotions | InbornFoot Admin</title><link rel="stylesheet" href="/admin/styles.css">
</head>
<body>
  <header class="admin-header">
    <a class="admin-brand" href="/admin/"><img src="/logo.png" alt="" width="44" height="44"><span><strong>InbornFoot</strong><small>Admin</small></span></a>
    <div class="admin-actions"><a href="/admin/">Orders</a><a href="/admin/products.php">Products</a><form method="post" action="/admin/logout.php"><input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>"><button type="submit">Sign out</button></form></div>
  </header>
  <main class="dashboard">
    <div class="page-heading"><div><p class="eyebrow">Pricing</p><h1>Coupons & combos</h1><p>Coupons stack after automatic combo savings. Delivery is never discounted.</p></div></div>
    <?php if ($flash): ?><div class="alert alert-<?= h($flash['type']) ?>"><?= h($flash['message']) ?></div><?php endif; ?>
    <?php if ($databaseError): ?><div class="alert alert-error"><?= h($databaseError) ?></div><?php endif; ?>
    <div class="promotion-grid">
      <section class="product-editor">
        <div class="editor-heading"><div><p class="eyebrow">Coupon</p><h2>Create or update coupon</h2></div></div>
        <form method="post" class="product-form">
          <input type="hidden" name="action" value="save_coupon"><input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
          <label><span>Code</span><input name="code" pattern="[A-Za-z0-9_-]{3,30}" placeholder="WELCOME10" required></label>
          <label><span>Description</span><input name="description" maxlength="150" placeholder="10% welcome discount"></label>
          <label><span>Discount type</span><select name="discount_type"><option value="percent">Percentage</option><option value="fixed">Fixed ₹ amount</option></select></label>
          <label><span>Discount value</span><input name="discount_value" type="number" min="0.01" step="0.01" required></label>
          <label><span>Minimum subtotal ₹</span><input name="min_subtotal" type="number" min="0" step="0.01" value="0" required></label>
          <label><span>Maximum discount ₹</span><input name="max_discount" type="number" min="0.01" step="0.01" placeholder="Optional"></label>
          <label><span>Usage limit</span><input name="usage_limit" type="number" min="1" step="1" placeholder="Optional"></label>
          <label><span>Starts</span><input name="starts_at" type="datetime-local"></label>
          <label><span>Ends</span><input name="ends_at" type="datetime-local"></label>
          <div class="form-actions wide"><button class="primary-button" type="submit">Save coupon</button></div>
        </form>
      </section>
      <section class="product-editor">
        <div class="editor-heading"><div><p class="eyebrow">Combo</p><h2>Create automatic combo</h2></div></div>
        <form method="post" class="product-form">
          <input type="hidden" name="action" value="save_combo"><input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
          <label><span>Name</span><input name="name" maxlength="120" placeholder="Oil family combo" required></label>
          <label><span>Combo image URL</span><input name="image_url" maxlength="500" placeholder="/assets/images/oil-combo.jpg"></label>
          <label><span>Discount type</span><select name="discount_type"><option value="percent">Percentage</option><option value="fixed">Fixed ₹ amount</option></select></label>
          <label><span>Discount value</span><input name="discount_value" type="number" min="0.01" step="0.01" required></label>
          <label><span>Priority</span><input name="priority" type="number" min="1" max="65535" value="100" required></label>
          <label><span>Starts</span><input name="starts_at" type="datetime-local"></label>
          <label><span>Ends</span><input name="ends_at" type="datetime-local"></label>
          <label class="wide"><span>Products and quantities</span><textarea name="items" rows="5" placeholder="peanut-oil:1&#10;coconut-oil:1" required></textarea><small>One product-id:quantity per line. At least two products.</small></label>
          <div class="combo-product-help wide"><?php foreach ($products as $product): ?><code><?= h($product['id']) ?></code> <?= h($product['name']) ?><?= $product['variant'] ? ' · ' . h($product['variant']) : '' ?><br><?php endforeach; ?></div>
          <div class="form-actions wide"><button class="primary-button" type="submit">Create combo</button></div>
        </form>
      </section>
    </div>
    <section class="product-list promotion-list">
      <div class="product-list-head"><strong>Coupons</strong><span><?= count($coupons) ?> configured</span></div>
      <?php foreach ($coupons as $coupon): ?><article class="admin-product <?= $coupon['active'] ? '' : 'is-inactive' ?>"><div class="admin-product-copy"><h2><?= h($coupon['code']) ?></h2><p><?= h($coupon['description']) ?> · <?= $coupon['discount_type'] === 'percent' ? h($coupon['discount_value']) . '%' : '₹' . number_format((float) $coupon['discount_value'], 0) ?> · used <?= h($coupon['used_count']) ?><?= $coupon['usage_limit'] !== null ? '/' . h($coupon['usage_limit']) : '' ?></p></div><form method="post" class="admin-product-actions"><input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>"><input type="hidden" name="action" value="toggle_coupon"><input type="hidden" name="code" value="<?= h($coupon['code']) ?>"><input type="hidden" name="active" value="<?= $coupon['active'] ? '0' : '1' ?>"><button type="submit"><?= $coupon['active'] ? 'Pause' : 'Activate' ?></button></form></article><?php endforeach; ?>
    </section>
    <section class="product-list promotion-list">
      <div class="product-list-head"><strong>Combo offers</strong><span><?= count($combos) ?> configured</span></div>
      <?php if ($combos !== []): ?>
        <form method="post" class="combo-image-editor">
          <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
          <input type="hidden" name="action" value="save_combo_image">
          <select name="combo_id" aria-label="Combo offer" required>
            <?php foreach ($combos as $combo): ?><option value="<?= h($combo['id']) ?>"><?= h($combo['name']) ?></option><?php endforeach; ?>
          </select>
          <input name="image_url" maxlength="500" placeholder="/assets/images/oil-combo.jpg or https://..." required>
          <button type="submit">Save combo image</button>
        </form>
      <?php endif; ?>
      <?php foreach ($combos as $combo): ?><article class="admin-product <?= $combo['active'] ? '' : 'is-inactive' ?>"><div class="admin-product-copy"><h2><?= h($combo['name']) ?></h2><p><?= h($combo['items']) ?> · <?= $combo['discount_type'] === 'percent' ? h($combo['discount_value']) . '%' : '₹' . number_format((float) $combo['discount_value'], 0) ?> off · priority <?= h($combo['priority']) ?></p></div><form method="post" class="admin-product-actions"><input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>"><input type="hidden" name="action" value="toggle_combo"><input type="hidden" name="combo_id" value="<?= h($combo['id']) ?>"><input type="hidden" name="active" value="<?= $combo['active'] ? '0' : '1' ?>"><button type="submit"><?= $combo['active'] ? 'Pause' : 'Activate' ?></button></form></article><?php endforeach; ?>
    </section>
  </main>
</body>
</html>

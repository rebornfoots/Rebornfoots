<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
requireAdmin();

function productText(mixed $value, int $maxLength): string
{
    $text = trim(preg_replace('/\s+/u', ' ', is_string($value) ? $value : '') ?? '');
    return mb_substr($text, 0, $maxLength);
}

function validProductImage(string $value): bool
{
    if ($value === '') {
        return true;
    }
    if (preg_match('#^assets/images/[a-zA-Z0-9/_\-.]+$#', $value)) {
        return true;
    }
    return filter_var($value, FILTER_VALIDATE_URL) !== false
        && strtolower((string) parse_url($value, PHP_URL_SCHEME)) === 'https'
        && strtolower((string) parse_url($value, PHP_URL_HOST)) === 'images.unsplash.com';
}

function uploadedProductImage(array $file): ?string
{
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($error !== UPLOAD_ERR_OK || !isset($file['tmp_name'], $file['size'])) {
        throw new RuntimeException('The image upload failed.');
    }
    if ((int) $file['size'] < 1 || (int) $file['size'] > 5 * 1024 * 1024) {
        throw new RuntimeException('Product images must be smaller than 5 MB.');
    }

    $mimeInfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = $mimeInfo ? finfo_file($mimeInfo, (string) $file['tmp_name']) : false;
    if ($mimeInfo) {
        finfo_close($mimeInfo);
    }
    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!is_string($mime) || !isset($extensions[$mime])) {
        throw new RuntimeException('Upload a JPEG, PNG or WebP image.');
    }

    $dimensions = @getimagesize((string) $file['tmp_name']);
    if (!is_array($dimensions) || $dimensions[0] < 300 || $dimensions[1] < 300
        || $dimensions[0] > 8000 || $dimensions[1] > 8000
    ) {
        throw new RuntimeException('Images must be between 300 and 8000 pixels on each side.');
    }

    $directory = dirname(__DIR__) . '/assets/images/products';
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        throw new RuntimeException('The product image directory is unavailable.');
    }
    $filename = 'product-' . bin2hex(random_bytes(12)) . '.' . $extensions[$mime];
    if (!move_uploaded_file((string) $file['tmp_name'], $directory . '/' . $filename)) {
        throw new RuntimeException('The image could not be saved.');
    }
    return 'assets/images/products/' . $filename;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validCsrf($_POST['csrf_token'] ?? null)) {
        flash('error', 'Your session expired. Please try again.');
        header('Location: /admin/products.php');
        exit;
    }

    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'toggle') {
        $id = (string) ($_POST['id'] ?? '');
        $active = ($_POST['active'] ?? '') === '1' ? 1 : 0;
        if (!preg_match('/^[a-z0-9-]{2,64}$/', $id)) {
            flash('error', 'Invalid product.');
        } else {
            try {
                $statement = database()->prepare('UPDATE products SET active = ? WHERE id = ?');
                $statement->bind_param('is', $active, $id);
                $statement->execute();
                $statement->close();
                flash('success', $active ? 'Product published.' : 'Product hidden from the store.');
            } catch (Throwable $error) {
                error_log('InbornFoot product toggle error: ' . $error->getMessage());
                flash('error', 'The product could not be updated.');
            }
        }
        header('Location: /admin/products.php');
        exit;
    }

    if ($action === 'save') {
        $originalId = (string) ($_POST['original_id'] ?? '');
        $id = strtolower(productText($_POST['id'] ?? '', 64));
        $slug = strtolower(productText($_POST['slug'] ?? '', 100));
        $name = productText($_POST['name'] ?? '', 150);
        $category = productText($_POST['category'] ?? '', 80);
        $description = productText($_POST['description'] ?? '', 500);
        $variant = productText($_POST['variant'] ?? '', 100);
        $badge = productText($_POST['badge'] ?? '', 60);
        $imageUrl = trim((string) ($_POST['image_url'] ?? ''));
        $altText = productText($_POST['alt_text'] ?? '', 250);
        $purchasable = isset($_POST['purchasable']) ? 1 : 0;
        $freeDelivery = isset($_POST['free_delivery']) ? 1 : 0;
        $trackStock = isset($_POST['track_stock']) ? 1 : 0;
        $stockQuantity = max(0, min(4294967295, (int) ($_POST['stock_quantity'] ?? 0)));
        $lowStockThreshold = max(0, min(65535, (int) ($_POST['low_stock_threshold'] ?? 5)));
        $active = isset($_POST['active']) ? 1 : 0;
        $sortOrder = max(0, min(65535, (int) ($_POST['sort_order'] ?? 0)));
        $priceInput = trim((string) ($_POST['price'] ?? ''));
        $price = $priceInput === '' ? null : filter_var($priceInput, FILTER_VALIDATE_FLOAT);
        $benefitValues = array_values(array_filter(array_map(
            static fn (string $value): string => productText($value, 60),
            explode(',', (string) ($_POST['benefits'] ?? ''))
        )));
        $benefits = $benefitValues === [] ? null : json_encode(
            array_slice($benefitValues, 0, 8),
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );

        $errors = [];
        if (!preg_match('/^[a-z0-9-]{2,64}$/', $id)) {
            $errors[] = 'Product ID must contain lowercase letters, numbers and hyphens.';
        }
        if (!preg_match('/^[a-z0-9-]{2,100}$/', $slug)) {
            $errors[] = 'URL slug must contain lowercase letters, numbers and hyphens.';
        }
        if (mb_strlen($name) < 2 || mb_strlen($category) < 2 || mb_strlen($description) < 10) {
            $errors[] = 'Name, category and a complete description are required.';
        }
        if ($price !== null && ($price === false || $price < 0.01 || $price > 999999.99)) {
            $errors[] = 'Enter a valid price or leave it empty.';
        }
        if ($purchasable && ($price === null || $price === false)) {
            $errors[] = 'Purchasable products require a price.';
        }
        if (!validProductImage($imageUrl)) {
            $errors[] = 'Upload an image, use a local assets/images path, or use an images.unsplash.com URL.';
        }
        if ($originalId !== '' && $originalId !== $id) {
            $errors[] = 'Product IDs cannot be changed after creation.';
        }

        try {
            $uploadedImage = uploadedProductImage($_FILES['product_image'] ?? []);
            if ($uploadedImage !== null) {
                $imageUrl = $uploadedImage;
            }
        } catch (RuntimeException $error) {
            $errors[] = $error->getMessage();
        }

        if ($errors !== []) {
            $_SESSION['product_form'] = $_POST;
            flash('error', implode(' ', $errors));
            header('Location: /admin/products.php?' . http_build_query(['edit' => $originalId ?: 'new']));
            exit;
        }

        try {
            if ($originalId === '') {
                $statement = database()->prepare(
                    'INSERT INTO products
                     (id, slug, name, category, description, price, variant, badge, benefits, image_url,
                      alt_text, purchasable, free_delivery, track_stock, stock_quantity,
                      low_stock_threshold, active, sort_order)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $statement->bind_param(
                    'sssssdsssssiiiiiii',
                    $id, $slug, $name, $category, $description, $price, $variant, $badge,
                    $benefits, $imageUrl, $altText, $purchasable, $freeDelivery, $trackStock,
                    $stockQuantity, $lowStockThreshold, $active, $sortOrder
                );
            } else {
                $statement = database()->prepare(
                    'UPDATE products SET slug = ?, name = ?, category = ?, description = ?, price = ?,
                     variant = ?, badge = ?, benefits = ?, image_url = ?, alt_text = ?,
                     purchasable = ?, free_delivery = ?, track_stock = ?, stock_quantity = ?,
                     low_stock_threshold = ?, active = ?, sort_order = ? WHERE id = ?'
                );
                $statement->bind_param(
                    'ssssdsssssiiiiiiis',
                    $slug, $name, $category, $description, $price, $variant, $badge, $benefits,
                    $imageUrl, $altText, $purchasable, $freeDelivery, $trackStock,
                    $stockQuantity, $lowStockThreshold, $active, $sortOrder, $id
                );
            }
            $statement->execute();
            $statement->close();
            flash('success', $originalId === '' ? 'Product created.' : 'Product updated.');
            header('Location: /admin/products.php?edit=' . rawurlencode($id));
            exit;
        } catch (Throwable $error) {
            error_log('InbornFoot product save error: ' . $error->getMessage());
            $_SESSION['product_form'] = $_POST;
            flash('error', 'The product could not be saved. Check that its ID and URL slug are unique.');
            header('Location: /admin/products.php?' . http_build_query(['edit' => $originalId ?: 'new']));
            exit;
        }
    }
}

$databaseError = '';
$products = [];
try {
    $products = database()->query(
        'SELECT id, slug, name, category, description, price, variant, badge, benefits,
                image_url, alt_text, purchasable, free_delivery, track_stock, stock_quantity, low_stock_threshold,
                active, sort_order, updated_at
         FROM products ORDER BY sort_order, name'
    )->fetch_all(MYSQLI_ASSOC);
} catch (Throwable $error) {
    error_log('InbornFoot products admin error: ' . $error->getMessage());
    $databaseError = 'Products are unavailable. Run database/migrate_products.sql first.';
}

$editId = (string) ($_GET['edit'] ?? '');
$editing = null;
if ($editId !== '' && $editId !== 'new') {
    foreach ($products as $product) {
        if ($product['id'] === $editId) {
            $editing = $product;
            break;
        }
    }
}
$form = $_SESSION['product_form'] ?? $editing ?? [
    'id' => '',
    'slug' => '',
    'name' => '',
    'category' => '',
    'description' => '',
    'price' => '',
    'variant' => '',
    'badge' => '',
    'benefits' => '',
    'image_url' => '',
    'alt_text' => '',
    'purchasable' => 0,
    'free_delivery' => 1,
    'track_stock' => 0,
    'stock_quantity' => 0,
    'low_stock_threshold' => 5,
    'active' => 1,
    'sort_order' => count($products) * 10 + 10,
];
unset($_SESSION['product_form']);
if (is_array($form['benefits'] ?? null)) {
    $form['benefits'] = implode(', ', $form['benefits']);
} elseif (is_string($form['benefits'] ?? null) && str_starts_with((string) $form['benefits'], '[')) {
    try {
        $form['benefits'] = implode(', ', json_decode((string) $form['benefits'], true, 16, JSON_THROW_ON_ERROR));
    } catch (JsonException) {
        $form['benefits'] = '';
    }
}
$showForm = $editId !== '';
$flash = takeFlash();
$activeCount = count(array_filter($products, static fn (array $product): bool => (bool) $product['active']));
$purchasableCount = count(array_filter($products, static fn (array $product): bool => (bool) $product['purchasable']));
$lowStockCount = count(array_filter(
    $products,
    static fn (array $product): bool => (bool) $product['track_stock']
        && (int) $product['stock_quantity'] <= (int) $product['low_stock_threshold']
));
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex,nofollow,noarchive">
  <title>Products | InbornFoot Admin</title>
  <link rel="stylesheet" href="/admin/styles.css">
</head>
<body>
  <header class="admin-header">
    <a class="admin-brand" href="/admin/">
      <img src="/logo.png" alt="" width="44" height="44">
      <span><strong>InbornFoot</strong><small>Admin</small></span>
    </a>
    <div class="admin-actions">
      <a href="/admin/">Orders</a>
      <a href="/" target="_blank" rel="noopener">View store ↗</a>
      <form method="post" action="/admin/logout.php">
        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
        <button type="submit">Sign out</button>
      </form>
    </div>
  </header>

  <main class="dashboard">
    <div class="page-heading">
      <div><p class="eyebrow">Catalogue</p><h1>Products</h1><p>Manage storefront content, prices and availability.</p></div>
      <a class="primary-button" href="/admin/products.php?edit=new">Add product</a>
    </div>

    <?php if ($flash): ?><div class="alert alert-<?= h($flash['type']) ?>" role="status"><?= h($flash['message']) ?></div><?php endif; ?>
    <?php if ($databaseError !== ''): ?><div class="alert alert-error" role="alert"><?= h($databaseError) ?></div><?php endif; ?>

    <section class="catalog-metrics" aria-label="Catalogue summary">
      <article><span>Total products</span><strong><?= count($products) ?></strong></article>
      <article><span>Published</span><strong><?= $activeCount ?></strong></article>
      <article><span>Online checkout</span><strong><?= $purchasableCount ?></strong></article>
      <article><span>Low / out of stock</span><strong><?= $lowStockCount ?></strong></article>
    </section>

    <?php if ($showForm): ?>
      <section class="product-editor">
        <div class="editor-heading">
          <div><p class="eyebrow"><?= $editing ? 'Edit product' : 'New product' ?></p><h2><?= h($editing['name'] ?? 'Create a product') ?></h2></div>
          <a href="/admin/products.php">Close ×</a>
        </div>
        <form method="post" action="/admin/products.php" class="product-form" enctype="multipart/form-data">
          <input type="hidden" name="action" value="save">
          <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
          <input type="hidden" name="original_id" value="<?= h($editing['id'] ?? '') ?>">
          <label><span>Product ID</span><input name="id" value="<?= h($form['id'] ?? '') ?>" placeholder="example-product-500g" pattern="[a-z0-9-]{2,64}" required <?= $editing ? 'readonly' : '' ?>><small>Permanent internal identifier</small></label>
          <label><span>URL slug</span><input name="slug" value="<?= h($form['slug'] ?? '') ?>" placeholder="example-product" pattern="[a-z0-9-]{2,100}" required></label>
          <label><span>Name</span><input name="name" value="<?= h($form['name'] ?? '') ?>" maxlength="150" required></label>
          <label><span>Category</span><input name="category" value="<?= h($form['category'] ?? '') ?>" maxlength="80" required></label>
          <label class="wide"><span>Description</span><textarea name="description" rows="4" maxlength="500" required><?= h($form['description'] ?? '') ?></textarea></label>
          <label><span>Price (₹)</span><input name="price" type="number" min="0.01" max="999999.99" step="0.01" value="<?= h($form['price'] ?? '') ?>" placeholder="Leave empty for contact pricing"></label>
          <label><span>Pack size / variant</span><input name="variant" value="<?= h($form['variant'] ?? '') ?>" maxlength="100"></label>
          <label><span>Badge</span><input name="badge" value="<?= h($form['badge'] ?? '') ?>" maxlength="60" placeholder="Best seller"></label>
          <label><span>Display order</span><input name="sort_order" type="number" min="0" max="65535" value="<?= h($form['sort_order'] ?? 0) ?>"></label>
          <label class="wide"><span>Benefits</span><input name="benefits" value="<?= h($form['benefits'] ?? '') ?>" placeholder="Cold pressed, Chemical free, Traditional flavour"><small>Separate benefits with commas</small></label>
          <label class="wide"><span>Image path or HTTPS URL</span><input name="image_url" value="<?= h($form['image_url'] ?? '') ?>" maxlength="500" placeholder="assets/images/products/product.jpg"></label>
          <label class="wide"><span>Upload product image</span><input name="product_image" type="file" accept="image/jpeg,image/png,image/webp"><small>JPEG, PNG or WebP · maximum 5 MB · minimum 300 × 300 px</small></label>
          <label class="wide"><span>Image description</span><input name="alt_text" value="<?= h($form['alt_text'] ?? '') ?>" maxlength="250"></label>
          <div class="product-checks wide">
            <label><input type="checkbox" name="purchasable" value="1" <?= !empty($form['purchasable']) ? 'checked' : '' ?>><span>Available for online checkout</span></label>
            <label><input type="checkbox" name="free_delivery" value="1" <?= !isset($form['free_delivery']) || !empty($form['free_delivery']) ? 'checked' : '' ?>><span>Free delivery</span></label>
            <label><input type="checkbox" name="track_stock" value="1" <?= !empty($form['track_stock']) ? 'checked' : '' ?>><span>Track available stock</span></label>
            <label><input type="checkbox" name="active" value="1" <?= !isset($form['active']) || !empty($form['active']) ? 'checked' : '' ?>><span>Published on storefront</span></label>
          </div>
          <label><span>Stock quantity</span><input name="stock_quantity" type="number" min="0" max="4294967295" value="<?= h($form['stock_quantity'] ?? 0) ?>"></label>
          <label><span>Low-stock warning at</span><input name="low_stock_threshold" type="number" min="0" max="65535" value="<?= h($form['low_stock_threshold'] ?? 5) ?>"></label>
          <div class="form-actions wide"><button class="primary-button" type="submit">Save product</button><a href="/admin/products.php">Cancel</a></div>
        </form>
      </section>
    <?php endif; ?>

    <section class="product-list">
      <div class="product-list-head"><strong><?= count($products) ?> products</strong><span>Changes appear on the storefront within one minute.</span></div>
      <?php if ($products === [] && $databaseError === ''): ?>
        <div class="empty-state"><span>🌿</span><h2>No products yet</h2><p>Create your first catalogue item.</p></div>
      <?php else: ?>
        <?php foreach ($products as $product): $productImageSrc = str_starts_with((string) $product['image_url'], 'https://') ? $product['image_url'] : '/' . ltrim((string) $product['image_url'], '/'); ?>
          <article class="admin-product <?= $product['active'] ? '' : 'is-inactive' ?>">
            <?php if ($product['image_url']): ?>
              <img class="admin-product-image" src="<?= h($productImageSrc) ?>" alt="">
            <?php else: ?>
              <div class="admin-product-image"></div>
            <?php endif; ?>
            <div class="admin-product-copy">
              <div><span><?= h($product['category']) ?></span><span><?= $product['active'] ? 'Published' : 'Hidden' ?></span></div>
              <h2><?= h($product['name']) ?></h2>
              <p>
                <?= h($product['variant']) ?> ·
                <?= $product['price'] === null ? 'Contact for price' : '₹' . number_format((float) $product['price'], 0) ?>
                · <?= $product['free_delivery'] ? 'Free delivery' : 'Delivery calculated separately' ?>
                · <?= $product['track_stock'] ? number_format((int) $product['stock_quantity']) . ' in stock' : 'Stock not tracked' ?>
              </p>
            </div>
            <div class="admin-product-actions">
              <a href="/admin/products.php?edit=<?= rawurlencode((string) $product['id']) ?>">Edit</a>
              <form method="post" action="/admin/products.php">
                <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                <input type="hidden" name="action" value="toggle">
                <input type="hidden" name="id" value="<?= h($product['id']) ?>">
                <input type="hidden" name="active" value="<?= $product['active'] ? '0' : '1' ?>">
                <button type="submit"><?= $product['active'] ? 'Hide' : 'Publish' ?></button>
              </form>
            </div>
          </article>
        <?php endforeach; ?>
      <?php endif; ?>
    </section>
  </main>
</body>
</html>


<?php
session_start();


if (ob_get_level() === 0) ob_start();

if (isset($_SERVER['HTTP_ORIGIN'])) {
    $allowed_origins = [
        'http://127.0.0.1:5500',
        'http://localhost:5500',
        'http://localhost',
        'http://127.0.0.1',
        'http://localhost:80',
        'http://127.0.0.1:80',
    ];

    if (in_array($_SERVER['HTTP_ORIGIN'], $allowed_origins, true)) {
        header("Access-Control-Allow-Origin: " . $_SERVER['HTTP_ORIGIN']);
    } else {

        if (preg_match('/^https?:\/\/(localhost|127\.0\.0\.1)(:\d+)?$/', $_SERVER['HTTP_ORIGIN'])) {
            header("Access-Control-Allow-Origin: " . $_SERVER['HTTP_ORIGIN']);
        }
    }
}
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, X-Requested-With");
header("Access-Control-Allow-Credentials: true");
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(200);
    exit();
}

include 'db.php';

function refreshOrderStatuses($conn)
{
    $conn->query("UPDATE orders SET status = 'delivered', status_updated_at = NOW() WHERE status IN ('pending', 'on_the_way') AND created_at <= DATE_SUB(NOW(), INTERVAL 1 HOUR)");
    $conn->query("UPDATE orders SET status = 'on_the_way', status_updated_at = NOW() WHERE status = 'pending' AND created_at <= DATE_SUB(NOW(), INTERVAL 10 MINUTE) AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)");
}

function getAdminApiKey()
{
    return hash("sha256", "dishdash-admin-panel-local-key");
}

function isAdminAuthenticated()
{
    $sessionAdmin = !empty($_SESSION['dishdash_admin_logged_in']);
    $providedKey = $_POST['admin_key'] ?? '';
    return $sessionAdmin || ($providedKey !== '' && hash_equals(getAdminApiKey(), $providedKey));
}

function requireAdmin($conn)
{
    if (!isAdminAuthenticated()) {
        echo json_encode(["status" => "error", "message" => "Admin access required. Please sign in as an admin."]);
        $conn->close();
        exit();
    }
}

function isValidOrderStatus($status)
{
    return in_array($status, ['pending', 'on_the_way', 'delivered', 'cancelled', 'refunded'], true);
}

function slugifyMenuCategoryName($name)
{
    $slug = strtolower(trim($name));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
    $slug = trim($slug, '-');
    return $slug !== '' ? substr($slug, 0, 80) : 'category';
}

function menuCategorySlugExists($conn, $slug, $ignoreCategoryId = 0)
{
    $stmt = $conn->prepare("SELECT id FROM menu_categories WHERE slug = ? AND id <> ? LIMIT 1");
    if (!$stmt) {
        echo json_encode(["status" => "error", "message" => "Database error: " . $conn->error]);
        $conn->close();
        exit();
    }

    $stmt->bind_param("si", $slug, $ignoreCategoryId);
    $stmt->execute();
    $stmt->store_result();
    $exists = $stmt->num_rows > 0;
    $stmt->close();
    return $exists;
}

function makeUniqueMenuCategorySlug($conn, $name, $ignoreCategoryId = 0)
{
    $base = slugifyMenuCategoryName($name);
    $slug = $base;
    $suffix = 2;

    while (menuCategorySlugExists($conn, $slug, $ignoreCategoryId)) {
        $suffixText = "-" . $suffix;
        $slug = substr($base, 0, 80 - strlen($suffixText)) . $suffixText;
        $suffix++;
    }

    return $slug;
}

function getMenuCategorySlugById($conn, $categoryId)
{
    $stmt = $conn->prepare("SELECT slug FROM menu_categories WHERE id = ? LIMIT 1");
    if (!$stmt) {
        echo json_encode(["status" => "error", "message" => "Database error: " . $conn->error]);
        $conn->close();
        exit();
    }

    $stmt->bind_param("i", $categoryId);
    $stmt->execute();
    $stmt->store_result();

    if ($stmt->num_rows !== 1) {
        $stmt->close();
        return "";
    }

    $stmt->bind_result($slug);
    $stmt->fetch();
    $stmt->close();
    return $slug;
}

function getMenuCategories($conn)
{
    $categories = [];
    $stmt = $conn->prepare("SELECT mc.id, mc.slug, mc.name, mc.image_src, mc.sort_order, mc.created_at, mc.updated_at, COUNT(mi.id) AS item_count FROM menu_categories mc LEFT JOIN menu_items mi ON mi.category = mc.slug GROUP BY mc.id, mc.slug, mc.name, mc.image_src, mc.sort_order, mc.created_at, mc.updated_at ORDER BY mc.sort_order ASC, mc.name ASC");
    if (!$stmt) {
        echo json_encode(["status" => "error", "message" => "Database error: " . $conn->error]);
        $conn->close();
        exit();
    }

    $stmt->execute();
    $stmt->store_result();
    $stmt->bind_result($id, $slug, $name, $image_src, $sort_order, $created_at, $updated_at, $item_count);

    while ($stmt->fetch()) {
        $categories[] = [
            "id" => (int) $id,
            "slug" => $slug,
            "name" => $name,
            "imageSrc" => $image_src,
            "sortOrder" => (int) $sort_order,
            "createdAt" => $created_at,
            "updatedAt" => $updated_at,
            "itemCount" => (int) $item_count
        ];
    }

    $stmt->close();
    return $categories;
}

function getFirstMenuCategorySlug($conn)
{
    $stmt = $conn->prepare("SELECT slug FROM menu_categories ORDER BY sort_order ASC, name ASC LIMIT 1");
    if (!$stmt) {
        echo json_encode(["status" => "error", "message" => "Database error: " . $conn->error]);
        $conn->close();
        exit();
    }

    $stmt->execute();
    $stmt->store_result();

    if ($stmt->num_rows !== 1) {
        $stmt->close();
        return "";
    }

    $stmt->bind_result($slug);
    $stmt->fetch();
    $stmt->close();
    return $slug;
}

function normalizeMenuCategory($conn, $category)
{
    $category = trim($category);

    if ($category !== '') {
        $stmt = $conn->prepare("SELECT slug FROM menu_categories WHERE slug = ? LIMIT 1");
        if (!$stmt) {
            echo json_encode(["status" => "error", "message" => "Database error: " . $conn->error]);
            $conn->close();
            exit();
        }

        $stmt->bind_param("s", $category);
        $stmt->execute();
        $stmt->store_result();

        if ($stmt->num_rows === 1) {
            $stmt->bind_result($slug);
            $stmt->fetch();
            $stmt->close();
            return $slug;
        }

        $stmt->close();
    }

    return getFirstMenuCategorySlug($conn);
}

function getMenuItems($conn)
{
    $menu_items = [];
    $stmt = $conn->prepare("SELECT mi.id, mi.category, COALESCE(mc.name, mi.category), mi.name, mi.description, mi.price, mi.image_src, mi.is_available, mi.created_at, mi.updated_at FROM menu_items mi LEFT JOIN menu_categories mc ON mc.slug = mi.category ORDER BY COALESCE(mc.sort_order, 9999) ASC, COALESCE(mc.name, mi.category) ASC, mi.id ASC");
    if (!$stmt) {
        echo json_encode(["status" => "error", "message" => "Database error: " . $conn->error]);
        $conn->close();
        exit();
    }

    $stmt->execute();
    $stmt->store_result();
    $stmt->bind_result($id, $category, $category_name, $name, $description, $price, $image_src, $is_available, $created_at, $updated_at);

    while ($stmt->fetch()) {
        $menu_items[] = [
            "id" => $id,
            "category" => $category,
            "categoryName" => $category_name,
            "name" => $name,
            "description" => $description,
            "price" => (float) $price,
            "imageSrc" => $image_src,
            "isAvailable" => (bool) $is_available,
            "createdAt" => $created_at,
            "updatedAt" => $updated_at
        ];
    }

    $stmt->close();
    return $menu_items;
}

$response = ["status" => "error", "message" => "Invalid form submission."];
$form_type = $_POST['form_type'] ?? '';

if ($form_type === 'registration') {
    $required_fields = ['firstName', 'lastName', 'birthMonth', 'birthDay', 'phone', 'email', 'password'];

    foreach ($required_fields as $field) {
        if (empty($_POST[$field])) {
            echo json_encode(["status" => "error", "message" => "Please complete all registration fields."]);
            $conn->close();
            exit();
        }
    }

    $first_name  = trim($_POST['firstName']);
    $last_name   = trim($_POST['lastName']);
    $birth_month = trim($_POST['birthMonth']);
    $birth_day   = trim($_POST['birthDay']);
    $phone       = trim($_POST['phone']);
    $email       = trim($_POST['email']);
    $password    = $_POST['password'];
    $confirm_password = $_POST['confirmPassword'] ?? '';

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(["status" => "error", "message" => "Please enter a valid email address."]);
        $conn->close();
        exit();
    }

    if ($confirm_password !== '' && $password !== $confirm_password) {
        echo json_encode(["status" => "error", "message" => "Passwords do not match."]);
        $conn->close();
        exit();
    }

    $checkStmt = $conn->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
    if ($checkStmt) {
        $checkStmt->bind_param("s", $email);
        $checkStmt->execute();
        $checkStmt->store_result();

        if ($checkStmt->num_rows > 0) {
            echo json_encode(["status" => "error", "message" => "This email is already registered. Please sign in instead."]);
            $checkStmt->close();
            $conn->close();
            exit();
        }
        $checkStmt->close();
    }

    $hashed_password = password_hash($password, PASSWORD_DEFAULT);

    $stmt = $conn->prepare("INSERT INTO users (first_name, last_name, birth_month, birth_day, phone, email, password) VALUES (?, ?, ?, ?, ?, ?, ?)");
    if (!$stmt) {
        echo json_encode(["status" => "error", "message" => "Database error: " . $conn->error]);
        $conn->close();
        exit();
    }

    $stmt->bind_param("sssssss", $first_name, $last_name, $birth_month, $birth_day, $phone, $email, $hashed_password);

    if ($stmt->execute()) {
        $response = [
            "status" => "success",
            "message" => "Account created successfully!",
            "user" => [
                "id" => $conn->insert_id,
                "firstName" => $first_name,
                "lastName" => $last_name,
                "birthMonth" => $birth_month,
                "birthDay" => $birth_day,
                "phone" => $phone,
                "email" => $email,
                "role" => "customer"
            ]
        ];
    } else {
        $response = ["status" => "error", "message" => "Error: " . $stmt->error];
    }
    $stmt->close();
} elseif ($form_type === 'login') {
    if (empty($_POST['email']) || empty($_POST['password'])) {
        echo json_encode(["status" => "error", "message" => "Please enter your email and password."]);
        $conn->close();
        exit();
    }

    $email = trim($_POST['email']);
    $password = $_POST['password'];

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(["status" => "error", "message" => "Please enter a valid email address."]);
        $conn->close();
        exit();
    }

    $admin_code = trim($_POST['admin_code'] ?? '');

    if ($admin_code !== '') {
        $stmt = $conn->prepare("SELECT id, name, email, password, admin_code FROM admins WHERE email = ? LIMIT 1");
        if (!$stmt) {
            echo json_encode(["status" => "error", "message" => "Database error: " . $conn->error]);
            $conn->close();
            exit();
        }

        $stmt->bind_param("s", $email);
        $stmt->execute();
        $stmt->store_result();

        if ($stmt->num_rows === 1) {
            $stmt->bind_result($admin_id, $admin_name, $admin_email, $hashed_password, $hashed_admin_code);
            $stmt->fetch();

            if (password_verify($password, $hashed_password) && password_verify($admin_code, $hashed_admin_code)) {
                $_SESSION['dishdash_admin_logged_in'] = true;
                $_SESSION['dishdash_admin_id'] = $admin_id;
                $_SESSION['dishdash_admin_email'] = $admin_email;

                $response = [
                    "status" => "success",
                    "message" => "Admin login successful! Welcome, " . $admin_name . ".",
                    "adminKey" => getAdminApiKey(),
                    "user" => [
                        "id" => $admin_id,
                        "firstName" => $admin_name,
                        "lastName" => "",
                        "birthMonth" => "",
                        "birthDay" => "",
                        "phone" => "",
                        "email" => $admin_email,
                        "role" => "admin"
                    ]
                ];
            } else {
                $response = ["status" => "error", "message" => "Incorrect admin email, password, or admin code."];
            }
        } else {
            $response = ["status" => "error", "message" => "Incorrect admin email, password, or admin code."];
        }

        $stmt->close();
    } else {
        $stmt = $conn->prepare("SELECT id, first_name, last_name, birth_month, birth_day, phone, email, password FROM users WHERE email = ? LIMIT 1");
        if (!$stmt) {
            echo json_encode(["status" => "error", "message" => "Database error: " . $conn->error]);
            $conn->close();
            exit();
        }

        $stmt->bind_param("s", $email);
        $stmt->execute();
        $stmt->store_result();

        if ($stmt->num_rows === 1) {
            $stmt->bind_result($user_id, $first_name, $last_name, $birth_month, $birth_day, $phone, $email, $hashed_password);
            $stmt->fetch();

            if (password_verify($password, $hashed_password)) {
                $_SESSION['dishdash_user_id'] = $user_id;

                $response = [
                    "status" => "success",
                    "message" => "Login successful! Welcome, " . $first_name . ".",
                    "user" => [
                        "id" => $user_id,
                        "firstName" => $first_name,
                        "lastName" => $last_name,
                        "birthMonth" => $birth_month,
                        "birthDay" => $birth_day,
                        "phone" => $phone,
                        "email" => $email,
                        "role" => "customer"
                    ]
                ];
            } else {
                $response = ["status" => "error", "message" => "Incorrect email or password."];
            }
        } else {
            $response = ["status" => "error", "message" => "Incorrect email or password."];
        }

        $stmt->close();
    }
} elseif ($form_type === 'delivery_address') {
    $required_fields = ['street_address', 'city_state', 'zip_code'];

    foreach ($required_fields as $field) {
        if (empty($_POST[$field])) {
            echo json_encode(["status" => "error", "message" => "Please complete all required address fields."]);
            $conn->close();
            exit();
        }
    }

    $street_address = trim($_POST['street_address']);
    $user_id = !empty($_POST['user_id']) ? (int) $_POST['user_id'] : null;
    $apt_suite = trim($_POST['apt_suite'] ?? '');
    $city_state = trim($_POST['city_state']);
    $zip_code = trim($_POST['zip_code']);
    $additional_info = trim($_POST['additional_info'] ?? '');

    $stmt = $conn->prepare("INSERT INTO addresses (user_id, street_address, apt_suite, city_state, zip_code, additional_info) VALUES (?, ?, ?, ?, ?, ?)");
    if (!$stmt) {
        echo json_encode(["status" => "error", "message" => "Database error: " . $conn->error]);
        $conn->close();
        exit();
    }

    $stmt->bind_param("isssss", $user_id, $street_address, $apt_suite, $city_state, $zip_code, $additional_info);

    if ($stmt->execute()) {
        $response = ["status" => "success", "message" => "Delivery address saved successfully!"];
    } else {
        $response = ["status" => "error", "message" => "Error: " . $stmt->error];
    }

    $stmt->close();
} elseif ($form_type === 'add_order') {
    $required_fields = ['product_name', 'product_price', 'quantity', 'payment_method'];

    foreach ($required_fields as $field) {
        if (empty($_POST[$field])) {
            echo json_encode(["status" => "error", "message" => "Missing order details."]);
            $conn->close();
            exit();
        }
    }

    $product_name = trim($_POST['product_name']);
    $user_id = !empty($_POST['user_id']) ? (int) $_POST['user_id'] : null;
    $product_price = trim($_POST['product_price']);
    $quantity = max(1, (int) $_POST['quantity']);

    $image_src = trim($_POST['image_src'] ?? '');
    $payment_method = trim($_POST['payment_method'] ?? 'cod');
    if (!in_array($payment_method, ['cod', 'online'], true)) {
        $payment_method = 'cod';
    }
    $price_number = (float) preg_replace('/[^0-9.]/', '', $product_price);
    $total_price = $price_number * $quantity;
    $status = 'pending';
    $status_updated_at = date('Y-m-d H:i:s');
    $stmt = $conn->prepare("INSERT INTO orders (user_id, product_name, product_price, quantity, total_price, image_src, payment_method, status, status_updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    if (!$stmt) {
        echo json_encode(["status" => "error", "message" => "Database error: " . $conn->error]);
        $conn->close();
        exit();
    }
    $stmt->bind_param("issidssss", $user_id, $product_name, $product_price, $quantity, $total_price, $image_src, $payment_method, $status, $status_updated_at);
    if ($stmt->execute()) {
        $response = [
            "status" => "success",
            "message" => "Order placed successfully!",
            "order" => [
                "id" => $conn->insert_id,
                "productName" => $product_name,
                "productPrice" => $product_price,
                "quantity" => $quantity,
                "totalPrice" => $total_price,
                "imageSrc" => $image_src,
                "paymentMethod" => $payment_method,
                "status" => $status,
                "createdAt" => $status_updated_at,
                "eta" => gmdate("c", time() + 3600)
            ]
        ];
    } else {
        $response = ["status" => "error", "message" => "Error: " . $stmt->error];
    }
    $stmt->close();
} elseif ($form_type === 'cancel_order') {
    $order_id = !empty($_POST['order_id']) ? (int) $_POST['order_id'] : 0;
    $user_id = !empty($_POST['user_id']) ? (int) $_POST['user_id'] : 0;

    if ($order_id <= 0 || $user_id <= 0) {
        echo json_encode(["status" => "error", "message" => "Invalid order cancellation request."]);
        $conn->close();
        exit();
    }

    refreshOrderStatuses($conn);

    $stmt = $conn->prepare("SELECT status FROM orders WHERE id = ? AND user_id = ? LIMIT 1");
    if (!$stmt) {
        echo json_encode(["status" => "error", "message" => "Database error: " . $conn->error]);
        $conn->close();
        exit();
    }

    $stmt->bind_param("ii", $order_id, $user_id);
    $stmt->execute();
    $stmt->store_result();

    if ($stmt->num_rows !== 1) {
        $stmt->close();
        echo json_encode(["status" => "error", "message" => "Order not found."]);
        $conn->close();
        exit();
    }

    $stmt->bind_result($current_status);
    $stmt->fetch();
    $stmt->close();

    if ($current_status !== 'pending') {
        echo json_encode(["status" => "error", "message" => "This order can no longer be cancelled because it is already on the way."]);
        $conn->close();
        exit();
    }

    $status = 'cancelled';
    $stmt = $conn->prepare("UPDATE orders SET status = ?, status_updated_at = NOW() WHERE id = ? AND user_id = ? AND status = 'pending'");
    if (!$stmt) {
        echo json_encode(["status" => "error", "message" => "Database error: " . $conn->error]);
        $conn->close();
        exit();
    }

    $stmt->bind_param("sii", $status, $order_id, $user_id);

    if ($stmt->execute() && $stmt->affected_rows === 1) {
        $response = ["status" => "success", "message" => "Pending order cancelled successfully."];
    } else {
        $response = ["status" => "error", "message" => "This order can no longer be cancelled."];
    }

    $stmt->close();
} elseif ($form_type === 'get_menu_items') {
    $response = [
        "status" => "success",
        "menuItems" => getMenuItems($conn),
        "menuCategories" => getMenuCategories($conn)
    ];
} elseif ($form_type === 'admin_logout') {
    unset($_SESSION['dishdash_admin_logged_in'], $_SESSION['dishdash_admin_id'], $_SESSION['dishdash_admin_email']);
    $response = ["status" => "success", "message" => "Admin logged out successfully."];
} elseif ($form_type === 'admin_get_dashboard') {
    requireAdmin($conn);
    refreshOrderStatuses($conn);

    $orders = [];
    $users = [];
    $stats = [
        "total" => 0,
        "pending" => 0,
        "on_the_way" => 0,
        "delivered" => 0,
        "cancelled" => 0,
        "refunded" => 0
    ];

    $stmt = $conn->prepare("SELECT o.id, o.user_id, o.product_name, o.product_price, o.quantity, o.total_price, o.image_src, o.payment_method, o.status, o.status_updated_at, o.created_at, COALESCE(u.first_name, ''), COALESCE(u.last_name, ''), COALESCE(u.email, '') FROM orders o LEFT JOIN users u ON o.user_id = u.id ORDER BY o.id DESC");
    if (!$stmt) {
        echo json_encode(["status" => "error", "message" => "Database error: " . $conn->error]);
        $conn->close();
        exit();
    }

    $stmt->execute();
    $stmt->store_result();
    $stmt->bind_result($order_id, $user_id, $product_name, $product_price, $quantity, $total_price, $image_src, $payment_method, $status, $status_updated_at, $created_at, $first_name, $last_name, $email);

    while ($stmt->fetch()) {
        $status = $status ?: 'pending';
        $stats["total"]++;
        if (array_key_exists($status, $stats)) {
            $stats[$status]++;
        }

        $orders[] = [
            "id" => $order_id,
            "userId" => $user_id,
            "customerName" => trim($first_name . " " . $last_name) ?: "Guest",
            "customerEmail" => $email,
            "productName" => $product_name,
            "productPrice" => $product_price,
            "quantity" => $quantity,
            "totalPrice" => $total_price,
            "imageSrc" => $image_src,
            "paymentMethod" => $payment_method,
            "status" => $status,
            "statusUpdatedAt" => $status_updated_at,
            "createdAt" => $created_at
        ];
    }
    $stmt->close();

    $stmt = $conn->prepare("SELECT u.id, u.first_name, u.last_name, u.birth_month, u.birth_day, u.phone, u.email, u.created_at, COUNT(o.id), COALESCE(SUM(o.total_price), 0) FROM users u LEFT JOIN orders o ON o.user_id = u.id GROUP BY u.id, u.first_name, u.last_name, u.birth_month, u.birth_day, u.phone, u.email, u.created_at ORDER BY u.id DESC");
    if (!$stmt) {
        echo json_encode(["status" => "error", "message" => "Database error: " . $conn->error]);
        $conn->close();
        exit();
    }

    $stmt->execute();
    $stmt->store_result();
    $stmt->bind_result($user_id, $first_name, $last_name, $birth_month, $birth_day, $phone, $email, $created_at, $order_count, $total_spent);

    while ($stmt->fetch()) {
        $users[] = [
            "id" => $user_id,
            "firstName" => $first_name,
            "lastName" => $last_name,
            "birthMonth" => $birth_month,
            "birthDay" => $birth_day,
            "phone" => $phone,
            "email" => $email,
            "createdAt" => $created_at,
            "orderCount" => (int) $order_count,
            "totalSpent" => (float) $total_spent
        ];
    }
    $stmt->close();

    $response = [
        "status" => "success",
        "orders" => $orders,
        "menuItems" => getMenuItems($conn),
        "menuCategories" => getMenuCategories($conn),
        "users" => $users,
        "stats" => $stats
    ];
} elseif ($form_type === 'admin_update_order_status') {
    requireAdmin($conn);

    $order_id = !empty($_POST['order_id']) ? (int) $_POST['order_id'] : 0;
    $new_status = trim($_POST['status'] ?? '');

    if ($order_id <= 0 || !isValidOrderStatus($new_status)) {
        echo json_encode(["status" => "error", "message" => "Invalid order status update."]);
        $conn->close();
        exit();
    }

    $stmt = $conn->prepare("UPDATE orders SET status = ?, status_updated_at = NOW() WHERE id = ?");
    if (!$stmt) {
        echo json_encode(["status" => "error", "message" => "Database error: " . $conn->error]);
        $conn->close();
        exit();
    }

    $stmt->bind_param("si", $new_status, $order_id);
    if ($stmt->execute()) {
        $response = ["status" => "success", "message" => "Order status updated."];
    } else {
        $response = ["status" => "error", "message" => "Error: " . $stmt->error];
    }
    $stmt->close();
} elseif ($form_type === 'admin_cancel_order' || $form_type === 'admin_refund_order') {
    requireAdmin($conn);

    $order_id = !empty($_POST['order_id']) ? (int) $_POST['order_id'] : 0;
    if ($order_id <= 0) {
        echo json_encode(["status" => "error", "message" => "Invalid order request."]);
        $conn->close();
        exit();
    }

    $new_status = $form_type === 'admin_refund_order' ? 'refunded' : 'cancelled';
    $stmt = $conn->prepare("UPDATE orders SET status = ?, status_updated_at = NOW() WHERE id = ?");
    if (!$stmt) {
        echo json_encode(["status" => "error", "message" => "Database error: " . $conn->error]);
        $conn->close();
        exit();
    }

    $stmt->bind_param("si", $new_status, $order_id);
    if ($stmt->execute()) {
        $response = ["status" => "success", "message" => $new_status === 'refunded' ? "Order marked as refunded." : "Order cancelled."];
    } else {
        $response = ["status" => "error", "message" => "Error: " . $stmt->error];
    }
    $stmt->close();
} elseif ($form_type === 'admin_save_menu_category') {
    requireAdmin($conn);

    $category_id = !empty($_POST['category_id']) ? (int) $_POST['category_id'] : 0;
    $name = trim($_POST['name'] ?? '');
    $image_src = trim($_POST['image_src'] ?? '');
    $sort_order = (int) ($_POST['sort_order'] ?? 0);

    if ($name === '') {
        echo json_encode(["status" => "error", "message" => "Please enter a category name."]);
        $conn->close();
        exit();
    }

    $slug = makeUniqueMenuCategorySlug($conn, $name, $category_id);

    if ($category_id > 0) {
        $old_slug = getMenuCategorySlugById($conn, $category_id);

        if ($old_slug === '') {
            echo json_encode(["status" => "error", "message" => "Menu category not found."]);
            $conn->close();
            exit();
        }

        $conn->begin_transaction();
        $stmt = $conn->prepare("UPDATE menu_categories SET slug = ?, name = ?, image_src = ?, sort_order = ? WHERE id = ?");
        if (!$stmt) {
            $conn->rollback();
            echo json_encode(["status" => "error", "message" => "Database error: " . $conn->error]);
            $conn->close();
            exit();
        }

        $stmt->bind_param("sssii", $slug, $name, $image_src, $sort_order, $category_id);
        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            $conn->rollback();
            echo json_encode(["status" => "error", "message" => "Error: " . $error]);
            $conn->close();
            exit();
        }
        $stmt->close();

        if ($old_slug !== $slug) {
            $stmt = $conn->prepare("UPDATE menu_items SET category = ? WHERE category = ?");
            if (!$stmt) {
                $conn->rollback();
                echo json_encode(["status" => "error", "message" => "Database error: " . $conn->error]);
                $conn->close();
                exit();
            }

            $stmt->bind_param("ss", $slug, $old_slug);
            if (!$stmt->execute()) {
                $error = $stmt->error;
                $stmt->close();
                $conn->rollback();
                echo json_encode(["status" => "error", "message" => "Error: " . $error]);
                $conn->close();
                exit();
            }
            $stmt->close();
        }

        $conn->commit();
        $response = ["status" => "success", "message" => "Menu category updated.", "categoryId" => $category_id];
    } else {
        $stmt = $conn->prepare("INSERT INTO menu_categories (slug, name, image_src, sort_order) VALUES (?, ?, ?, ?)");
        if (!$stmt) {
            echo json_encode(["status" => "error", "message" => "Database error: " . $conn->error]);
            $conn->close();
            exit();
        }

        $stmt->bind_param("sssi", $slug, $name, $image_src, $sort_order);
        if ($stmt->execute()) {
            $response = ["status" => "success", "message" => "Menu category added.", "categoryId" => $conn->insert_id];
        } else {
            $response = ["status" => "error", "message" => "Error: " . $stmt->error];
        }
        $stmt->close();
    }
} elseif ($form_type === 'admin_delete_menu_category') {
    requireAdmin($conn);

    $category_id = !empty($_POST['category_id']) ? (int) $_POST['category_id'] : 0;
    if ($category_id <= 0) {
        echo json_encode(["status" => "error", "message" => "Invalid menu category."]);
        $conn->close();
        exit();
    }

    $category_slug = getMenuCategorySlugById($conn, $category_id);
    if ($category_slug === '') {
        echo json_encode(["status" => "error", "message" => "Menu category not found."]);
        $conn->close();
        exit();
    }

    $conn->begin_transaction();

    $stmt = $conn->prepare("DELETE FROM menu_items WHERE category = ?");
    if (!$stmt) {
        $conn->rollback();
        echo json_encode(["status" => "error", "message" => "Database error: " . $conn->error]);
        $conn->close();
        exit();
    }
    $stmt->bind_param("s", $category_slug);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        $conn->rollback();
        echo json_encode(["status" => "error", "message" => "Error: " . $error]);
        $conn->close();
        exit();
    }
    $stmt->close();

    $stmt = $conn->prepare("DELETE FROM menu_categories WHERE id = ?");
    if (!$stmt) {
        $conn->rollback();
        echo json_encode(["status" => "error", "message" => "Database error: " . $conn->error]);
        $conn->close();
        exit();
    }
    $stmt->bind_param("i", $category_id);
    if ($stmt->execute()) {
        $conn->commit();
        $response = ["status" => "success", "message" => "Menu category and its food items were deleted."];
    } else {
        $error = $stmt->error;
        $conn->rollback();
        $response = ["status" => "error", "message" => "Error: " . $error];
    }
    $stmt->close();
} elseif ($form_type === 'admin_save_menu_item') {
    requireAdmin($conn);

    $item_id = !empty($_POST['item_id']) ? (int) $_POST['item_id'] : 0;
    $category = normalizeMenuCategory($conn, $_POST['category'] ?? '');
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $price = (float) preg_replace('/[^0-9.]/', '', $_POST['price'] ?? '0');
    $image_src = trim($_POST['image_src'] ?? '');
    $is_available = (int) ($_POST['is_available'] ?? 1) === 1 ? 1 : 0;

    if ($category === '') {
        echo json_encode(["status" => "error", "message" => "Please create a menu category before adding food items."]);
        $conn->close();
        exit();
    }

    if ($name === '' || $price <= 0) {
        echo json_encode(["status" => "error", "message" => "Please enter a food name and valid price."]);
        $conn->close();
        exit();
    }

    if ($item_id > 0) {
        $stmt = $conn->prepare("UPDATE menu_items SET category = ?, name = ?, description = ?, price = ?, image_src = ?, is_available = ? WHERE id = ?");
        if (!$stmt) {
            echo json_encode(["status" => "error", "message" => "Database error: " . $conn->error]);
            $conn->close();
            exit();
        }
        $stmt->bind_param("sssdsii", $category, $name, $description, $price, $image_src, $is_available, $item_id);
    } else {
        $stmt = $conn->prepare("INSERT INTO menu_items (category, name, description, price, image_src, is_available) VALUES (?, ?, ?, ?, ?, ?)");
        if (!$stmt) {
            echo json_encode(["status" => "error", "message" => "Database error: " . $conn->error]);
            $conn->close();
            exit();
        }
        $stmt->bind_param("sssdsi", $category, $name, $description, $price, $image_src, $is_available);
    }

    if ($stmt->execute()) {
        $response = [
            "status" => "success",
            "message" => $item_id > 0 ? "Menu item updated." : "Menu item added.",
            "itemId" => $item_id > 0 ? $item_id : $conn->insert_id
        ];
    } else {
        $response = ["status" => "error", "message" => "Error: " . $stmt->error];
    }
    $stmt->close();
} elseif ($form_type === 'admin_delete_menu_item') {
    requireAdmin($conn);

    $item_id = !empty($_POST['item_id']) ? (int) $_POST['item_id'] : 0;
    if ($item_id <= 0) {
        echo json_encode(["status" => "error", "message" => "Invalid menu item."]);
        $conn->close();
        exit();
    }

    $stmt = $conn->prepare("DELETE FROM menu_items WHERE id = ?");
    if (!$stmt) {
        echo json_encode(["status" => "error", "message" => "Database error: " . $conn->error]);
        $conn->close();
        exit();
    }

    $stmt->bind_param("i", $item_id);
    if ($stmt->execute()) {
        $response = ["status" => "success", "message" => "Menu item deleted."];
    } else {
        $response = ["status" => "error", "message" => "Error: " . $stmt->error];
    }
    $stmt->close();
} elseif ($form_type === 'admin_toggle_menu_item') {
    requireAdmin($conn);

    $item_id = !empty($_POST['item_id']) ? (int) $_POST['item_id'] : 0;
    $is_available = (int) ($_POST['is_available'] ?? 1) === 1 ? 1 : 0;

    if ($item_id <= 0) {
        echo json_encode(["status" => "error", "message" => "Invalid menu item."]);
        $conn->close();
        exit();
    }

    $stmt = $conn->prepare("UPDATE menu_items SET is_available = ? WHERE id = ?");
    if (!$stmt) {
        echo json_encode(["status" => "error", "message" => "Database error: " . $conn->error]);
        $conn->close();
        exit();
    }

    $stmt->bind_param("ii", $is_available, $item_id);
    if ($stmt->execute()) {
        $response = ["status" => "success", "message" => $is_available ? "Item marked in stock." : "Item marked out of stock."];
    } else {
        $response = ["status" => "error", "message" => "Error: " . $stmt->error];
    }
    $stmt->close();
} elseif ($form_type === 'get_profile_data') {
    $user_id = !empty($_POST['user_id']) ? (int) $_POST['user_id'] : 0;
    $user = null;
    $address = null;
    $orders = [];

    if ($user_id > 0) {
        $stmt = $conn->prepare("SELECT id, first_name, last_name, birth_month, birth_day, phone, email FROM users WHERE id = ? LIMIT 1");
        if (!$stmt) {
            echo json_encode(["status" => "error", "message" => "Database error: " . $conn->error]);
            $conn->close();
            exit();
        }

        $stmt->bind_param("i", $user_id);
    } else {
        $stmt = $conn->prepare("SELECT id, first_name, last_name, birth_month, birth_day, phone, email FROM users ORDER BY id DESC LIMIT 1");
        if (!$stmt) {
            echo json_encode(["status" => "error", "message" => "Database error: " . $conn->error]);
            $conn->close();
            exit();
        }
    }

    $stmt->execute();
    $stmt->store_result();

    if ($stmt->num_rows === 1) {
        $stmt->bind_result($db_user_id, $first_name, $last_name, $birth_month, $birth_day, $phone, $email);
        $stmt->fetch();
        $user = [
            "id" => $db_user_id,
            "firstName" => $first_name,
            "lastName" => $last_name,
            "birthMonth" => $birth_month,
            "birthDay" => $birth_day,
            "phone" => $phone,
            "email" => $email,
            "role" => "customer"
        ];
        $user_id = $db_user_id;
    }
    $stmt->close();

    if (!$user) {
        echo json_encode(["status" => "error", "message" => "No user profile found."]);
        $conn->close();
        exit();
    }

    $stmt = $conn->prepare("SELECT street_address, apt_suite, city_state, zip_code, additional_info FROM addresses WHERE user_id = ? OR user_id IS NULL ORDER BY CASE WHEN user_id = ? THEN 0 ELSE 1 END, id DESC LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("ii", $user_id, $user_id);
        $stmt->execute();
        $stmt->store_result();

        if ($stmt->num_rows === 1) {
            $stmt->bind_result($street_address, $apt_suite, $city_state, $zip_code, $additional_info);
            $stmt->fetch();
            $address = [
                "streetAddress" => $street_address,
                "aptSuite" => $apt_suite,
                "cityState" => $city_state,
                "zipCode" => $zip_code,
                "additionalInfo" => $additional_info
            ];
        }
        $stmt->close();
    }

    refreshOrderStatuses($conn);

    $stmt = $conn->prepare("SELECT id, product_name, product_price, quantity, total_price, image_src, payment_method, status, created_at FROM orders WHERE user_id = ? OR user_id IS NULL ORDER BY CASE WHEN user_id = ? THEN 0 ELSE 1 END, id DESC");
    if ($stmt) {
        $stmt->bind_param("ii", $user_id, $user_id);
        $stmt->execute();
        $stmt->store_result();
        $stmt->bind_result($order_id, $product_name, $product_price, $quantity, $total_price, $image_src, $payment_method, $status, $created_at);

        while ($stmt->fetch()) {
            $orders[] = [
                "id" => $order_id,
                "productName" => $product_name,
                "productPrice" => $product_price,
                "quantity" => $quantity,
                "totalPrice" => $total_price,
                "imageSrc" => $image_src,
                "paymentMethod" => $payment_method,
                "status" => $status,
                "createdAt" => $created_at
            ];
        }
        $stmt->close();
    }

    $response = [
        "status" => "success",
        "user" => $user,
        "address" => $address,
        "orders" => $orders
    ];
} elseif ($form_type === 'update_profile') {
    $user_id = !empty($_POST['user_id']) ? (int) $_POST['user_id'] : 0;
    if ($user_id <= 0) {
        echo json_encode(["status" => "error", "message" => "Invalid user session."]);
        $conn->close();
        exit();
    }

    $required_fields = ['firstName', 'lastName', 'phone', 'email'];
    foreach ($required_fields as $field) {
        if (empty($_POST[$field])) {
            echo json_encode(["status" => "error", "message" => "Please complete all required fields."]);
            $conn->close();
            exit();
        }
    }

    $first_name  = trim($_POST['firstName']);
    $last_name   = trim($_POST['lastName']);
    $birth_month = trim($_POST['birthMonth'] ?? '');
    $birth_day   = trim($_POST['birthDay'] ?? '');
    $phone       = trim($_POST['phone']);
    $email       = trim($_POST['email']);

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(["status" => "error", "message" => "Please enter a valid email address."]);
        $conn->close();
        exit();
    }

    $checkStmt = $conn->prepare("SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1");
    if ($checkStmt) {
        $checkStmt->bind_param("si", $email, $user_id);
        $checkStmt->execute();
        $checkStmt->store_result();

        if ($checkStmt->num_rows > 0) {
            echo json_encode(["status" => "error", "message" => "That email is already used by another account."]);
            $checkStmt->close();
            $conn->close();
            exit();
        }
        $checkStmt->close();
    }

    $stmt = $conn->prepare("UPDATE users SET first_name=?, last_name=?, birth_month=?, birth_day=?, phone=?, email=? WHERE id=?");
    if (!$stmt) {
        echo json_encode(["status" => "error", "message" => "Database error: " . $conn->error]);
        $conn->close();
        exit();
    }

    $stmt->bind_param("ssssssi", $first_name, $last_name, $birth_month, $birth_day, $phone, $email, $user_id);

    if ($stmt->execute()) {
        $response = [
            "status" => "success",
            "message" => "Profile updated successfully!",
            "user" => [
                "id" => $user_id,
                "firstName" => $first_name,
                "lastName" => $last_name,
                "birthMonth" => $birth_month,
                "birthDay" => $birth_day,
                "phone" => $phone,
                "email" => $email,
                "role" => "customer"
            ]
        ];
    } else {
        $response = ["status" => "error", "message" => "Error: " . $stmt->error];
    }
    $stmt->close();

} elseif ($form_type === 'update_address') {
    $user_id = !empty($_POST['user_id']) ? (int) $_POST['user_id'] : null;
    if (!$user_id) {
        echo json_encode(["status" => "error", "message" => "Invalid user session. Please sign in again."]);
        $conn->close();
        exit();
    }

    $required_fields = ['street_address', 'city_state', 'zip_code'];

    foreach ($required_fields as $field) {
        if (empty($_POST[$field])) {
            echo json_encode(["status" => "error", "message" => "Please complete all required address fields."]);
            $conn->close();
            exit();
        }
    }

    $street_address  = trim($_POST['street_address']);
    $apt_suite       = trim($_POST['apt_suite'] ?? '');
    $city_state      = trim($_POST['city_state']);
    $zip_code        = trim($_POST['zip_code']);
    $additional_info = trim($_POST['additional_info'] ?? '');

    // Check if address exists for user
    $checkStmt = $conn->prepare("SELECT id FROM addresses WHERE user_id = ? LIMIT 1");
    $exists = false;
    if ($checkStmt && $user_id) {
        $checkStmt->bind_param("i", $user_id);
        $checkStmt->execute();
        $checkStmt->store_result();
        $exists = $checkStmt->num_rows > 0;
        $checkStmt->close();
    }

    if ($exists && $user_id) {
        $stmt = $conn->prepare("UPDATE addresses SET street_address=?, apt_suite=?, city_state=?, zip_code=?, additional_info=? WHERE user_id=?");
        if (!$stmt) {
            echo json_encode(["status" => "error", "message" => "Database error: " . $conn->error]);
            $conn->close();
            exit();
        }
        $stmt->bind_param("sssssi", $street_address, $apt_suite, $city_state, $zip_code, $additional_info, $user_id);
    } else {
        $stmt = $conn->prepare("INSERT INTO addresses (user_id, street_address, apt_suite, city_state, zip_code, additional_info) VALUES (?, ?, ?, ?, ?, ?)");
        if (!$stmt) {
            echo json_encode(["status" => "error", "message" => "Database error: " . $conn->error]);
            $conn->close();
            exit();
        }
        $stmt->bind_param("isssss", $user_id, $street_address, $apt_suite, $city_state, $zip_code, $additional_info);
    }

    if ($stmt->execute()) {
        $response = [
            "status" => "success",
            "message" => "Address updated successfully!",
            "address" => [
                "streetAddress" => $street_address,
                "aptSuite" => $apt_suite,
                "cityState" => $city_state,
                "zipCode" => $zip_code,
                "additionalInfo" => $additional_info
            ]
        ];
    } else {
        $response = ["status" => "error", "message" => "Error: " . $stmt->error];
    }
    $stmt->close();
}

echo json_encode($response);
$conn->close();
?>

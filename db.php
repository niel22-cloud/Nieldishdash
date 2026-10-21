<?php
$host = "localhost";
$user = "root";
$password = "";
$database = "dishdash_db";

mysqli_report(MYSQLI_REPORT_OFF);

function stopWithDatabaseError($message, $detail = "")
{
    http_response_code(500);
    echo json_encode([
        "status" => "error",
        "message" => $detail ? $message . ": " . $detail : $message
    ]);
    exit();
}

$conn = new mysqli($host, $user, $password);

if ($conn->connect_error) {
    stopWithDatabaseError("Database connection failed. Please make sure WAMP MySQL is running", $conn->connect_error);
}

$conn->set_charset("utf8mb4");

if (!$conn->query("CREATE DATABASE IF NOT EXISTS `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci")) {
    stopWithDatabaseError("Could not create/select the project database", $conn->error);
}

if (!$conn->select_db($database)) {
    stopWithDatabaseError("Could not select the project database", $conn->error);
}

$schemaStatements = [
    "CREATE TABLE IF NOT EXISTS admins (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(120) NOT NULL,
        email VARCHAR(150) NOT NULL UNIQUE,
        password VARCHAR(255) NOT NULL,
        admin_code VARCHAR(255) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
    "CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        first_name VARCHAR(100) NOT NULL,
        last_name VARCHAR(100) NOT NULL,
        birth_month VARCHAR(20) NOT NULL,
        birth_day VARCHAR(5) NOT NULL,
        phone VARCHAR(30) NOT NULL,
        email VARCHAR(150) NOT NULL UNIQUE,
        password VARCHAR(255) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
    "CREATE TABLE IF NOT EXISTS addresses (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NULL,
        street_address VARCHAR(255) NOT NULL,
        apt_suite VARCHAR(100) DEFAULT '',
        city_state VARCHAR(150) NOT NULL,
        zip_code VARCHAR(20) NOT NULL,
        additional_info TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_addresses_user_id (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
    "CREATE TABLE IF NOT EXISTS orders (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NULL,
        product_name VARCHAR(180) NOT NULL,
        product_price VARCHAR(50) NOT NULL,
        quantity INT NOT NULL DEFAULT 1,
        total_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        image_src TEXT NULL,
        payment_method VARCHAR(30) NOT NULL DEFAULT 'cod',
        status VARCHAR(30) NOT NULL DEFAULT 'pending',
        status_updated_at DATETIME NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_orders_user_id (user_id),
        INDEX idx_orders_created_at (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
    "CREATE TABLE IF NOT EXISTS menu_categories (
        id INT AUTO_INCREMENT PRIMARY KEY,
        slug VARCHAR(80) NOT NULL UNIQUE,
        name VARCHAR(120) NOT NULL,
        image_src TEXT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_menu_categories_sort (sort_order)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
    "CREATE TABLE IF NOT EXISTS menu_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        category VARCHAR(80) NOT NULL,
        name VARCHAR(180) NOT NULL,
        description TEXT NULL,
        price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        image_src TEXT NULL,
        is_available TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_menu_category (category),
        INDEX idx_menu_available (is_available)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
];

foreach ($schemaStatements as $statement) {
    if (!$conn->query($statement)) {
        stopWithDatabaseError("Could not prepare the database tables", $conn->error);
    }
}

function ensureColumn($conn, $table, $column, $definition)
{
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $column = preg_replace('/[^a-zA-Z0-9_]/', '', $column);
    $result = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");

    if ($result && $result->num_rows === 0) {
        if (!$conn->query("ALTER TABLE `$table` ADD COLUMN `$column` $definition")) {
            stopWithDatabaseError("Could not update the `$table` table", $conn->error);
        }
    }

    if ($result) {
        $result->close();
    }
}

ensureColumn($conn, "users", "created_at", "TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
ensureColumn($conn, "admins", "name", "VARCHAR(120) NOT NULL DEFAULT 'DishDash Admin'");
ensureColumn($conn, "admins", "admin_code", "VARCHAR(255) NOT NULL DEFAULT ''");
ensureColumn($conn, "admins", "created_at", "TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
ensureColumn($conn, "addresses", "user_id", "INT NULL");
ensureColumn($conn, "addresses", "created_at", "TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
ensureColumn($conn, "addresses", "updated_at", "TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
ensureColumn($conn, "orders", "payment_method", "VARCHAR(30) NOT NULL DEFAULT 'cod'");
ensureColumn($conn, "orders", "status", "VARCHAR(30) NOT NULL DEFAULT 'pending'");
ensureColumn($conn, "orders", "status_updated_at", "DATETIME NULL");
ensureColumn($conn, "orders", "created_at", "TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
ensureColumn($conn, "menu_categories", "slug", "VARCHAR(80) NOT NULL DEFAULT ''");
ensureColumn($conn, "menu_categories", "name", "VARCHAR(120) NOT NULL DEFAULT ''");
ensureColumn($conn, "menu_categories", "image_src", "TEXT NULL");
ensureColumn($conn, "menu_categories", "sort_order", "INT NOT NULL DEFAULT 0");
ensureColumn($conn, "menu_categories", "created_at", "TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
ensureColumn($conn, "menu_categories", "updated_at", "TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
ensureColumn($conn, "menu_items", "category", "VARCHAR(80) NOT NULL DEFAULT 'main'");
ensureColumn($conn, "menu_items", "name", "VARCHAR(180) NOT NULL DEFAULT ''");
ensureColumn($conn, "menu_items", "description", "TEXT NULL");
ensureColumn($conn, "menu_items", "price", "DECIMAL(10,2) NOT NULL DEFAULT 0.00");
ensureColumn($conn, "menu_items", "image_src", "TEXT NULL");
ensureColumn($conn, "menu_items", "is_available", "TINYINT(1) NOT NULL DEFAULT 1");
ensureColumn($conn, "menu_items", "created_at", "TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
ensureColumn($conn, "menu_items", "updated_at", "TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");

function seedDefaultAdmin($conn)
{
    $result = $conn->query("SELECT COUNT(*) AS total FROM admins");
    $row = $result ? $result->fetch_assoc() : ["total" => 0];

    if ($result) {
        $result->close();
    }

    if ((int) ($row["total"] ?? 0) > 0) {
        return;
    }

    $name = "DishDash Admin";
    $email = "admin@dishdash.test";
    $password = password_hash("admin123", PASSWORD_DEFAULT);
    $adminCode = password_hash("DISHDASH-ADMIN", PASSWORD_DEFAULT);

    $stmt = $conn->prepare("INSERT INTO admins (name, email, password, admin_code) VALUES (?, ?, ?, ?)");
    if (!$stmt) {
        stopWithDatabaseError("Could not seed the default admin", $conn->error);
    }

    $stmt->bind_param("ssss", $name, $email, $password, $adminCode);
    if (!$stmt->execute()) {
        stopWithDatabaseError("Could not seed the default admin", $stmt->error);
    }
    $stmt->close();
}

function seedDefaultMenuCategories($conn)
{
    $result = $conn->query("SELECT COUNT(*) AS total FROM menu_categories");
    $row = $result ? $result->fetch_assoc() : ["total" => 0];

    if ($result) {
        $result->close();
    }

    if ((int) ($row["total"] ?? 0) > 0) {
        return;
    }

    $categories = [
        ["main", "Main Courses", "src/images/featuredMenuCat/img1.jpg", 10],
        ["desserts", "Desserts", "src/images/featuredMenuCat/img2.jpg", 20],
        ["beverages", "Beverages", "src/images/featuredMenuCat/img3.jpg", 30]
    ];

    $stmt = $conn->prepare("INSERT INTO menu_categories (slug, name, image_src, sort_order) VALUES (?, ?, ?, ?)");
    if (!$stmt) {
        stopWithDatabaseError("Could not seed menu categories", $conn->error);
    }

    foreach ($categories as $category) {
        [$slug, $name, $imageSrc, $sortOrder] = $category;
        $stmt->bind_param("sssi", $slug, $name, $imageSrc, $sortOrder);
        if (!$stmt->execute()) {
            stopWithDatabaseError("Could not seed menu categories", $stmt->error);
        }
    }

    $stmt->close();
}

function seedDefaultMenuItems($conn)
{
    $result = $conn->query("SELECT COUNT(*) AS total FROM menu_items");
    $row = $result ? $result->fetch_assoc() : ["total" => 0];

    if ($result) {
        $result->close();
    }

    if ((int) ($row["total"] ?? 0) > 0) {
        return;
    }

    $items = [
        ["main", "Grilled Ribeye Steak", "Tender grilled ribeye served with savory sides.", 620.00, "src/images/main courses/img1.jpg"],
        ["main", "Pan-Seared Salmon", "Golden salmon fillet with a fresh, bright finish.", 540.00, "src/images/main courses/img7.jpg"],
        ["main", "Herb-Roasted Chicken", "Juicy roasted chicken seasoned with garden herbs.", 390.00, "src/images/main courses/img9.jpg"],
        ["main", "Spaghetti Carbonara", "Creamy pasta tossed with smoky, savory flavors.", 310.00, "src/images/main courses/img6.jpg"],
        ["main", "Thai Green Curry", "Aromatic curry with vegetables and warm spices.", 340.00, "src/images/main courses/img5.jpg"],
        ["main", "Classic Fish and Chips", "Crisp battered fish with golden fries.", 360.00, "src/images/main courses/img4.jpg"],
        ["main", "Traditional Moussaka", "Layered baked comfort food with rich sauce.", 420.00, "src/images/main courses/img10.jpg"],
        ["main", "BBQ Bacon Cheeseburger", "Smoky burger stacked with bacon and barbecue sauce.", 350.00, "src/images/main courses/img2.jpg"],
        ["main", "Sizzling Chicken Fajitas", "Seasoned chicken with peppers and onions.", 380.00, "src/images/main courses/img3.jpg"],
        ["main", "Hearty Beef Stew", "Slow-cooked beef stew with deep, comforting flavor.", 410.00, "src/images/main courses/img8.jpg"],
        ["beverages", "Freshly Brewed Latte", "Smooth espresso with steamed milk.", 160.00, "src/images/beverages/img3.jpg"],
        ["beverages", "Refreshing Iced Tea", "Chilled tea with a clean, crisp taste.", 120.00, "src/images/beverages/img4.jpg"],
        ["beverages", "Strawberry Milkshake", "Creamy strawberry shake blended to order.", 180.00, "src/images/beverages/img10.jpg"],
        ["beverages", "Fresh Squeezed Orange Juice", "Bright citrus juice served fresh.", 150.00, "src/images/beverages/img7.jpg"],
        ["beverages", "Classic Red Wine", "A balanced red wine for hearty meals.", 260.00, "src/images/beverages/img9.jpg"],
        ["beverages", "Classic Mojito Cocktail", "Minty citrus cocktail with a sparkling finish.", 220.00, "src/images/beverages/img2.jpg"],
        ["beverages", "Rich Hot Chocolate", "Warm chocolate drink with a velvety body.", 150.00, "src/images/beverages/img5.jpg"],
        ["beverages", "Elegant Champagne Flute", "Crisp sparkling drink for celebrations.", 290.00, "src/images/beverages/img6.jpg"],
        ["beverages", "Healthy Green Smoothie", "Fresh green smoothie packed with fruit and greens.", 190.00, "src/images/beverages/img1.jpg"],
        ["beverages", "Sparkling Mineral Water", "Clean sparkling water served chilled.", 95.00, "src/images/beverages/img8.jpg"],
        ["desserts", "Molten Chocolate Lava Cake", "Warm chocolate cake with a rich flowing center.", 220.00, "src/images/desserts/img1.jpg"],
        ["desserts", "Classic New York Cheesecake", "Creamy cheesecake with a classic crumb crust.", 250.00, "src/images/desserts/img10.jpg"],
        ["desserts", "Creme Brulee", "Silky custard with a crisp caramelized top.", 240.00, "src/images/desserts/img9.jpg"],
        ["desserts", "Apple Tarte Tatin", "Caramelized apple tart with buttery pastry.", 230.00, "src/images/desserts/img8.jpg"],
        ["desserts", "Classic Tiramisu", "Coffee-soaked dessert layered with cream.", 260.00, "src/images/desserts/img6.jpg"],
        ["desserts", "Lemon Meringue Pie", "Bright lemon filling with toasted meringue.", 210.00, "src/images/desserts/img2.jpg"],
        ["desserts", "Strawberry Shortcake", "Soft cake layered with strawberries and cream.", 230.00, "src/images/desserts/img7.jpg"],
        ["desserts", "Decadent Chocolate Mousse", "Light, rich chocolate mousse.", 180.00, "src/images/desserts/img4.jpg"],
        ["desserts", "Profiteroles", "Cream-filled pastries with chocolate sauce.", 200.00, "src/images/desserts/img5.jpg"],
        ["desserts", "Warm Sticky Toffee Pudding", "Soft date pudding with warm toffee sauce.", 220.00, "src/images/desserts/img3.jpg"]
    ];

    $stmt = $conn->prepare("INSERT INTO menu_items (category, name, description, price, image_src, is_available) VALUES (?, ?, ?, ?, ?, ?)");
    if (!$stmt) {
        stopWithDatabaseError("Could not seed menu items", $conn->error);
    }

    foreach ($items as $item) {
        [$category, $name, $description, $price, $imageSrc] = $item;
        $isAvailable = 1;
        $stmt->bind_param("sssdsi", $category, $name, $description, $price, $imageSrc, $isAvailable);
        if (!$stmt->execute()) {
            stopWithDatabaseError("Could not seed menu items", $stmt->error);
        }
    }

    $stmt->close();
}

seedDefaultAdmin($conn);
seedDefaultMenuCategories($conn);
seedDefaultMenuItems($conn);
?>

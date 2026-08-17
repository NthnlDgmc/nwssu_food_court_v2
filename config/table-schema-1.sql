-- =====================================================
-- A Web-Based Food Court Ordering System for Northwest Samar State University (NwSSU Main)
-- Complete Database Schema
-- =====================================================

CREATE TABLE admins (
    admin_id INT AUTO_INCREMENT PRIMARY KEY,
    profile_image VARCHAR(255) NULL,
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    contact_number VARCHAR(20) NOT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE stall_owners (
    owner_id INT AUTO_INCREMENT PRIMARY KEY,
    profile_image VARCHAR(255) NULL,
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    contact_number VARCHAR(20) NOT NULL,
    email VARCHAR(100) NULL,
    password VARCHAR(255) NOT NULL,
    delivery_fee DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);


CREATE TABLE delivery_staff (
    staff_id INT AUTO_INCREMENT PRIMARY KEY,
    profile_image VARCHAR(255) NULL,
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    contact_number VARCHAR(20) NOT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);


CREATE TABLE customers (
    customer_id INT AUTO_INCREMENT PRIMARY KEY,
    customer_type ENUM('student', 'faculty', 'staff', 'guest') DEFAULT 'student',
    profile_image VARCHAR(255) NULL,
    id_number VARCHAR(50) NULL,
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    contact_number VARCHAR(20) NOT NULL,
    email VARCHAR(100) NULL,
    password VARCHAR(255) NOT NULL,
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);


CREATE TABLE password_resets (
    reset_id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(100) NOT NULL,
    user_type ENUM('admin', 'stall_owner', 'delivery_staff', 'customer') NOT NULL,
    code VARCHAR(6) NOT NULL,
    expires_at DATETIME NOT NULL,
    used TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_email_code (email, code)
);

CREATE TABLE email_verifications (
    verification_id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(100) NOT NULL,
    code VARCHAR(6) NOT NULL,
    expires_at DATETIME NOT NULL,
    used TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_email_code (email, code)
);


CREATE TABLE stalls (
    stall_id INT AUTO_INCREMENT PRIMARY KEY,
    stall_name VARCHAR(100) NOT NULL,
    status ENUM('open', 'closed') DEFAULT 'open',
    owner_id INT NULL,
    staff_id INT NULL,
    opens_at TIME NULL,
    closes_at TIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);


CREATE TABLE categories (
    category_id INT AUTO_INCREMENT PRIMARY KEY,
    category_name VARCHAR(50) NOT NULL UNIQUE,
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);


CREATE TABLE menu_items (
    menu_item_id INT AUTO_INCREMENT PRIMARY KEY,
    stall_id INT NOT NULL,
    owner_id INT NOT NULL,
    category_id INT NOT NULL,
    item_name VARCHAR(100) NOT NULL,
    price DECIMAL(10, 2) NOT NULL,
    image VARCHAR(255) NULL,
    status ENUM('available', 'unavailable') DEFAULT 'available',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (stall_id) REFERENCES stalls(stall_id) ON DELETE CASCADE,
    FOREIGN KEY (owner_id) REFERENCES stall_owners(owner_id) ON DELETE CASCADE,
    FOREIGN KEY (category_id) REFERENCES categories(category_id) ON DELETE RESTRICT
);

CREATE TABLE favorites (
    favorite_id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL,
    menu_item_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_favorite (customer_id, menu_item_id),
    FOREIGN KEY (customer_id) REFERENCES customers(customer_id) ON DELETE CASCADE,
    FOREIGN KEY (menu_item_id) REFERENCES menu_items(menu_item_id) ON DELETE CASCADE
);

CREATE TABLE carts (
    cart_id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL,
    menu_item_id INT NOT NULL,
    stall_id INT NOT NULL,
    quantity INT NOT NULL DEFAULT 1,
    note TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES customers(customer_id) ON DELETE CASCADE,
    FOREIGN KEY (menu_item_id) REFERENCES menu_items(menu_item_id) ON DELETE CASCADE,
    FOREIGN KEY (stall_id) REFERENCES stalls(stall_id) ON DELETE CASCADE,
    UNIQUE KEY unique_cart_item (customer_id, menu_item_id)
);


-- NOTE: This table merges the two conflicting `orders` definitions that were
-- pasted (one with the 9-status flow + customer_confirmed, one with owner_id +
-- cancel_reason/cancelled_at). The actual PHP code across the system
-- (orders.php, deliveries.php, stall-dashboard.php, order.php) requires BOTH
-- sets of columns together, so they are combined here into a single table.
CREATE TABLE orders (
    order_id VARCHAR(20) PRIMARY KEY,
    customer_id INT NOT NULL,
    stall_id INT NOT NULL,
    owner_id INT NULL,
    staff_id INT NULL,
    order_type ENUM('pickup', 'delivery') NOT NULL DEFAULT 'delivery',
    status ENUM(
        'pending',
        'preparing',
        'ready_for_pickup',
        'ready_for_dispatch',
        'collected',
        'out_for_delivery',
        'delivered',
        'completed',
        'cancelled'
    ) DEFAULT 'pending',
    payment_method ENUM('cash', 'gcash', 'paymaya') NOT NULL DEFAULT 'cash',
    payment_status ENUM('unpaid', 'paid') NOT NULL DEFAULT 'unpaid',
    total_amount DECIMAL(10, 2) NOT NULL,
    total_delivery_fee DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    grand_total DECIMAL(10, 2) NOT NULL,
    drop_off_location VARCHAR(255) NULL,
    note TEXT NULL,
    cancel_reason VARCHAR(255) NULL,
    cancelled_at TIMESTAMP NULL,
    delivery_proof_image VARCHAR(255) NULL,
    proof_captured_at TIMESTAMP NULL,
    customer_confirmed ENUM('pending', 'confirmed', 'issue') DEFAULT 'pending',
    customer_confirmed_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES customers(customer_id) ON DELETE CASCADE,
    FOREIGN KEY (stall_id) REFERENCES stalls(stall_id) ON DELETE RESTRICT,
    FOREIGN KEY (owner_id) REFERENCES stall_owners(owner_id) ON DELETE SET NULL,
    FOREIGN KEY (staff_id) REFERENCES delivery_staff(staff_id) ON DELETE SET NULL
);

CREATE TABLE order_items (
    order_item_id INT AUTO_INCREMENT PRIMARY KEY,
    order_id VARCHAR(20) NOT NULL,
    menu_item_id INT NULL,
    item_name VARCHAR(100) NOT NULL,
    unit_price DECIMAL(10, 2) NOT NULL,
    quantity INT NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (order_id) REFERENCES orders(order_id) ON DELETE CASCADE,
    FOREIGN KEY (menu_item_id) REFERENCES menu_items(menu_item_id) ON DELETE SET NULL
);


CREATE TABLE notifications (
    notification_id INT AUTO_INCREMENT PRIMARY KEY,
    user_type ENUM('admin', 'stall_owner', 'delivery_staff', 'customer') NOT NULL,
    user_id INT NOT NULL,
    title VARCHAR(150) NOT NULL,
    message VARCHAR(255) NOT NULL,
    link VARCHAR(255) NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user (user_type, user_id, is_read)
);


CREATE TABLE push_subscriptions (
    subscription_id INT AUTO_INCREMENT PRIMARY KEY,
    user_type ENUM('admin', 'stall_owner', 'delivery_staff', 'customer') NOT NULL,
    user_id INT NOT NULL,
    endpoint TEXT NOT NULL,
    p256dh_key VARCHAR(255) NOT NULL,
    auth_key VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user (user_type, user_id)
);


CREATE TABLE payment_transactions (
    transaction_id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL,
    order_ids VARCHAR(255) NULL,
    checkout_data MEDIUMTEXT NOT NULL,
    paymongo_source_id VARCHAR(100) NULL,
    paymongo_payment_id VARCHAR(100) NULL,
    payment_method ENUM('gcash', 'paymaya') NOT NULL,
    amount DECIMAL(10, 2) NOT NULL,
    status ENUM('pending', 'paid', 'failed') NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_source (paymongo_source_id),
    FOREIGN KEY (customer_id) REFERENCES customers(customer_id) ON DELETE CASCADE
);
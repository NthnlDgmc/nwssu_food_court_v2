-- =====================================================
-- UPDATED: orders table
--
-- Changes from original:
-- 1. status: 'delivered' -> 'completed', to match frontend
--    STATUS_META keys directly (no mapping layer needed between
--    DB and JS).
-- 2. Added cancel_reason + cancelled_at, to store the reason a
--    customer selects in the cancel modal (predefined options or
--    custom "other" text).
-- 3. Added owner_id as a snapshot of who actually owned/ran the
--    stall at the time this order was placed. stall_id alone can
--    drift in meaning if the admin reassigns that stall to a
--    different owner later -- owner_id preserves accurate history,
--    same reasoning as menu_items.owner_id.
-- =====================================================

CREATE TABLE orders (
    order_id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL,
    stall_id INT NOT NULL,
    owner_id INT NULL,
    staff_id INT NULL,
    order_type ENUM('pickup', 'delivery') NOT NULL DEFAULT 'delivery',
    status ENUM(
        'pending',
        'preparing',
        'ready_for_pickup',
        'picked_up',
        'out_for_delivery',
        'completed',
        'cancelled'
    ) DEFAULT 'pending',
    payment_method ENUM('cash', 'gcash', 'paymaya') NOT NULL DEFAULT 'cash',
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
    FOREIGN KEY (customer_id) REFERENCES customers(customer_id) ON DELETE RESTRICT,
    FOREIGN KEY (stall_id) REFERENCES stalls(stall_id) ON DELETE RESTRICT,
    FOREIGN KEY (owner_id) REFERENCES stall_owners(owner_id) ON DELETE SET NULL,
    FOREIGN KEY (staff_id) REFERENCES delivery_staff(staff_id) ON DELETE SET NULL
);

-- =====================================================
-- SUGGESTED (not yet confirmed with you): order_items
-- Without this, there is no way to know which specific menu items,
-- quantities, or prices-at-time-of-order belong to a given order --
-- the mockup data (order.items array) has no home in the schema
-- otherwise. price_at_order is a snapshot, same reasoning as
-- total_delivery_fee: menu_items.price can change later, but past
-- orders must keep showing what the customer actually paid.
-- =====================================================
CREATE TABLE order_items (
    order_item_id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    menu_item_id INT NOT NULL,
    item_name_snapshot VARCHAR(100) NOT NULL,
    price_at_order DECIMAL(10, 2) NOT NULL,
    quantity INT NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (order_id) REFERENCES orders(order_id) ON DELETE CASCADE,
    FOREIGN KEY (menu_item_id) REFERENCES menu_items(menu_item_id) ON DELETE RESTRICT
);
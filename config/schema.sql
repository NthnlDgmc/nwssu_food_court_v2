INSERT INTO
    admin (
        profile_image,
        first_name,
        last_name,
        contact_number,
        email,
        password
    )
VALUES
    (
        NULL,
        'Maloi',
        'Ricalde',
        '09123456789',
        'admin@gmail.com',
        'Password123'
    );

INSERT INTO
    stalls (
        stall_number,
        profile_image,
        first_name,
        last_name,
        contact_number,
        email,
        password,
        delivery_fee,
        status
    )
VALUES
    (
        'Stall 1',
        NULL,
        'Sheryl',
        'Caibog',
        '09234567890',
        'caibog@gmail.com',
        'Password123',
        10.00,
        'open'
    ),
    (
        'Stall 2',
        NULL,
        'Rina',
        'Baga',
        '09987654321',
        'baga@gmail.com',
        'Password123',
        10.00,
        'open'
    );

INSERT INTO
    delivery_staff (
        profile_image,
        first_name,
        last_name,
        contact_number,
        email,
        password,
        status
    )
VALUES
    (
        NULL,
        'Jenuel',
        'Castillo',
        '09345678901',
        'castillo@gmail.com',
        'Password123',
        'available'
    ),
    (
        NULL,
        'Jio',
        'Canaman',
        '09456789012',
        'canaman@gmail.com',
        'Password123',
        'available'
    );

INSERT INTO
    customers (
        customer_type,
        profile_image,
        id_number,
        first_name,
        last_name,
        contact_number,
        email,
        password,
        status
    )
VALUES
    (
        'student',
        NULL,
        '23-02140',
        'Nathaniel',
        'Dagamac',
        '09123456789',
        'dagamac@gmail.com',
        'Password123',
        'active'
    ),
    (
        'faculty',
        NULL,
        'FAC-2024-001',
        'Arnelyn',
        'Heleran',
        '09234567890',
        'heleran@gmail.com',
        'Password123',
        'active'
    );
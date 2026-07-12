<?php
// Permanent Super Admin Configuration
// This file stores the super admin credentials outside the database
// so they survive database deletion

return [
    'super_admin' => [
        'username' => 'superadmin',
        'password' => 'admin123', // Change this to your preferred password
        'email' => 'roycepigao@gmail.com',
        'department' => 'IT Department',
        'status' => 'active',
        'created_at' => '2024-01-01 00:00:00'
    ]
];
?>
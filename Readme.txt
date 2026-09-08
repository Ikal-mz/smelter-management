Struktur file

smelter-management/
│
├── config/
│   └── database.php
│
├── auth/
│   ├── login.php
│   ├── register.php
│   └── logout.php
│
├── admin/
│   ├── dashboard.php
│   ├── users.php
│   ├── teams.php
│   └── access-requests.php
│
├── spv/
│   ├── dashboard.php
│   ├── foremen.php
│   ├── access-requests.php
│   └── my-access.php
│
├── foreman/
│   ├── dashboard.php
│   ├── my-access.php
│   └── access-requests.php
│
├── api/
│   ├── get-smelters.php
│   └── get-teams.php
│
├── middleware/
│   ├── auth.php
│   ├── admin.php
│   ├── spv.php
│   └── foreman.php
│
├── helpers/
│   └── auth.php
│
├── assets/
│   ├── css/
│   │   └── style.css
│   └── js/
│       └── app.js
│
└── index.php




================
buat akun admin
    Buat file sementara:

create-admin.php

di folder utama project:

Kemudian buka:

http://localhost/smelter-management/create-admin.php

Jika berhasil, akan muncul:

Admin berhasil dibuat.

Email: admin@smelter.local
Password: Admin@12345

Setelah berhasil, hapus create-admin.php.

Password tersebut hanya untuk akun Admin awal. Setelah login nanti sebaiknya kita tambahkan fitur ubah password.